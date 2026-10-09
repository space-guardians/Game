<?php

declare(strict_types=1);

namespace App\Model\Universe;

/** Emplacements de colonisation d'un empire (§4.1) : colonies fondées, maximum permis par l'astrophysique */
final readonly class ColonySlots
{
    public function __construct(
        public int $colonies,
        public int $max,
        public int $astrophysicsLevel,
        /** Niveau d'astrophysique qui ouvrirait l'emplacement suivant */
        public int $nextLevel,
    ) {}

    public function free(): int
    {
        return max(0, $this->max - $this->colonies);
    }

    /** Raison d'un refus, quand l'empire voudrait fonder plus de colonies qu'il n'a d'emplacements libres */
    public function refusal(int $wanted = 1): ?string
    {
        if ($wanted <= $this->free()) {
            return null;
        }

        return \sprintf(
            'Limite de colonies atteinte : %d sur %d permise(s) par l’astrophysique niveau %d. Recherchez le niveau %d pour un emplacement de plus.',
            $this->colonies,
            $this->max,
            $this->astrophysicsLevel,
            $this->nextLevel,
        );
    }
}
