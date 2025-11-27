<?php

namespace App\Controller;

use App\Entity\LocationTag;
use App\Entity\Forum;
use App\Entity\Message;
use App\Entity\Article;
use App\Entity\Utilisateurs;
use App\Form\MessageType;
use App\Form\ArticleType;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TagController extends AbstractController
{
    #[Route('/tag/{id}', name: 'app_tag_show', methods: ['GET', 'POST'])]
    public function show(
        LocationTag $tag,
        Request $request,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): Response {
        $forum = $tag->getForum();
        $currentUser = $this->getUser();

        $messageForm = null;
        $articleForm = null;
        $messages = [];
        $forumItems = [];

        if ($forum) {
            // Charger les messages - on utilise une requête DQL qui vérifie l'existence de l'auteur
            $messages = $em->createQuery(
                'SELECT m FROM App\Entity\Message m 
                 LEFT JOIN m.author a 
                 WHERE m.forum = :forum 
                 AND (a.id IS NOT NULL OR m.author IS NULL)
                 ORDER BY m.createdAt ASC'
            )
            ->setParameter('forum', $forum)
            ->getResult();
            
            // Vérifier que les auteurs existent encore
            $validMessages = [];
            $userRepo = $em->getRepository(Utilisateurs::class);
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
                 ORDER BY n.createdAt ASC'
            )
            ->setParameter('forum', $forum)
            ->getResult();
            
            // Vérifier que les auteurs existent encore
            $notifications = [];
            $userRepo = $em->getRepository(Utilisateurs::class);
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

                    return $this->redirectToRoute('app_tag_show', ['id' => $tag->getId()]);
                }

                // Formulaire pour créer un article lié au tag
                $article = new Article();
                $article->setLocationTag($tag);
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
                    if ($forum) {
                        $notificationService->notifyArticleCreated($forum, $article->getTitle(), $currentUser);
                    }

                    return $this->redirectToRoute('app_tag_show', ['id' => $tag->getId()]);
                }
            }
        }

        $wikis = $tag->getWikiPages();
        $articles = $em->getRepository(Article::class)->findBy(
            ['locationTag' => $tag],
            ['id' => 'DESC']
        );

        // Construire la hiérarchie des parents
        $parents = [];
        $current = $tag->getParent();
        while ($current) {
            $parents[] = $current;
            $current = $current->getParent();
        }
        // Inverser pour avoir du plus général au plus spécifique
        $parents = array_reverse($parents);

        return $this->render('tag/show.html.twig', [
            'tag' => $tag,
            'forum' => $forum,
            'forumItems' => $forumItems,
            'messageForm' => $messageForm,
            'articleForm' => $articleForm,
            'wikis' => $wikis,
            'articles' => $articles,
            'parents' => $parents,
        ]);
    }

    #[Route('/tag/{id}/forum/create', name: 'app_tag_forum_create', methods: ['POST'])]
    public function createForum(LocationTag $tag, Request $request, EntityManagerInterface $em): Response
    {
        if ($tag->getForum()) {
            $this->addFlash('info', 'Le forum existe déjà pour ce tag.');
            return $this->redirectToRoute('app_tag_show', ['id' => $tag->getId()]);
        }

        if ($this->isCsrfTokenValid('create_forum_tag'.$tag->getId(), $request->request->get('_token'))) {
            $forum = new Forum();
            $forum->setLocationTag($tag);
            $tag->setForum($forum);

            $em->persist($forum);
            $em->flush();

            $this->addFlash('success', 'Forum créé pour ce tag.');
        }

        return $this->redirectToRoute('app_tag_show', ['id' => $tag->getId()]);
    }
}


