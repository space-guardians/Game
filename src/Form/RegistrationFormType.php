<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Empire;
use App\Enum\Account\StartingOrientation;
use App\Model\Account\Registration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Registration>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Adresse e-mail',
                'attr' => ['placeholder' => 'vous@exemple.fr', 'autocomplete' => 'email'],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'attr' => ['placeholder' => '12 caractères minimum', 'autocomplete' => 'new-password'],
            ])
            ->add('empireName', TextType::class, [
                'label' => 'Nom de l’empire',
                'help' => \sprintf('De %d à %d caractères ; il sera visible de tous les joueurs.', Empire::NAME_MIN_LENGTH, Empire::NAME_MAX_LENGTH),
                'attr' => ['placeholder' => 'Ordre d’Orion', 'maxlength' => Empire::NAME_MAX_LENGTH, 'autocomplete' => 'off'],
            ])
            ->add('orientation', EnumType::class, [
                'class' => StartingOrientation::class,
                'label' => 'Orientation de départ',
                'help' => 'Elle ne décide que de l’emplacement de votre planète mère.',
                'expanded' => true,
                'choice_label' => static fn(StartingOrientation $orientation): string => $orientation->label(),
                'choice_attr' => static fn(StartingOrientation $orientation): array => ['data-description' => $orientation->description()],
            ])
            ->add('acceptRules', CheckboxType::class, [
                'label' => 'J’accepte les règles du jeu : un seul compte par joueur, pas d’automatisation.',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Libellés déjà rédigés en français : pas de traduction
        $resolver->setDefaults(['data_class' => Registration::class, 'translation_domain' => false]);
    }
}
