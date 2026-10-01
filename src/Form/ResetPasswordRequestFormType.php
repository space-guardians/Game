<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractType<array{email: string}>
 */
final class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Adresse e-mail du compte',
            'attr' => ['placeholder' => 'vous@exemple.fr', 'autocomplete' => 'email'],
            'constraints' => [
                new Assert\NotBlank(message: 'Saisissez votre adresse e-mail.'),
                new Assert\Email(message: 'Cette adresse e-mail n\'est pas valide.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Libellés déjà rédigés en français : pas de traduction
        $resolver->setDefaults(['translation_domain' => false]);
    }
}
