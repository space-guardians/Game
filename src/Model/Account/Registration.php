<?php

declare(strict_types=1);

namespace App\Model\Account;

use App\Entity\Empire;
use App\Enum\Account\StartingOrientation;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données saisies à l'inscription : le compte, puis l'empire et son orientation de départ (§2.4, §4.1).
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

    #[Assert\NotBlank(message: 'Donnez un nom à votre empire.')]
    #[Assert\Length(
        min: Empire::NAME_MIN_LENGTH,
        max: Empire::NAME_MAX_LENGTH,
        minMessage: 'Le nom de l\'empire doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le nom de l\'empire ne doit pas dépasser {{ limit }} caractères.',
    )]
    #[Assert\Regex(
        pattern: '/^[\p{L}\p{N}](?:[\p{L}\p{N} \'’-]*[\p{L}\p{N}])?$/u',
        message: 'Lettres, chiffres, espaces, tirets et apostrophes uniquement, en commençant et finissant par une lettre ou un chiffre.',
    )]
    public string $empireName = '';

    #[Assert\NotNull(message: 'Choisissez une orientation de départ.')]
    public ?StartingOrientation $orientation = null;

    #[Assert\IsTrue(message: 'Vous devez accepter les règles du jeu.')]
    public bool $acceptRules = false;
}
