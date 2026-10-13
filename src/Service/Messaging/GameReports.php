<?php

declare(strict_types=1);

namespace App\Service\Messaging;

use App\Entity\Empire;
use App\Enum\Messaging\MessageCategory;
use App\Exception\Messaging\InvalidMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Rapports automatiques (§4.12.2) : le code de jeu les note pendant la résolution d'un événement ou d'une action, ils
 * sont délivrés comme messages système classés par catégorie une fois le traitement validé (GameReportNotifier),
 * jamais s'il a échoué. Le lien vers l'écran concerné se calcule à l'envoi (identifiants alors connus).
 */
final class GameReports implements ResetInterface
{
    /**
     * @var list<array{empires: list<Empire>, category: MessageCategory, subject: string, body: string, route: ?string, parameters: ?\Closure(): array<string, mixed>}>
     */
    private array $pending = [];

    public function __construct(
        private readonly SystemMessenger $messenger,
        private readonly UrlGeneratorInterface $urls,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param Empire|list<Empire>                    $empires
     * @param (\Closure(): array<string, mixed>)|null $parameters paramètres de la route, évalués à l'envoi
     */
    public function report(Empire|array $empires, MessageCategory $category, string $subject, string $body, ?string $route = null, ?\Closure $parameters = null): void
    {
        $empires = $empires instanceof Empire ? [$empires] : $empires;
        if ([] === $empires) {
            return;
        }
        $this->pending[] = ['empires' => $empires, 'category' => $category, 'subject' => $subject, 'body' => $body, 'route' => $route, 'parameters' => $parameters];
    }

    public function hasPending(): bool
    {
        return [] !== $this->pending;
    }

    public function send(): void
    {
        $reports = $this->pending;
        $this->pending = [];
        // Transaction annulée en cours de route : rien à délivrer
        if (!$this->entityManager->isOpen()) {
            return;
        }
        foreach ($reports as $report) {
            try {
                $link = null === $report['route'] ? null : $this->urls->generate($report['route'], null === $report['parameters'] ? [] : ($report['parameters'])());
                $this->messenger->toEmpires($report['empires'], mb_substr($report['subject'], 0, 120), $report['body'], $report['category'], $link);
            } catch (InvalidMessage $exception) {
                $this->logger->warning('Rapport automatique non délivré : {reason}', ['reason' => $exception->getMessage()]);
            }
        }
    }

    public function discard(): void
    {
        $this->pending = [];
    }

    public function reset(): void
    {
        $this->pending = [];
    }
}
