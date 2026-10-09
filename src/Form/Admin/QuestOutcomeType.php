<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\QuestOutcome;
use App\Entity\QuestTemplate;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Issue d'une quête, saisie dans la fiche de son gabarit (panneau d'administration, §4.6.4).
 *
 * @extends AbstractType<QuestOutcome>
 */
final class QuestOutcomeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, ['label' => 'Libellé', 'help' => 'Choix proposé au joueur, ou nom du dénouement tiré au sort.'])
            ->add('text', TextareaType::class, ['label' => 'Dénouement'])
            ->add('weight', IntegerType::class, ['label' => 'Poids', 'help' => 'Quête automatique : chance relative de cette issue au tirage.'])
            ->add('metal', IntegerType::class, ['label' => 'Métal', 'help' => 'Gain (positif) ou perte (négatif) de cargaison.'])
            ->add('crystal', IntegerType::class, ['label' => 'Cristal'])
            ->add('deuterium', IntegerType::class, ['label' => 'Deutérium'])
            ->add('shipLossPercent', IntegerType::class, ['label' => 'Vaisseaux perdus (%)', 'help' => 'Part de chaque type de vaisseau, arrondie à l’unité inférieure.'])
            ->add('nextQuest', EntityType::class, [
                'label' => 'Quête suivante',
                'class' => QuestTemplate::class,
                'required' => false,
                'placeholder' => '— Aucune —',
                'help' => 'Déclenchée aussitôt si la flotte en remplit les conditions.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => QuestOutcome::class]);
    }
}
