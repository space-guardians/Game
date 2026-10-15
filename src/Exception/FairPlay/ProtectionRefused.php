<?php

declare(strict_types=1);

namespace App\Exception\FairPlay;

/** Mode vacances refusé (flotte en vol, attaque en route, durée minimale…) ; message montré au joueur */
final class ProtectionRefused extends \DomainException {}
