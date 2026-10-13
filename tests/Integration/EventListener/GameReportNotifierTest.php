<?php

declare(strict_types=1);

namespace App\Tests\Integration\EventListener;

use App\Enum\Messaging\MessageCategory;
use App\Factory\EmpireFactory;
use App\Repository\ConversationRepository;
use App\Service\Messaging\GameReports;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * Rapports automatiques (§4.12.2) : délivrés avec la réponse d'une requête réussie, oubliés si elle a échoué.
 */
final class GameReportNotifierTest extends KernelTestCase
{
    use Factories;

    public function testReportsLeaveWithTheResponseAndAreDroppedOnFailure(): void
    {
        self::bootKernel();
        $empire = EmpireFactory::createOne();
        $reports = self::getContainer()->get(GameReports::class);
        $kernel = self::$kernel;
        \assert(null !== $kernel);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $count = static fn(): int => \count(self::getContainer()->get(ConversationRepository::class)->findPlacesOf($empire, category: MessageCategory::Transport));

        $reports->report($empire, MessageCategory::Transport, 'Perdu', 'Requête en échec.');
        $dispatcher->dispatch(new ExceptionEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException()), KernelEvents::EXCEPTION);
        $dispatcher->dispatch(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()), KernelEvents::RESPONSE);
        self::assertSame(0, $count());

        $reports->report($empire, MessageCategory::Transport, 'Livré', 'Requête réussie.', 'app_messaging');
        $dispatcher->dispatch(new ResponseEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST, new Response()), KernelEvents::RESPONSE);
        $places = self::getContainer()->get(ConversationRepository::class)->findPlacesOf($empire, category: MessageCategory::Transport);
        self::assertCount(1, $places);
        self::assertSame('Livré', $places[0]->getConversation()->getSubject());
        $message = $places[0]->getConversation()->getMessages()->first();
        self::assertNotFalse($message);
        self::assertSame('/messagerie', $message->getLink());
    }
}
