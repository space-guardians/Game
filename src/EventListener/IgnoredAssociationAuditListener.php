<?php

declare(strict_types=1);

namespace App\EventListener;

use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Auditing\Attribute\Ignore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Applique #[Ignore] aux collections : l'auditeur enregistre un « ajout à une relation » sur l'entité inverse
 * (ex. la galaxie) à chaque objet rattaché (ses 1 000 systèmes générés), sans consulter #[Ignore]. Ces entrées
 * sont écartées avant leur enregistrement, qui écoute le même événement en toute dernière priorité.
 */
#[AsEventListener(event: LifecycleEvent::class)]
final class IgnoredAssociationAuditListener
{
    public function __invoke(LifecycleEvent $event): void
    {
        $payload = $event->getPayload();
        if (!\in_array($payload['type'], [TransactionType::ASSOCIATE, TransactionType::DISSOCIATE], true)) {
            return;
        }

        $diffs = \is_string($payload['diffs']) ? json_decode($payload['diffs'], true, 512, \JSON_THROW_ON_ERROR) : $payload['diffs'];
        $class = $diffs['source']['class'] ?? null;
        $field = $diffs['source']['field'] ?? null;
        if (\is_string($class) && \is_string($field) && $this->isIgnored($class, $field)) {
            $event->stopPropagation();
        }
    }

    private function isIgnored(string $class, string $field): bool
    {
        if (!property_exists($class, $field)) {
            return false;
        }

        return [] !== new \ReflectionProperty($class, $field)->getAttributes(Ignore::class);
    }
}
