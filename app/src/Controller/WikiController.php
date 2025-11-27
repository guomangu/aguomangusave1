<?php

namespace App\Controller;

use App\Form\WikiPageType; 
use App\Entity\WikiPage;
use App\Entity\Article;
use App\Form\ArticleType;
use App\Entity\AgendaSlotPattern;
use App\Entity\Forum;
use App\Entity\Message;
use App\Form\MessageType;
use App\Form\AgendaRoutineType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;

class WikiController extends AbstractController
{
    #[Route('/wiki', name: 'app_wiki_index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $page = new WikiPage();
        $form = $this->createForm(WikiPageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (method_exists($page, 'setCreatedAt')) {
                $page->setCreatedAt(new \DateTimeImmutable());
            }

            $imageFile = $form->get('imageFile')->getData();
            if ($imageFile) {
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/wiki';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $newFilename = uniqid('wiki_', true) . '.' . $imageFile->guessExtension();
                $imageFile->move($uploadDir, $newFilename);
                $page->setImage('/uploads/wiki/' . $newFilename);
            }

            $em->persist($page);
            $em->flush();

            return $this->redirectToRoute('app_wiki_show', ['id' => $page->getId()]);
        }

        $pages = $em->getRepository(WikiPage::class)->findBy([], ['id' => 'DESC']);

        return $this->render('wiki/list.html.twig', [
            'pages' => $pages,
            'form' => $form,
        ]);
    }

    // 1. LE CHEATCODE "CREATE"
    #[Route('/wiki/new', name: 'app_wiki_new')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $page = new WikiPage();
        $form = $this->createForm(WikiPageType::class, $page);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // On définit la date de création auto si ce n'est pas fait dans l'entité
            if (method_exists($page, 'setCreatedAt')) {
                $page->setCreatedAt(new \DateTimeImmutable());
            }

            $imageFile = $form->get('imageFile')->getData();
            if ($imageFile) {
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/wiki';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $newFilename = uniqid('wiki_', true) . '.' . $imageFile->guessExtension();
                $imageFile->move($uploadDir, $newFilename);
                $page->setImage('/uploads/wiki/' . $newFilename);
            }

            $em->persist($page);
            $em->flush();

            // Hop, on redirige vers le nouvel article
            return $this->redirectToRoute('app_wiki_show', ['id' => $page->getId()]);
        }

        return $this->render('wiki/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/wiki/{id}', name: 'app_wiki_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(WikiPage $wikiPage, Request $request, EntityManagerInterface $em): Response
    {
        // Magie : Symfony a vu "{id}" dans l'URL et "WikiPage" dans les arguments.
        // Il fait tout seul le "SELECT * FROM wiki_page WHERE id = ..."
        // Si l'id n'existe pas, il renvoie une erreur 404 tout seul.

        $currentUser = $this->getUser();
        $isOwner = $currentUser && $wikiPage->getOwner() && $wikiPage->getOwner() === $currentUser;

        // Formulaires réservés au propriétaire
        $articleForm = null;
        $childForm = null;
        $agendaForm = null;

        if ($isOwner) {
            // Formulaire pour ajouter un article enfant
            $article = new Article();
            $article->setWikiPage($wikiPage);
            $articleForm = $this->createForm(ArticleType::class, $article);
            $articleForm->handleRequest($request);

            if ($articleForm->isSubmitted() && $articleForm->isValid()) {
                $imageFile = $articleForm->get('imageFile')->getData();
                if ($imageFile) {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/articles';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $newFilename = uniqid('article_', true) . '.' . $imageFile->guessExtension();
                    $imageFile->move($uploadDir, $newFilename);
                    $article->setImage('/uploads/articles/' . $newFilename);
                }

                $em->persist($article);
                $em->flush();

                return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
            }

            // Formulaire pour ajouter un wiki enfant
            $childWiki = new WikiPage();
            $childForm = $this->createForm(WikiPageType::class, $childWiki);
            $childForm->handleRequest($request);

            if ($childForm->isSubmitted() && $childForm->isValid()) {
                // même propriétaire que le parent
                $childWiki->setOwner($wikiPage->getOwner());
                $childWiki->setParent($wikiPage);

                if (method_exists($childWiki, 'setCreatedAt')) {
                    $childWiki->setCreatedAt(new \DateTimeImmutable());
                }

                $imageFile = $childForm->get('imageFile')->getData();
                if ($imageFile) {
                    $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/wiki';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $newFilename = uniqid('wiki_', true) . '.' . $imageFile->guessExtension();
                    $imageFile->move($uploadDir, $newFilename);
                    $childWiki->setImage('/uploads/wiki/' . $newFilename);
                }

                $em->persist($childWiki);
                $em->flush();

                return $this->redirectToRoute('app_wiki_show', ['id' => $childWiki->getId()]);
            }

            // Formulaire pour créer une routine de créneaux (un seul bloc pour plusieurs jours)
            $agendaForm = $this->createForm(AgendaRoutineType::class, null);
            $agendaForm->handleRequest($request);

            if ($agendaForm->isSubmitted() && $agendaForm->isValid()) {
                $data = $agendaForm->getData();
                $title = $data['title'];
                $startTime = $data['startTime'];
                $endTime = $data['endTime'];
                $daysOfWeek = $data['daysOfWeek'] ?? [];
                $capacity = (int) $data['capacity'];

                foreach ($daysOfWeek as $dayOfWeek) {
                    $pattern = new AgendaSlotPattern();
                    $pattern->setWikiPage($wikiPage);
                    $pattern->setTitle($title);
                    $pattern->setStartTime($startTime);
                    $pattern->setEndTime($endTime);
                    $pattern->setDayOfWeek($dayOfWeek);
                    $pattern->setCapacity($capacity);
                    // validFrom / validTo null => se répète à l’infini

                    $em->persist($pattern);
                }

                $em->flush();

                return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
            }
        }

        // Gestion du forum et des messages
        $forum = $wikiPage->getForum();
        if (!$forum) {
            $forum = null;
        }

        $messageForm = null;
        $messages = [];

        if ($forum) {
            $messages = $em->getRepository(Message::class)->findBy(
                ['forum' => $forum],
                ['createdAt' => 'ASC']
            );

            if ($currentUser) {
                $message = new Message();
                $message->setForum($forum);
                $message->setAuthor($currentUser);
                $messageForm = $this->createForm(MessageType::class, $message);
                $messageForm->handleRequest($request);

                if ($messageForm->isSubmitted() && $messageForm->isValid()) {
                    $message->setCreatedAt(new \DateTimeImmutable());
                    $em->persist($message);
                    $em->flush();

                    return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
                }
            }
        }

        return $this->render('wiki/index.html.twig', [
            'page' => $wikiPage,
            'articleForm' => $articleForm,
            'childWikiForm' => $childForm,
            'agendaForm' => $agendaForm,
            'isOwner' => $isOwner,
            'forum' => $forum,
            'messages' => $messages,
            'messageForm' => $messageForm,
        ]);
    }
    
    // #[Route('/', name: 'app_home')]
    // public function index(): Response 
    // {
    //     // Petite redirection pour que la page d'accueil aille vers l'admin pour l'instant
    //     // ou vers une liste d'articles (à toi de voir)
    //     return $this->redirectToRoute('admin');
    // }

    // 2. LE CHEATCODE "DELETE"
    #[Route('/wiki/{id}/delete', name: 'app_wiki_delete', methods: ['POST'])]
    public function delete(Request $request, WikiPage $page, EntityManagerInterface $em): Response
    {
        // Sécurité CSRF (le "token" évite que n'importe qui supprime via un lien piégé)
        if ($this->isCsrfTokenValid('delete'.$page->getId(), $request->request->get('_token'))) {
            $em->remove($page);
            $em->flush();
        }

        return $this->redirectToRoute('app_home'); // Ou 'admin' selon ta route d'accueil
    }

    #[Route('/wiki/{wikiId}/pattern/{id}/delete', name: 'app_wiki_pattern_delete', methods: ['POST'])]
    public function deletePattern(
        int $wikiId,
        AgendaSlotPattern $pattern,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $wikiPage = $pattern->getWikiPage();

        if (!$wikiPage || $wikiPage->getId() !== $wikiId) {
            $this->addFlash('error', 'Routine introuvable pour ce wiki.');
            return $this->redirectToRoute('app_wiki_index');
        }

        $currentUser = $this->getUser();
        if (!$currentUser || !$wikiPage->getOwner() || $wikiPage->getOwner() !== $currentUser) {
            $this->addFlash('error', 'Vous ne pouvez pas modifier les routines de ce wiki.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
        }

        if ($this->isCsrfTokenValid('delete_pattern'.$pattern->getId(), $request->request->get('_token'))) {
            $em->remove($pattern);
            $em->flush();
            $this->addFlash('success', 'Routine supprimée.');
        }

        return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
    }

    #[Route('/wiki/{id}/forum/create', name: 'app_wiki_forum_create', methods: ['POST'])]
    public function createForum(WikiPage $wikiPage, Request $request, EntityManagerInterface $em): Response
    {
        $currentUser = $this->getUser();
        if (!$currentUser || !$wikiPage->getOwner() || $wikiPage->getOwner() !== $currentUser) {
            $this->addFlash('error', 'Vous ne pouvez pas créer un forum pour ce wiki.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
        }

        if ($wikiPage->getForum()) {
            $this->addFlash('info', 'Le forum existe déjà pour ce wiki.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
        }

        if ($this->isCsrfTokenValid('create_forum'.$wikiPage->getId(), $request->request->get('_token'))) {
            $forum = new Forum();
            $forum->setWikiPage($wikiPage);
            $wikiPage->setForum($forum);

            $em->persist($forum);
            $em->flush();

            $this->addFlash('success', 'Forum créé pour ce wiki.');
        }

        return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
    }
}