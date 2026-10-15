<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Entity\Empire;
use App\Entity\Message;
use App\Entity\MessageReport;
use App\Enum\Moderation\ReportSource;
use App\Enum\Moderation\ReportStatus;
use App\Exception\Chat\ChatRefused;
use App\Exception\Moderation\ReportRefused;
use App\Model\Chat\ChatMessage;
use App\Repository\EmpireRepository;
use App\Service\Chat\Chat;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Signalements de messages à la modération (§4.13). Un joueur signale un message reçu, du chat ou de la messagerie ; le
 * message et son contexte sont recopiés au moment du signalement : pour le chat, non persistant, les derniers messages
 * du tampon du canal (§4.12.1). La modération les traite dans le panneau, sanctions comprises (fiche joueur, #78).
 */
final readonly class MessageReports
{
    /** Messages de contexte recopiés, message signalé compris */
    public const int CONTEXT = 10;

    public function __construct(
        private Chat $chat,
        private EmpireRepository $empires,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** @throws ReportRefused */
    public function reportChat(Empire $reporter, string $channel, string $messageId, string $reason): MessageReport
    {
        try {
            $recent = $this->chat->recent($reporter, $channel);
        } catch (ChatRefused $refusal) {
            throw new ReportRefused($refusal->getMessage());
        }
        $index = null;
        foreach ($recent as $position => $message) {
            if ($message->id === $messageId) {
                $index = $position;
            }
        }
        if (null === $index) {
            throw new ReportRefused('Ce message n’est plus dans le fil récent du chat : il ne peut plus être signalé.');
        }
        $reported = $recent[$index];
        $author = $this->empires->find($reported->authorId) ?? throw new ReportRefused('L’auteur de ce message n’existe plus.');
        $excerpt = array_map(static fn(ChatMessage $line): array => ['author' => $line->author, 'body' => $line->body, 'sentAt' => $line->sentAt->format('d/m H:i')], \array_slice($recent, max(0, $index - self::CONTEXT + 1), min(self::CONTEXT, $index + 1)));

        return $this->store($reporter, $author, ReportSource::Chat, $reported->body, $excerpt, $reason);
    }

    /** @throws ReportRefused */
    public function reportMessage(Empire $reporter, Message $message, string $reason): MessageReport
    {
        $place = $message->getConversation()->participantOf($reporter);
        $author = $message->getAuthor();
        if (null === $place || !$place->sees($message)) {
            throw new ReportRefused('Ce message ne vous a pas été adressé.');
        }
        if (null === $author) {
            throw new ReportRefused('Un message du jeu ne se signale pas.');
        }
        $visible = array_values(array_filter($message->getConversation()->getMessages()->toArray(), static fn(Message $candidate): bool => $place->sees($candidate) && $candidate->getSentAt() <= $message->getSentAt()));
        $excerpt = array_map(static fn(Message $line): array => ['author' => $line->getAuthor()?->getName() ?? 'Jeu', 'body' => $line->getBody(), 'sentAt' => $line->getSentAt()->format('d/m H:i')], \array_slice($visible, -self::CONTEXT));

        return $this->store($reporter, $author, ReportSource::Messaging, $message->getBody(), $excerpt, $reason);
    }

    public function handle(MessageReport $report, ReportStatus $status, string $moderator): void
    {
        $report->handle($status, $moderator, $this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * @param list<array{author: string, body: string, sentAt: string}> $excerpt
     *
     * @throws ReportRefused
     */
    private function store(Empire $reporter, Empire $author, ReportSource $source, string $body, array $excerpt, string $reason): MessageReport
    {
        if ($author === $reporter) {
            throw new ReportRefused('Vous ne pouvez pas signaler votre propre message.');
        }
        if ('' === trim($reason)) {
            throw new ReportRefused('Indiquez le motif du signalement.');
        }
        $report = new MessageReport($reporter, $author, $source, $body, $excerpt, $reason, $this->clock->now());
        $this->entityManager->persist($report);
        $this->entityManager->flush();

        return $report;
    }
}
