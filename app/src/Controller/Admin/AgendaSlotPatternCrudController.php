<?php

namespace App\Controller\Admin;

use App\Entity\AgendaSlotPattern;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

class AgendaSlotPatternCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AgendaSlotPattern::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield AssociationField::new('wikiPage', 'Wiki')
            ->setHelp('Page wiki à laquelle ce créneau appartient');

        yield TextField::new('title', 'Titre du créneau');

        yield ChoiceField::new('dayOfWeek', 'Jour de la semaine')
            ->setChoices([
                'Lundi' => 1,
                'Mardi' => 2,
                'Mercredi' => 3,
                'Jeudi' => 4,
                'Vendredi' => 5,
                'Samedi' => 6,
                'Dimanche' => 7,
            ]);

        yield TimeField::new('startTime', 'Heure de début')
            ->setFormat('HH:mm');
        yield TimeField::new('endTime', 'Heure de fin')
            ->setFormat('HH:mm');

        yield DateField::new('validFrom', 'Valide à partir du')
            ->setRequired(false);
        yield DateField::new('validTo', 'Valide jusqu’au')
            ->setRequired(false);

        yield IntegerField::new('capacity', 'Capacité')
            ->setHelp('Nombre max de réservations sur ce créneau');
    }
}
