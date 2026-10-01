<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/**
 * Construit les e-mails liés au compte joueur.
 */
final readonly class AccountMailer
{
    public function __construct(
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
    ) {}

    public function welcome(User $user): TemplatedEmail
    {
        return $this->email($user)
            ->subject('Bienvenue parmi les Gardiens')
            ->htmlTemplate('emails/welcome.html.twig');
    }

    public function resetPassword(User $user, ResetPasswordToken $token): TemplatedEmail
    {
        return $this->email($user)
            ->subject('Réinitialisation de votre mot de passe')
            ->htmlTemplate('emails/reset_password.html.twig')
            ->context(['resetToken' => $token]);
    }

    private function email(User $user): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to(new Address($user->getEmail()));
    }
}
