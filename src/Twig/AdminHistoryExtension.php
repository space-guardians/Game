<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\Admin\AuditOrigin;
use Twig\Attribute\AsTwigFunction;

/**
 * Fonctions Twig de l'historique des modifications (panneau d'administration).
 */
final class AdminHistoryExtension
{
    /** Origine d'une entrée d'historique à partir du pare-feu enregistré */
    #[AsTwigFunction('audit_origin')]
    public function origin(?string $firewall): AuditOrigin
    {
        return AuditOrigin::fromFirewall($firewall);
    }
}
