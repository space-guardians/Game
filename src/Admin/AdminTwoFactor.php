<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;

/**
 * Activation et réinitialisation de la double authentification (TOTP) des comptes d'administration.
 */
final readonly class AdminTwoFactor
{
    public function __construct(
        private TotpAuthenticatorInterface $totpAuthenticator,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Prépare l'activation : crée le secret s'il n'existe pas encore et renvoie de quoi l'enregistrer
     * dans une application d'authentification.
     *
     * @return array{secret: string, qrCode: string} qrCode : image SVG en URI data
     */
    public function prepareEnrollment(AdminUser $admin): array
    {
        if (!$admin->hasPendingTotpSecret()) {
            $admin->startTotpEnrollment($this->totpAuthenticator->generateSecret());
            $this->entityManager->flush();
        }

        $configuration = $admin->getTotpAuthenticationConfiguration();
        \assert(null !== $configuration);
        $qrCode = new Builder(writer: new SvgWriter(), data: $this->totpAuthenticator->getQRContent($admin), size: 240, margin: 8);

        return ['secret' => $configuration->getSecret(), 'qrCode' => $qrCode->build()->getDataUri()];
    }

    /** Termine l'activation si le code fourni par l'application est valide */
    public function confirmEnrollment(AdminUser $admin, string $code): bool
    {
        if (!$admin->hasPendingTotpSecret() || !$this->totpAuthenticator->checkCode($admin, trim($code))) {
            return false;
        }

        $admin->confirmTotp();
        $this->entityManager->flush();

        return true;
    }

    public function reset(AdminUser $admin): void
    {
        $admin->resetTwoFactor();
        $this->entityManager->flush();
    }
}
