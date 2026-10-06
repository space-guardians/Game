<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Update;

/**
 * Vérifie la configuration réelle du hub (secret, émetteur de confiance, audience) :
 * les autres tests remplacent le hub par un MockHub.
 */
final class MercureHubTest extends KernelTestCase
{
    private const string TOPIC = 'https://space-guardians.test/tests/mercure';

    public function testPublishesUpdate(): void
    {
        $id = $this->hub()->publish(new Update(self::TOPIC, '{"ok":true}'));

        self::assertStringStartsWith('urn:uuid:', $id);
    }

    public function testAcceptsSubscriberToken(): void
    {
        $hub = $this->hub();
        $token = $hub->getFactory()?->create([new Grant([Grant::ACTION_SUBSCRIBE], [self::TOPIC])]);
        self::assertIsString($token);

        // URL interne : l'URL publique (celle du navigateur) n'est pas joignable depuis le conteneur
        $response = HttpClient::create()->request('GET', (string) $_SERVER['MERCURE_URL'], [
            // Protocole 1.0 : « match » remplace le paramètre « topic » de la 0.x
            'query' => ['match' => self::TOPIC],
            'auth_bearer' => $token,
            'timeout' => 5,
        ]);

        // Lire les en-têtes suffit : le flux d'événements reste ouvert
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', $response->getHeaders()['content-type'][0]);
        $response->cancel();
    }

    private function hub(): HubInterface
    {
        return self::getContainer()->get('mercure.hub.default');
    }
}
