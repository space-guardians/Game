<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Actions personnalisées du panneau (POST) : déclenchées depuis le panneau lui-même, protection contre les
 * requêtes intersites en plus du jeton CSRF.
 */
trait SameOriginTrait
{
    private function denyUnlessSameOrigin(Request $request): void
    {
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');
        if (null === $origin || parse_url($origin, \PHP_URL_HOST) !== $request->getHost()) {
            throw new AccessDeniedException('Action à déclencher depuis le panneau d’administration.');
        }
    }
}
