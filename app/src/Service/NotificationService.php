<?php

namespace App\Service;

use App\Entity\Forum;
use App\Entity\Notification;
use App\Entity\Utilisateurs;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Crée une notification dans le forum d'un wiki
     */
    public function createNotification(
        Forum $forum,
        string $type,
        string $content,
        ?Utilisateurs $author = null
    ): void {
        $notification = new Notification();
        $notification->setForum($forum);
        $notification->setType($type);
        $notification->setContent($content);
        $notification->setAuthor($author);
        $notification->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($notification);
        $this->em->flush();
    }

    /**
     * Crée une notification pour un tag créé
     */
    public function notifyTagCreated(Forum $forum, string $tagName, ?Utilisateurs $author = null): void
    {
        $content = sprintf('Un nouveau tag de localisation a été ajouté : %s', $tagName);
        $this->createNotification($forum, 'tag_created', $content, $author);
    }

    /**
     * Crée une notification pour une routine d'agenda créée
     */
    public function notifyAgendaRoutineCreated(Forum $forum, string $routineTitle, ?Utilisateurs $author = null): void
    {
        $content = sprintf('Une nouvelle routine de créneaux a été créée : %s', $routineTitle);
        $this->createNotification($forum, 'agenda_routine_created', $content, $author);
    }

    /**
     * Crée une notification pour une réservation de créneau
     */
    public function notifyAgendaReserved(Forum $forum, string $slotTitle, \DateTimeInterface $startTime, ?Utilisateurs $author = null): void
    {
        $content = sprintf(
            'Un créneau a été réservé : %s le %s',
            $slotTitle,
            $startTime->format('d/m/Y à H:i')
        );
        $this->createNotification($forum, 'agenda_reserved', $content, $author);
    }

    /**
     * Crée une notification pour un article créé
     */
    public function notifyArticleCreated(Forum $forum, string $articleTitle, ?Utilisateurs $author = null): void
    {
        $content = sprintf('Un nouvel article a été créé : %s', $articleTitle);
        $this->createNotification($forum, 'article_created', $content, $author);
    }

    /**
     * Crée une notification pour un wiki enfant créé
     */
    public function notifyWikiChildCreated(Forum $forum, string $wikiTitle, ?Utilisateurs $author = null): void
    {
        $content = sprintf('Un nouveau wiki enfant a été créé : %s', $wikiTitle);
        $this->createNotification($forum, 'wiki_child_created', $content, $author);
    }
}



