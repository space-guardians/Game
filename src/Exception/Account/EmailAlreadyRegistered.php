<?php

declare(strict_types=1);

namespace App\Exception\Account;

final class EmailAlreadyRegistered extends \DomainException
{
    public function __construct(string $email)
    {
        parent::__construct(\sprintf('Un compte existe déjà pour « %s ».', $email));
    }
}
