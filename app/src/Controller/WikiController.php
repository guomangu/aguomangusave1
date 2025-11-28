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
use App\Service\LocationTagService;
use App\Service\NotificationService;
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
        try {
            $page = new WikiPage();
            $form = $this->createForm(WikiPageType::class, $page);
            $form->handleRequest($request);

            if ($form->isSubmitted()) {
                if (!$form->isValid()) {
                    // Logger les erreurs de validation pour déboguer
                    $errors = [];
                    foreach ($form->getErrors(true) as $error) {
                        $errors[] = $error->getMessage();
                    }
                    error_log('Erreurs de validation du formulaire Wiki: ' . json_encode($errors));
                    
                    // Afficher les erreurs dans les flash messages
                    foreach ($form->getErrors(true) as $error) {
                        $this->addFlash('error', $error->getMessage());
                    }
                }
            }

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

            $pages = $em->getRepository(WikiPage::class)->findBy([], ['id' => 'DESC'], 30);

            return $this->render('wiki/list.html.twig', [
                'pages' => $pages,
                'form' => $form,
            ]);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
            // Si la table n'existe pas, afficher une page vide
            return $this->render('wiki/empty.html.twig', [
                'message' => 'La base de données n\'est pas encore initialisée. Veuillez contacter l\'administrateur.',
            ]);
        }
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
    public function show(
        WikiPage $wikiPage,
        Request $request,
        EntityManagerInterface $em,
        LocationTagService $locationTagService,
        NotificationService $notificationService
    ): Response
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
        $locationSearchResults = [];
        $locationSearchQuery = '';
        $customLocationForm = null;

        // Formulaire pour ajouter un article - accessible à tous les utilisateurs connectés
        if ($currentUser) {
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

                // Notification dans le forum si il existe
                $forum = $wikiPage->getForum();
                if ($forum) {
                    $notificationService->notifyArticleCreated($forum, $article->getTitle(), $currentUser);
                }

                return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
            }
        }

        if ($isOwner) {

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

                // Notification dans le forum si il existe
                $forum = $wikiPage->getForum();
                if ($forum) {
                    $notificationService->notifyWikiChildCreated($forum, $childWiki->getTitle(), $currentUser);
                }

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

                // Notification dans le forum si il existe
                $forum = $wikiPage->getForum();
                if ($forum) {
                    $notificationService->notifyAgendaRoutineCreated($forum, $title, $currentUser);
                }

                return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
            }

            // Vérifier si le wiki a déjà des tags officiels (non-custom)
            $hasOfficialTags = false;
            foreach ($wikiPage->getLocationTags() as $tag) {
                if ($tag->getType() !== 'custom') {
                    $hasOfficialTags = true;
                    break;
                }
            }

            // Formulaire de recherche de localisation (CSV) - seulement si pas de tags officiels déjà présents
            $locationSearchQuery = '';
            $locationSearchResults = [];
            if (!$hasOfficialTags) {
                $locationSearchQuery = (string) $request->query->get('loc_q', '');
                if ($locationSearchQuery !== '') {
                    $locationSearchResults = $locationTagService->searchPlaces($locationSearchQuery, 10);
                }
            }

            // Formulaire de tag personnalisé libre (titre + description), toujours lié au wiki
            $customLocationFormBuilder = $this->createFormBuilder(null, [
                'csrf_protection' => true,
            ])
                ->add('title', \Symfony\Component\Form\Extension\Core\Type\TextType::class, [
                    'label' => 'Titre du tag (ex : Étage)',
                ])
                ->add('description', \Symfony\Component\Form\Extension\Core\Type\TextareaType::class, [
                    'label' => 'Description (ex : 5e étage)',
                    'required' => false,
                    'attr' => [
                        'rows' => 2,
                    ],
                ]);

            $customLocationForm = $customLocationFormBuilder->getForm();
            $customLocationForm->handleRequest($request);

            if ($customLocationForm->isSubmitted() && $customLocationForm->isValid()) {
                $data = $customLocationForm->getData();
                $tag = new \App\Entity\LocationTag();
                $tag->setName($data['title'])
                    ->setType('custom')
                    ->setDescription($data['description'] ?? null)
                    ->setParent(null);

                $em->persist($tag);
                $wikiPage->addLocationTag($tag);
                $em->flush();

                // Notification dans le forum si il existe
                $forum = $wikiPage->getForum();
                if ($forum) {
                    $tagName = $tag->getDescription() ?: $tag->getName();
                    $notificationService->notifyTagCreated($forum, $tagName, $currentUser);
                }

                return $this->redirectToRoute('app_wiki_show', ['id' => $wikiPage->getId()]);
            }
        }

        // Vérifier si le wiki a déjà des tags officiels (non-custom) pour l'affichage
        // (doit être accessible même si l'utilisateur n'est pas propriétaire)
        if (!isset($hasOfficialTags)) {
            $hasOfficialTags = false;
            foreach ($wikiPage->getLocationTags() as $tag) {
                if ($tag->getType() !== 'custom') {
                    $hasOfficialTags = true;
                    break;
                }
            }
        }

        // Gestion du forum et des messages
        $forum = $wikiPage->getForum();
        if (!$forum) {
            $forum = null;
        }

        $messageForm = null;
        $forumItems = []; // Mélange de notifications et messages

        if ($forum) {
            // Charger les messages - on utilise une requête DQL qui vérifie l'existence de l'auteur
            $messages = $em->createQuery(
                'SELECT m FROM App\Entity\Message m 
                 LEFT JOIN m.author a 
                 WHERE m.forum = :forum 
                 AND (a.id IS NOT NULL OR m.author IS NULL)
                 ORDER BY m.createdAt DESC'
            )
            ->setParameter('forum', $forum)
            ->setMaxResults(50)
            ->getResult();
            
            // Vérifier que les auteurs existent encore
            $validMessages = [];
            $userRepo = $em->getRepository(\App\Entity\Utilisateurs::class);
            foreach ($messages as $msg) {
                $author = $msg->getAuthor();
                if ($author === null) {
                    $validMessages[] = $msg;
                } else {
                    // Vérifier si l'auteur existe encore
                    $authorExists = $userRepo->find($author->getId());
                    if ($authorExists) {
                        $validMessages[] = $msg;
                    } else {
                        // L'auteur n'existe plus, on met l'auteur à null
                        $msg->setAuthor(null);
                        $validMessages[] = $msg;
                    }
                }
            }
            $messages = $validMessages;
            
            // Charger les notifications - on utilise une requête DQL qui vérifie l'existence de l'auteur
            $allNotifications = $em->createQuery(
                'SELECT n FROM App\Entity\Notification n 
                 LEFT JOIN n.author a 
                 WHERE n.forum = :forum 
                 ORDER BY n.createdAt DESC'
            )
            ->setParameter('forum', $forum)
            ->setMaxResults(50)
            ->getResult();
            
            // Vérifier que les auteurs existent encore
            $notifications = [];
            $userRepo = $em->getRepository(\App\Entity\Utilisateurs::class);
            foreach ($allNotifications as $notif) {
                try {
                    $author = $notif->getAuthor();
                    if ($author === null) {
                        $notifications[] = $notif;
                    } else {
                        // Vérifier si l'auteur existe encore en utilisant l'ID directement
                        $authorId = $author->getId();
                        $authorExists = $userRepo->find($authorId);
                        if ($authorExists) {
                            $notifications[] = $notif;
                        } else {
                            // L'auteur n'existe plus, on met l'auteur à null
                            $notif->setAuthor(null);
                            $notifications[] = $notif;
                        }
                    }
                } catch (\Doctrine\ORM\EntityNotFoundException $e) {
                    // L'auteur n'existe plus, on met l'auteur à null
                    $notif->setAuthor(null);
                    $notifications[] = $notif;
                } catch (\Exception $e) {
                    // En cas d'autre erreur, on met l'auteur à null pour éviter les erreurs
                    $notif->setAuthor(null);
                    $notifications[] = $notif;
                }
            }

            // Fusionner les notifications et messages avec un type pour les distinguer
            foreach ($messages as $message) {
                // Préparer les données de l'auteur de manière sécurisée
                $authorName = 'Utilisateur disparu';
                try {
                    $author = $message->getAuthor();
                    if ($author) {
                        $authorName = $author->getDisplayName();
                    }
                } catch (\Exception $e) {
                    // Auteur inaccessible, on garde "Utilisateur disparu"
                }
                
                $forumItems[] = [
                    'type' => 'message',
                    'item' => $message,
                    'authorName' => $authorName,
                    'createdAt' => $message->getCreatedAt(),
                ];
            }

            foreach ($notifications as $notification) {
                // Préparer les données de l'auteur de manière sécurisée
                $authorName = 'Utilisateur disparu';
                try {
                    $author = $notification->getAuthor();
                    if ($author) {
                        $authorName = $author->getDisplayName();
                    }
                } catch (\Exception $e) {
                    // Auteur inaccessible, on garde "Utilisateur disparu"
                }
                
                $forumItems[] = [
                    'type' => 'notification',
                    'item' => $notification,
                    'authorName' => $authorName,
                    'createdAt' => $notification->getCreatedAt(),
                ];
            }

            // Trier par date/heure (du plus récent au plus ancien)
            usort($forumItems, function ($a, $b) {
                return $b['createdAt'] <=> $a['createdAt'];
            });

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

        // Limiter les articles affichés
        $articles = $em->getRepository(\App\Entity\Article::class)
            ->findBy(['wikiPage' => $wikiPage], ['id' => 'DESC'], 30);
        
        // Limiter les patterns affichés (pour le propriétaire)
        $patterns = null;
        if ($isOwner) {
            $patterns = $em->getRepository(\App\Entity\AgendaSlotPattern::class)
                ->findBy(['wikiPage' => $wikiPage], ['id' => 'DESC'], 30);
        }

        return $this->render('wiki/index.html.twig', [
            'page' => $wikiPage,
            'articleForm' => $articleForm,
            'childWikiForm' => $childForm,
            'agendaForm' => $agendaForm,
            'isOwner' => $isOwner,
            'forum' => $forum,
            'forumItems' => $forumItems,
            'messageForm' => $messageForm,
            'locationSearchQuery' => $locationSearchQuery,
            'locationSearchResults' => $locationSearchResults,
            'customLocationForm' => $customLocationForm,
            'hasOfficialTags' => $hasOfficialTags,
            'articles' => $articles,
            'patterns' => $patterns,
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