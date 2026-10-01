<?php

declare(strict_types=1);

// Chargé par PHPStan (phpstan-doctrine) pour connaître le mapping des entités.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

(new Dotenv())->bootEnv(__DIR__ . '/../.env');

$kernel = new Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
