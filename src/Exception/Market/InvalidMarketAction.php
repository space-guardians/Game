<?php

declare(strict_types=1);

namespace App\Exception\Market;

/** Action refusée au marché (§4.15) : annonce invalide ou déjà prise, acheteur non autorisé… ; message montré au joueur */
final class InvalidMarketAction extends \DomainException {}
