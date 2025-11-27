<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

abstract class BaseCrudController extends AbstractCrudController
{
    // La sécurisation se fait via l'EventSubscriber AdminAccessSubscriber
    // qui intercepte toutes les requêtes vers /admin
}

