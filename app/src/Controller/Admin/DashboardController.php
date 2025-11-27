<?php

namespace App\Controller\Admin;
use App\Entity\WikiPage;
use App\Entity\Article;
use App\Entity\Agenda;
use App\Entity\AgendaSlotPattern;
use App\Entity\Utilisateurs;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use App\Controller\Admin\WikiPageCrudController;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        // Sécurisation : seul guillaume@gmail.com peut accéder à l'admin
        $user = $this->getUser();
        if (!$user || $user->getUserIdentifier() !== 'guillaume@gmail.com') {
            $this->addFlash('error', 'Accès refusé. Seul l\'administrateur peut accéder à cette page.');
            return $this->redirectToRoute('app_home');
        }

        // Option 1. You can make your dashboard redirect to some common page of your backend
        //
        // 1.1) If you have enabled the "pretty URLs" feature:
        // return $this->redirectToRoute('admin_user_index');
        //
        // 1.2) Same example but using the "ugly URLs" that were used in previous EasyAdmin versions:
        // $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);
        // return $this->redirect($adminUrlGenerator->setController(OneOfYourCrudController::class)->generateUrl());

        // Option 2. You can make your dashboard redirect to different pages depending on the user
        //
        // if ('jane' === $this->getUser()->getUsername()) {
        //     return $this->redirectToRoute('...');
        // }

        // Option 3. You can render some custom template to display a proper dashboard with widgets, etc.
        // (tip: it's easier if your template extends from @EasyAdmin/page/content.html.twig)
        //
        // return $this->render('some/path/my-dashboard.html.twig');

        // On récupère le service qui génère les URLs d'admin
        $adminUrlGenerator = $this->container->get(AdminUrlGenerator::class);

        // On redirige vers la liste des WikiPage
        return $this->redirect($adminUrlGenerator->setController(WikiPageCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('App');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToCrud('Mes Pages Wiki', 'fa fa-file-text', WikiPage::class);
        yield MenuItem::linkToCrud('Articles', 'fa fa-newspaper', Article::class);
        yield MenuItem::linkToCrud('Créneaux réservés', 'fa fa-calendar-check', Agenda::class);
        yield MenuItem::linkToCrud('Routines de créneaux', 'fa fa-calendar', AgendaSlotPattern::class);
        yield MenuItem::linkToCrud('Utilisateurs', 'fa fa-users', Utilisateurs::class);
    }
}
