<?php

namespace App\Controller;

use App\Entity\LocationTag;
use App\Entity\Forum;
use App\Entity\Message;
use App\Form\MessageType;
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
        EntityManagerInterface $em
    ): Response {
        $forum = $tag->getForum();
        $currentUser = $this->getUser();

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

                    return $this->redirectToRoute('app_tag_show', ['id' => $tag->getId()]);
                }
            }
        }

        $wikis = $tag->getWikiPages();

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
            'messages' => $messages,
            'messageForm' => $messageForm,
            'wikis' => $wikis,
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


