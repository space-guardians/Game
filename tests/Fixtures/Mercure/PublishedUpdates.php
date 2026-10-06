<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Mercure;

use Symfony\Component\Mercure\Update;

/**
 * Publications Mercure faites pendant un test (éditeur du MockHub configuré pour l'environnement de test).
 */
final class PublishedUpdates
{
    /** @var list<Update> */
    private array $updates = [];

    public function __invoke(Update $update): string
    {
        $this->updates[] = $update;

        return 'urn:uuid:test-' . \count($this->updates);
    }

    /** @return list<Update> */
    public function all(): array
    {
        return $this->updates;
    }
}
