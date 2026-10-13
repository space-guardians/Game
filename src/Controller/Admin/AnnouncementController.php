<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Conversation;
use App\Entity\Empire;
use App\Enum\Admin\AdminRole;
use App\Enum\Admin\AuditAction;
use App\Enum\Messaging\SystemAudience;
use App\Exception\Messaging\InvalidMessage;
use App\Repository\AllianceRepository;
use App\Repository\EmpireRepository;
use App\Service\Admin\AdminAudit;
use App\Service\Messaging\SystemMessenger;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Annonces (communication, §5.6.1) : message système à des joueurs désignés, à une alliance ou à tous les joueurs,
 * via `SystemMessenger` ; chaque envoi est noté au journal des actions. Routes : admin_announcement_index, _send.
 */
#[IsGranted(AdminRole::Moderator->value)]
#[AdminRoute(path: '/annonces', name: 'announcement')]
final class AnnouncementController extends AbstractController
{
    use SameOriginTrait;

    public function __construct(
        private readonly SystemMessenger $messenger,
        private readonly EmpireRepository $empires,
        private readonly AllianceRepository $alliances,
        private readonly AdminAudit $audit,
    ) {}

    #[AdminRoute(path: '/', name: 'index', options: ['methods' => ['GET']])]
    public function index(): Response
    {
        return $this->renderForm([]);
    }

    #[AdminRoute(path: '/envoyer', name: 'send', options: ['methods' => ['POST']])]
    public function send(Request $request): Response
    {
        $this->denyUnlessSameOrigin($request);
        $values = [
            'audience' => $request->request->getString('audience'),
            'recipients' => $request->request->getString('recipients'),
            'alliance' => $request->request->getString('alliance'),
            'subject' => $request->request->getString('subject'),
            'body' => $request->request->getString('body'),
        ];
        if (!$this->isCsrfTokenValid('admin_announcement_send', $request->request->getString('_token'))) {
            $this->addFlash('danger', 'La page a expiré : recommencez.');

            return $this->renderForm($values, 422);
        }

        try {
            $audience = SystemAudience::tryFrom($values['audience']) ?? throw new InvalidMessage('Choisissez les destinataires.');
            $subject = '' === trim($values['subject']) ? null : $values['subject'];
            [$conversation, $target] = match ($audience) {
                SystemAudience::All => [$this->messenger->toAll($subject, $values['body']), 'tous les joueurs'],
                SystemAudience::Alliance => $this->toAlliance($values['alliance'], $subject, $values['body']),
                SystemAudience::Empires => $this->toEmpires($values['recipients'], $subject, $values['body']),
            };
        } catch (InvalidMessage $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->renderForm($values, 422);
        }

        $this->audit->record(AuditAction::Announce, $conversation, [
            'destinataires' => [null, $target],
            'objet' => [null, $subject],
        ]);
        $this->addFlash('success', \sprintf('Annonce envoyée à %s.', $target));

        return $this->redirectToRoute('admin_announcement_index');
    }

    /**
     * @return array{0: Conversation, 1: string}
     *
     * @throws InvalidMessage
     */
    private function toAlliance(string $tag, ?string $subject, string $body): array
    {
        $alliance = $this->alliances->findOneBy(['tag' => mb_strtoupper(trim($tag))]) ?? throw new InvalidMessage(\sprintf('Aucune alliance au tag « %s ».', trim($tag)));

        return [$this->messenger->toAlliance($alliance, $subject, $body), (string) $alliance];
    }

    /**
     * @return array{0: Conversation, 1: string}
     *
     * @throws InvalidMessage
     */
    private function toEmpires(string $names, ?string $subject, string $body): array
    {
        $empires = [];
        foreach (array_filter(array_map('trim', explode(',', $names)), static fn(string $name): bool => '' !== $name) as $name) {
            $empires[] = $this->empires->findOneByName($name) ?? throw new InvalidMessage(\sprintf('Aucun empire nommé « %s ».', $name));
        }

        return [$this->messenger->toEmpires($empires, $subject, $body), implode(', ', array_map(static fn(Empire $empire): string => $empire->getName(), $empires))];
    }

    /** @param array<string, string> $values saisie à reproposer */
    private function renderForm(array $values, int $status = 200): Response
    {
        return $this->render('admin/announcement/index.html.twig', [
            'audiences' => SystemAudience::cases(),
            'values' => $values,
        ], new Response(status: $status));
    }
}
