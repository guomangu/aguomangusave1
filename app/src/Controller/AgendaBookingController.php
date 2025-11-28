<?php

namespace App\Controller;

use App\Entity\Agenda;
use App\Entity\AgendaSlotPattern;
use App\Entity\WikiPage;
use App\Repository\AgendaSlotPatternRepository;
use App\Repository\WikiPageRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AgendaBookingController extends AbstractController
{
    #[Route('/creneau', name: 'app_creneau_show', methods: ['GET', 'POST'])]
    public function show(
        Request $request,
        WikiPageRepository $wikiRepo,
        AgendaSlotPatternRepository $patternRepo,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): Response {
        $wikiId = $request->query->get('wikiId');
        $patternId = $request->query->get('patternId');
        $startParam = $request->query->get('start');

        if (!$wikiId || !$patternId || !$startParam) {
            $this->addFlash('error', 'Paramètres de créneau manquants.');
            return $this->redirectToRoute('app_wiki_index');
        }

        $wiki = $wikiRepo->find($wikiId);
        $pattern = $patternRepo->find($patternId);

        if (!$wiki || !$pattern || $pattern->getWikiPage()?->getId() !== $wiki->getId()) {
            $this->addFlash('error', 'Créneau introuvable.');
            return $this->redirectToRoute('app_wiki_index');
        }

        try {
            $occurrenceStart = new \DateTime($startParam);
        } catch (\Exception) {
            $this->addFlash('error', 'Date de créneau invalide.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
        }

        // Recalcule l'heure de fin à partir du pattern
        $startTime = $pattern->getStartTime();
        $endTime = $pattern->getEndTime();

        $occurrenceStart->setTime(
            (int) $startTime->format('H'),
            (int) $startTime->format('i')
        );

        $occurrenceEnd = (clone $occurrenceStart)->setTime(
            (int) $endTime->format('H'),
            (int) $endTime->format('i')
        );

        // Si l'heure de fin est <= heure de début, on considère que le créneau se termine le lendemain
        if ($endTime <= $startTime) {
            $occurrenceEnd->modify('+1 day');
        }

        // Récupère les réservations existantes pour ce créneau avec les utilisateurs
        $existingReservations = $em->getRepository(Agenda::class)->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')
            ->addSelect('u')
            ->andWhere('a.slotPattern = :pattern')
            ->andWhere('a.start = :start')
            ->setParameter('pattern', $pattern)
            ->setParameter('start', $occurrenceStart)
            ->orderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        $existingCount = count($existingReservations);
        $isAvailable = $existingCount < $pattern->getCapacity();
        $remainingSlots = max(0, $pattern->getCapacity() - $existingCount);

        // Gestion de la réservation si le formulaire est soumis
        $user = $this->getUser();
        if ($request->isMethod('POST') && $user) {
            if (!$isAvailable) {
                $this->addFlash('error', 'Ce créneau est déjà complet.');
                return $this->redirectToRoute('app_creneau_show', [
                    'wikiId' => $wikiId,
                    'patternId' => $patternId,
                    'start' => $startParam,
                ]);
            }

            // Création de la réservation
            $agenda = new Agenda();
            $agenda->setWikiPage($wiki);
            $agenda->setSlotPattern($pattern);
            $agenda->setTitle($pattern->getTitle());
            $agenda->setStart($occurrenceStart);
            $agenda->setEnd($occurrenceEnd);
            $agenda->setUser($user);

            $em->persist($agenda);
            $em->flush();

            // Notification dans le forum si il existe
            $forum = $wiki->getForum();
            if ($forum) {
                $notificationService->notifyAgendaReserved($forum, $pattern->getTitle(), $occurrenceStart, $user);
            }

            $this->addFlash('success', 'Créneau réservé avec succès.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
        }

        // Filtrer les réservations pour gérer les utilisateurs supprimés
        $validReservations = [];
        foreach ($existingReservations as $reservation) {
            try {
                $reservationUser = $reservation->getUser();
                if ($reservationUser !== null) {
                    // Vérifier que l'utilisateur existe vraiment
                    $userId = $reservationUser->getId();
                    $userExists = $em->getRepository(\App\Entity\Utilisateurs::class)->find($userId);
                    if (!$userExists) {
                        $reservation->setUser(null);
                    }
                }
                $validReservations[] = $reservation;
            } catch (\Doctrine\ORM\EntityNotFoundException $e) {
                $reservation->setUser(null);
                $validReservations[] = $reservation;
            } catch (\Exception $e) {
                $reservation->setUser(null);
                $validReservations[] = $reservation;
            }
        }

        return $this->render('creneau/show.html.twig', [
            'wiki' => $wiki,
            'pattern' => $pattern,
            'occurrenceStart' => $occurrenceStart,
            'occurrenceEnd' => $occurrenceEnd,
            'isAvailable' => $isAvailable,
            'remainingSlots' => $remainingSlots,
            'capacity' => $pattern->getCapacity(),
            'existingCount' => $existingCount,
            'existingReservations' => $validReservations,
        ]);
    }

    #[Route('/agenda/book', name: 'app_agenda_book', methods: ['GET', 'POST'])]
    public function book(
        Request $request,
        WikiPageRepository $wikiRepo,
        AgendaSlotPatternRepository $patternRepo,
        EntityManagerInterface $em,
        NotificationService $notificationService
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour réserver un créneau.');
            return $this->redirectToRoute('app_login');
        }
        $wikiId = $request->query->get('wikiId');
        $patternId = $request->query->get('patternId');
        $startParam = $request->query->get('start');

        if (!$wikiId || !$patternId || !$startParam) {
            $this->addFlash('error', 'Paramètres de réservation manquants.');
            return $this->redirectToRoute('app_wiki_index');
        }

        $wiki = $wikiRepo->find($wikiId);
        $pattern = $patternRepo->find($patternId);

        if (!$wiki || !$pattern || $pattern->getWikiPage()?->getId() !== $wiki->getId()) {
            $this->addFlash('error', 'Créneau introuvable.');
            return $this->redirectToRoute('app_wiki_index');
        }

        try {
            $occurrenceStart = new \DateTime($startParam);
        } catch (\Exception) {
            $this->addFlash('error', 'Date de créneau invalide.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
        }

        // Recalcule l'heure de fin à partir du pattern
        $startTime = $pattern->getStartTime();
        $endTime = $pattern->getEndTime();

        $occurrenceStart->setTime(
            (int) $startTime->format('H'),
            (int) $startTime->format('i')
        );

        $occurrenceEnd = (clone $occurrenceStart)->setTime(
            (int) $endTime->format('H'),
            (int) $endTime->format('i')
        );

        // Vérifie la capacité (nombre de réservations existantes pour ce créneau)
        $existingCount = $em->getRepository(Agenda::class)->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.slotPattern = :pattern')
            ->andWhere('a.start = :start')
            ->setParameter('pattern', $pattern)
            ->setParameter('start', $occurrenceStart)
            ->getQuery()
            ->getSingleScalarResult();

        if ($existingCount >= $pattern->getCapacity()) {
            $this->addFlash('error', 'Ce créneau est déjà complet.');
            return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
        }

        // Création de la réservation
        $agenda = new Agenda();
        $agenda->setWikiPage($wiki);
        $agenda->setSlotPattern($pattern);
        $agenda->setTitle($pattern->getTitle());
        $agenda->setStart($occurrenceStart);
        $agenda->setEnd($occurrenceEnd);
        $agenda->setUser($user);

        $em->persist($agenda);
        $em->flush();

        // Notification dans le forum si il existe
        $forum = $wiki->getForum();
        if ($forum) {
            $notificationService->notifyAgendaReserved($forum, $pattern->getTitle(), $occurrenceStart, $user);
        }

        $this->addFlash('success', 'Créneau réservé avec succès.');

        return $this->redirectToRoute('app_wiki_show', ['id' => $wiki->getId()]);
    }
}


