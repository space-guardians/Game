<?php

declare(strict_types=1);

namespace App\Service\Alliance;

use App\Entity\Alliance;
use App\Entity\AllianceInvitation;
use App\Entity\AllianceMember;
use App\Entity\Empire;
use App\Enum\Alliance\AllianceRole;
use App\Exception\Alliance\InvalidAllianceAction;
use App\Repository\AllianceMemberRepository;
use App\Repository\AllianceRepository;
use App\Repository\EmpireRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Création et gestion des alliances (§4.10) : fondation, invitations (par le fondateur ou un diplomate), acceptation
 * ou refus, rôles, exclusion, départ, transmission du rôle de fondateur, dissolution. Un empire appartient au plus à
 * une alliance ; le fondateur ne part qu'en transmettant son rôle, ou en dissolvant l'alliance.
 */
final readonly class Alliances
{
    public function __construct(
        private AllianceRepository $alliances,
        private AllianceMemberRepository $members,
        private EmpireRepository $empires,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {}

    /** @throws InvalidAllianceAction */
    public function found(Empire $founder, string $name, string $tag, ?string $description = null): Alliance
    {
        $this->assertFree($founder);
        $name = (string) preg_replace('/\s+/u', ' ', trim($name));
        $tag = mb_strtoupper(trim($tag));
        if (mb_strlen($name) < Alliance::NAME_MIN_LENGTH || mb_strlen($name) > Alliance::NAME_MAX_LENGTH) {
            throw new InvalidAllianceAction(\sprintf('Le nom compte de %d à %d caractères.', Alliance::NAME_MIN_LENGTH, Alliance::NAME_MAX_LENGTH));
        }
        if (1 !== preg_match('/^[\p{Lu}\d]{' . Alliance::TAG_MIN_LENGTH . ',' . Alliance::TAG_MAX_LENGTH . '}$/u', $tag)) {
            throw new InvalidAllianceAction(\sprintf('Le tag compte de %d à %d lettres ou chiffres.', Alliance::TAG_MIN_LENGTH, Alliance::TAG_MAX_LENGTH));
        }
        if (null !== $description && mb_strlen($description) > Alliance::DESCRIPTION_MAX_LENGTH) {
            throw new InvalidAllianceAction(\sprintf('La description compte au plus %d caractères.', Alliance::DESCRIPTION_MAX_LENGTH));
        }
        if ($this->alliances->isTaken($name, $tag)) {
            throw new InvalidAllianceAction('Ce nom ou ce tag est déjà pris.');
        }

        $now = $this->clock->now();
        $alliance = new Alliance($name, $tag, $now);
        $alliance->setDescription($description);
        $alliance->enrol($founder, AllianceRole::Founder, $now);
        $this->entityManager->persist($alliance);
        $this->dropInvitations($founder);
        $this->entityManager->flush();

        return $alliance;
    }

    /** @throws InvalidAllianceAction */
    public function invite(Empire $by, string $name): AllianceInvitation
    {
        $recruiter = $this->membership($by);
        if (!$recruiter->getRole()->canRecruit()) {
            throw new InvalidAllianceAction('Seuls le fondateur et les diplomates invitent de nouveaux membres.');
        }
        $empire = $this->empires->findOneByName($name) ?? throw new InvalidAllianceAction(\sprintf('Aucun joueur ne s’appelle « %s ».', trim($name)));
        if (null !== $this->members->findOneByEmpire($empire)) {
            throw new InvalidAllianceAction(\sprintf('%s appartient déjà à une alliance.', $empire->getName()));
        }
        $alliance = $recruiter->getAlliance();
        foreach ($this->alliances->findInvitationsOf($alliance) as $pending) {
            if ($pending->getEmpire() === $empire) {
                throw new InvalidAllianceAction(\sprintf('%s est déjà invité.', $empire->getName()));
            }
        }

        $invitation = new AllianceInvitation($alliance, $empire, $by, $this->clock->now());
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();

        return $invitation;
    }

    /** @throws InvalidAllianceAction */
    public function accept(AllianceInvitation $invitation, Empire $empire): AllianceMember
    {
        $this->assertInvited($invitation, $empire);
        $this->assertFree($empire);
        $member = $invitation->getAlliance()->enrol($empire, AllianceRole::Member, $this->clock->now());
        // Membre d'une alliance : ses autres invitations n'ont plus d'objet
        $this->dropInvitations($empire);
        $this->entityManager->flush();

        return $member;
    }

    /** @throws InvalidAllianceAction */
    public function decline(AllianceInvitation $invitation, Empire $empire): void
    {
        $this->assertInvited($invitation, $empire);
        $this->entityManager->remove($invitation);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    public function setRole(Empire $by, AllianceMember $member, AllianceRole $role): void
    {
        $this->assertFounderOver($by, $member);
        if (AllianceRole::Founder === $role) {
            throw new InvalidAllianceAction('Pour nommer un autre fondateur, transmettez votre rôle.');
        }
        $member->setRole($role);
        $this->entityManager->flush();
    }

    /** Le fondateur transmet son rôle à un autre membre et devient diplomate */
    public function transferLeadership(Empire $by, AllianceMember $member): void
    {
        $founder = $this->assertFounderOver($by, $member);
        $founder->setRole(AllianceRole::Diplomat);
        $member->setRole(AllianceRole::Founder);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    public function expel(Empire $by, AllianceMember $member): void
    {
        $this->assertFounderOver($by, $member);
        $member->getAlliance()->remove($member);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    public function leave(Empire $empire): void
    {
        $member = $this->membership($empire);
        $alliance = $member->getAlliance();
        if (AllianceRole::Founder === $member->getRole()) {
            if ($alliance->getMembers()->count() > 1) {
                throw new InvalidAllianceAction('Le fondateur transmet son rôle avant de partir, ou dissout l’alliance.');
            }
            $this->dissolve($empire);

            return;
        }
        $alliance->remove($member);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    public function dissolve(Empire $by): void
    {
        $founder = $this->membership($by);
        if (AllianceRole::Founder !== $founder->getRole()) {
            throw new InvalidAllianceAction('Seul le fondateur peut dissoudre l’alliance.');
        }
        $alliance = $founder->getAlliance();
        foreach ($this->alliances->findInvitationsOf($alliance) as $invitation) {
            $this->entityManager->remove($invitation);
        }
        $this->entityManager->remove($alliance);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    public function describe(Empire $by, ?string $description): void
    {
        $founder = $this->membership($by);
        if (AllianceRole::Founder !== $founder->getRole()) {
            throw new InvalidAllianceAction('Seul le fondateur modifie la description.');
        }
        if (null !== $description && mb_strlen($description) > Alliance::DESCRIPTION_MAX_LENGTH) {
            throw new InvalidAllianceAction(\sprintf('La description compte au plus %d caractères.', Alliance::DESCRIPTION_MAX_LENGTH));
        }
        $founder->getAlliance()->setDescription($description);
        $this->entityManager->flush();
    }

    /**
     * Paramètre de l'alliance : partager les rapports de combat de chaque membre avec toute l'alliance (§4.7).
     *
     * @throws InvalidAllianceAction
     */
    public function shareCombatReports(Empire $by, bool $share): void
    {
        $founder = $this->membership($by);
        if (AllianceRole::Founder !== $founder->getRole()) {
            throw new InvalidAllianceAction('Seul le fondateur règle les paramètres de l’alliance.');
        }
        $founder->getAlliance()->setShareCombatReports($share);
        $this->entityManager->flush();
    }

    /** @throws InvalidAllianceAction */
    private function membership(Empire $empire): AllianceMember
    {
        return $this->members->findOneByEmpire($empire) ?? throw new InvalidAllianceAction('Vous n’appartenez à aucune alliance.');
    }

    /** @throws InvalidAllianceAction */
    private function assertFree(Empire $empire): void
    {
        if (null !== $this->members->findOneByEmpire($empire)) {
            throw new InvalidAllianceAction('Vous appartenez déjà à une alliance : quittez-la d’abord.');
        }
    }

    /** @throws InvalidAllianceAction */
    private function assertInvited(AllianceInvitation $invitation, Empire $empire): void
    {
        if ($invitation->getEmpire() !== $empire) {
            throw new InvalidAllianceAction('Cette invitation ne vous est pas adressée.');
        }
    }

    /**
     * Le fondateur agit sur un autre membre de son alliance.
     *
     * @throws InvalidAllianceAction
     */
    private function assertFounderOver(Empire $by, AllianceMember $member): AllianceMember
    {
        $founder = $this->membership($by);
        if (AllianceRole::Founder !== $founder->getRole()) {
            throw new InvalidAllianceAction('Seul le fondateur gère les membres.');
        }
        if ($member->getAlliance() !== $founder->getAlliance() || $member === $founder) {
            throw new InvalidAllianceAction('Choisissez un autre membre de votre alliance.');
        }

        return $founder;
    }

    private function dropInvitations(Empire $empire): void
    {
        foreach ($this->alliances->findInvitationsFor($empire) as $invitation) {
            $this->entityManager->remove($invitation);
        }
    }
}
