<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\FairPlay\ActivityAction;
use App\Exception\Account\EmailAlreadyRegistered;
use App\Exception\Account\EmpireNameTaken;
use App\Exception\Universe\NoFreePlanet;
use App\Form\RegistrationFormType;
use App\Model\Account\Registration;
use App\Service\Account\PlayerRegistration;
use App\Service\FairPlay\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function register(Request $request, PlayerRegistration $registration, Security $security, LoggerInterface $logger, ActivityLogger $activity, EntityManagerInterface $entityManager): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $data = new Registration();
        $form = $this->createForm(RegistrationFormType::class, $data);
        $form->handleRequest($request);

        // Comptes multiples (§4.13) : un nombre limité d'inscriptions par adresse
        $refusal = $form->isSubmitted() ? $activity->registrationRefusal() : null;
        if (null !== $refusal) {
            $form->addError(new FormError($refusal));
        }
        if (null === $refusal && $form->isSubmitted() && $form->isValid()) {
            try {
                $user = $registration->register($data);
                $activity->record($user, ActivityAction::Registration);
                $entityManager->flush();
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', 'Bienvenue, Gardien. Un e-mail de confirmation vous a été envoyé.');

                return $this->redirectToRoute('app_home');
            } catch (EmailAlreadyRegistered) {
                $form->get('email')->addError(new FormError('Un compte existe déjà avec cette adresse e-mail.'));
            } catch (EmpireNameTaken) {
                $form->get('empireName')->addError(new FormError('Un empire porte déjà ce nom : choisissez-en un autre.'));
            } catch (NoFreePlanet $exception) {
                $logger->critical('Inscription impossible : {message}', ['message' => $exception->getMessage()]);
                $form->addError(new FormError('Aucune planète n’est disponible pour le moment : les administrateurs ont été prévenus, réessayez plus tard.'));
            }
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }
}
