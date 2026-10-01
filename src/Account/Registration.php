<?php

declare(strict_types=1);

namespace App\Account;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données saisies à l'inscription. Le nom de l'empire et l'orientation de départ arrivent avec l'empire (#16).
 */
final class Registration
{
    #[Assert\NotBlank(message: 'Saisissez votre adresse e-mail.')]
    #[Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.', mode: Assert\Email::VALIDATION_MODE_STRICT)]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank(message: 'Choisissez un mot de passe.')]
    #[Assert\Length(min: 12, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.')]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM, message: 'Ce mot de passe est trop facile à deviner : allongez-le ou variez les caractères.')]
    public string $plainPassword = '';

    #[Assert\IsTrue(message: 'Vous devez accepter les règles du jeu.')]
    public bool $acceptRules = false;
}
