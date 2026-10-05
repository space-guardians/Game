<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\Account\EmailAlreadyRegistered;
use App\Form\RegistrationFormType;
use App\Model\Account\Registration;
use App\Service\Account\PlayerRegistration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function register(Request $request, PlayerRegistration $registration, Security $security): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $data = new Registration();
        $form = $this->createForm(RegistrationFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $registration->register($data);
                $security->login($user, 'form_login', 'main');
                $this->addFlash('success', 'Bienvenue, Gardien. Un e-mail de confirmation vous a été envoyé.');

                return $this->redirectToRoute('app_home');
            } catch (EmailAlreadyRegistered) {
                $form->get('email')->addError(new FormError('Un compte existe déjà avec cette adresse e-mail.'));
            }
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }
}
