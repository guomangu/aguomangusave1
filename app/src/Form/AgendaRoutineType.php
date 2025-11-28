<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AgendaRoutineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre du créneau',
            ])
            ->add('startTime', TimeType::class, [
                'label' => 'Heure de début',
                'widget' => 'single_text',
            ])
            ->add('endTime', TimeType::class, [
                'label' => 'Heure de fin',
                'widget' => 'single_text',
            ])
            ->add('daysOfWeek', ChoiceType::class, [
                'label' => 'Jours de la semaine',
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    'Lundi' => 1,
                    'Mardi' => 2,
                    'Mercredi' => 3,
                    'Jeudi' => 4,
                    'Vendredi' => 5,
                    'Samedi' => 6,
                    'Dimanche' => 7,
                ],
                'help' => 'Sélectionne tous les jours où ce créneau doit se répéter',
            ])
            ->add('capacity', IntegerType::class, [
                'label' => 'Capacité (nombre max de réservations par créneau)',
                'empty_data' => '1',
                'data' => 1,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Pas de data_class : c’est un simple formulaire "DTO"
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}




