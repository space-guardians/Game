<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Entity\Empire;
use App\Entity\User;
use App\Enum\Account\StartingOrientation;
use App\Factory\EmpireFactory;
use App\Factory\UserFactory;
use App\Model\Universe\SpiralGalaxyShape;
use App\Repository\EmpireRepository;
use App\Repository\UserRepository;
use App\Service\Universe\GalaxyCreator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

final class RegistrationTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Comme un navigateur : la protection CSRF sans état vérifie l'origine de la requête
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
    }

    /** Une petite galaxie où placer les planètes mères */
    private function generateGalaxy(): void
    {
        self::getContainer()->get(GalaxyCreator::class)->create(1, 'Voie des Gardiens', 7, new SpiralGalaxyShape(), 40);
    }

    public function testRegistersLogsInAndSendsWelcomeEmail(): void
    {
        $this->generateGalaxy();
        $this->register('Nouveau.Gardien@Exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseRedirects('/');
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('nouveau.gardien@exemple.fr');
        self::assertInstanceOf(User::class, $user);
        self::assertNotSame(UserFactory::DEFAULT_PASSWORD, $user->getPassword());

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', 'nouveau.gardien@exemple.fr');
        self::assertEmailSubjectContains($email, 'Bienvenue parmi les Gardiens');

        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Planète mère');
        self::assertSelectorTextContains('.sg-topbar', 'Ordre d’Orion');
    }

    public function testFoundsEmpireOnHomePlanet(): void
    {
        $this->generateGalaxy();

        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD, empireName: '  Ordre   d’Orion ', orientation: 'producer');

        self::assertResponseRedirects('/');
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('gardien@exemple.fr');
        self::assertInstanceOf(User::class, $user);
        $empire = self::getContainer()->get(EmpireRepository::class)->findOneByUser($user);
        self::assertInstanceOf(Empire::class, $empire);
        self::assertSame('Ordre d’Orion', $empire->getName());
        self::assertSame(StartingOrientation::Producer, $empire->getOrientation());
        self::assertSame($empire, $empire->getHomePlanet()->getOwner());
        self::assertSame($empire->getHomePlanet(), $empire->getActivePlanet());
    }

    public function testRefusesTakenEmpireNameWhateverItsCase(): void
    {
        $this->generateGalaxy();
        EmpireFactory::createOne(['name' => 'Ordre d’Orion']);

        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD, empireName: 'ORDRE D’ORION');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'Un empire porte déjà ce nom');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('gardien@exemple.fr'));
        self::assertQueuedEmailCount(0);
    }

    public function testRejectsInvalidEmpireName(): void
    {
        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD, empireName: '<Ordre>');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'Lettres, chiffres, espaces');
    }

    public function testRequiresOrientation(): void
    {
        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD, orientation: null);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-choices', 'Choisissez une orientation de départ.');
    }

    public function testExplainsWhenNoPlanetIsFree(): void
    {
        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Aucune planète n’est disponible pour le moment');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('gardien@exemple.fr'));
    }

    public function testRefusesAlreadyRegisteredEmail(): void
    {
        UserFactory::createOne(['email' => 'gardien@exemple.fr']);

        $this->register('GARDIEN@exemple.fr', UserFactory::DEFAULT_PASSWORD);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'Un compte existe déjà avec cette adresse e-mail.');
        self::assertQueuedEmailCount(0);
    }

    public function testRefusesWeakPassword(): void
    {
        $this->register('gardien@exemple.fr', 'motdepasse12');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'trop facile à deviner');
    }

    public function testRequiresRulesAcceptance(): void
    {
        $this->register('gardien@exemple.fr', UserFactory::DEFAULT_PASSWORD, acceptRules: false);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.sg-field--error', 'Vous devez accepter les règles du jeu.');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('gardien@exemple.fr'));
    }

    private function register(
        string $email,
        string $password,
        bool $acceptRules = true,
        string $empireName = 'Ordre d’Orion',
        ?string $orientation = 'aggressive',
    ): void {
        $this->client->request('GET', '/inscription');
        $form = $this->client->getCrawler()->selectButton('Fonder mon empire')->form([
            'registration_form[email]' => $email,
            'registration_form[plainPassword]' => $password,
            'registration_form[empireName]' => $empireName,
        ]);
        if (null !== $orientation) {
            $choice = $form['registration_form[orientation]'];
            self::assertInstanceOf(ChoiceFormField::class, $choice);
            $choice->select($orientation);
        }
        if ($acceptRules) {
            $checkbox = $form['registration_form[acceptRules]'];
            self::assertInstanceOf(ChoiceFormField::class, $checkbox);
            $checkbox->tick();
        }
        $this->client->submit($form);
    }
}
