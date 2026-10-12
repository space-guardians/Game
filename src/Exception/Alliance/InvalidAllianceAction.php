<?php

declare(strict_types=1);

namespace App\Exception\Alliance;

/** Action d'alliance refusée : nom pris, droits insuffisants, empire déjà membre ailleurs… */
final class InvalidAllianceAction extends \DomainException {}
