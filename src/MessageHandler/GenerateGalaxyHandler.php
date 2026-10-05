<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\GalaxyGeneration;
use App\Enum\Admin\AuditAction;
use App\Enum\Universe\GenerationStatus;
use App\Message\GenerateGalaxy;
use App\Service\Admin\AdminAudit;
use App\Service\Universe\GalaxyCreator;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Exécute une demande de génération : en cours → terminée (galaxie enregistrée, action journalisée) ou échec.
 * Une génération est déterministe : un échec n'est pas retenté, il est affiché dans le panneau.
 */
#[AsMessageHandler]
final readonly class GenerateGalaxyHandler
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private GalaxyCreator $creator,
        private AdminAudit $audit,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(GenerateGalaxy $message): void
    {
        $entityManager = $this->doctrine->getManagerForClass(GalaxyGeneration::class);
        \assert(null !== $entityManager);
        $generation = $entityManager->find(GalaxyGeneration::class, $message->generationId);
        if (!$generation instanceof GalaxyGeneration) {
            return;
        }
        // Message relivré alors que la génération était en cours : le worker s'est arrêté pendant le traitement
        // (mémoire, redémarrage). La galaxie n'a pas été enregistrée (une seule transaction) : on le signale.
        if (GenerationStatus::Running === $generation->getStatus()) {
            $generation->fail('La génération a été interrompue (arrêt du traitement en arrière-plan) : relancez-la.', $this->clock->now());
            $entityManager->flush();

            return;
        }
        // Message rejoué après une génération terminée : rien à faire
        if (GenerationStatus::Pending !== $generation->getStatus()) {
            return;
        }

        $generation->start($this->clock->now());
        $entityManager->flush();

        try {
            $created = $this->creator->create(null, $generation->getName(), (int) $generation->getSeed(), $generation->getShape(), $generation->getSystemCount());
        } catch (\Throwable $exception) {
            $this->logger->error('Échec de la génération de galaxie #{id}.', ['id' => $message->generationId, 'exception' => $exception]);
            $this->fail($message->generationId, $exception);

            return;
        }

        $generation->complete($created->galaxy, $created->planets, $this->clock->now());
        $entityManager->flush();
        $this->audit->record(AuditAction::Generate, $created->galaxy, [
            'seed' => [null, $generation->getSeed()],
            'systems' => [null, $created->systems],
            'planets' => [null, $created->planets],
            'shapeTemplate' => [null, $generation->getShapeTemplate()?->getName()],
            'shape' => [null, $generation->getShape()->toArray()],
        ], $generation->getRequestedBy());
    }

    /** Une erreur d'écriture ferme le gestionnaire d'entités : l'échec est enregistré avec un gestionnaire neuf */
    private function fail(int $generationId, \Throwable $exception): void
    {
        $this->doctrine->resetManager();
        $entityManager = $this->doctrine->getManagerForClass(GalaxyGeneration::class);
        \assert(null !== $entityManager);
        $generation = $entityManager->find(GalaxyGeneration::class, $generationId);
        \assert($generation instanceof GalaxyGeneration);

        $generation->fail($exception instanceof \InvalidArgumentException ? $exception->getMessage() : 'Erreur inattendue : ' . $exception->getMessage(), $this->clock->now());
        $entityManager->flush();
    }
}
