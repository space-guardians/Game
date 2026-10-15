<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Moderation;

use App\Enum\Moderation\ReportSource;
use App\Enum\Moderation\ReportStatus;
use App\Exception\Moderation\ReportRefused;
use App\Factory\EmpireFactory;
use App\Service\Chat\Chat;
use App\Service\Chat\ChatBuffer;
use App\Service\Messaging\Messaging;
use App\Service\Moderation\MessageReports;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Test\Factories;

/**
 * Signalements (§4.13) : message et contexte recopiés au signalement — tampon du chat, conversation de la messagerie.
 */
final class MessageReportsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use Factories;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clock = self::mockTime('2026-10-15 18:30:00');
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(ChatBuffer::class)->clear(Chat::PUBLIC_CHANNEL);
        parent::tearDown();
    }

    public function testAChatMessageIsReportedWithItsContextFromTheBuffer(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $chat = self::getContainer()->get(Chat::class);
        $chat->post($ana, Chat::PUBLIC_CHANNEL, 'Bonjour à tous');
        $insult = $chat->post($bob, Chat::PUBLIC_CHANNEL, 'Propos injurieux');

        $report = $this->reports()->reportChat($ana, Chat::PUBLIC_CHANNEL, $insult->id, 'Insulte');

        self::assertSame([$ana, $bob, ReportSource::Chat, 'Propos injurieux', 'Insulte', ReportStatus::Open], [$report->getReporter(), $report->getReported(), $report->getSource(), $report->getBody(), $report->getReason(), $report->getStatus()]);
        self::assertSame(['Ana', 'Bob'], array_column($report->getExcerpt(), 'author'));

        foreach ([
            'Vous ne pouvez pas signaler votre propre message.' => fn() => $this->reports()->reportChat($bob, Chat::PUBLIC_CHANNEL, $insult->id, 'Moi'),
            'Ce message n’est plus dans le fil récent du chat : il ne peut plus être signalé.' => fn() => $this->reports()->reportChat($ana, Chat::PUBLIC_CHANNEL, 'inconnu', 'Insulte'),
            'Indiquez le motif du signalement.' => fn() => $this->reports()->reportChat($ana, Chat::PUBLIC_CHANNEL, $insult->id, ' '),
        ] as $expected => $attempt) {
            try {
                $attempt();
                self::fail('Signalement accepté à tort.');
            } catch (ReportRefused $refusal) {
                self::assertSame($expected, $refusal->getMessage());
            }
        }
    }

    public function testAReceivedMessageIsReportedAndThenHandled(): void
    {
        $ana = EmpireFactory::createOne(['name' => 'Ana']);
        $bob = EmpireFactory::createOne(['name' => 'Bob']);
        $messaging = self::getContainer()->get(Messaging::class);
        $conversation = $messaging->start($ana, ['Bob'], null, 'Salut Bob');
        $this->clock->sleep(60);
        $message = $messaging->reply($conversation, $bob, 'Menace');

        $report = $this->reports()->reportMessage($ana, $message, 'Menaces');
        self::assertSame(ReportSource::Messaging, $report->getSource());
        self::assertSame(['Salut Bob', 'Menace'], array_column($report->getExcerpt(), 'body'));

        $this->reports()->handle($report, ReportStatus::Dismissed, 'moderateur@exemple.fr');
        self::assertSame([ReportStatus::Dismissed, 'moderateur@exemple.fr'], [$report->getStatus(), $report->getHandledBy()]);

        $this->expectExceptionObject(new ReportRefused('Ce message ne vous a pas été adressé.'));
        $this->reports()->reportMessage(EmpireFactory::createOne(), $message, 'Curieux');
    }

    private function reports(): MessageReports
    {
        return self::getContainer()->get(MessageReports::class);
    }
}
