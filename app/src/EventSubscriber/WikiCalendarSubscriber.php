<?php

namespace App\EventSubscriber;

use App\Entity\Agenda;
use App\Repository\AgendaRepository;
use App\Repository\AgendaSlotPatternRepository;
use CalendarBundle\Entity\Event;
use CalendarBundle\Event\SetDataEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class WikiCalendarSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AgendaSlotPatternRepository $patternRepository,
        private AgendaRepository $agendaRepository,
        private UrlGeneratorInterface $router
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // On écoute directement la classe d'événement SetDataEvent
        return [
            SetDataEvent::class => 'onCalendarSetData',
        ];
    }

    public function onCalendarSetData(SetDataEvent $event): void
    {
        $start = $event->getStart();
        $end = $event->getEnd();
        $filters = $event->getFilters();

        $wikiId = $filters['wikiId'] ?? null;

        if (!$wikiId) {
            return;
        }

        try {
            // Récupère tous les patterns de ce wiki (via son id)
            // Utilisation de p.wikiPage = :wikiPage au lieu de IDENTITY() pour meilleure compatibilité
            $wikiPage = $this->patternRepository->getEntityManager()->getReference(\App\Entity\WikiPage::class, $wikiId);
            $patterns = $this->patternRepository->createQueryBuilder('p')
                ->andWhere('p.wikiPage = :wikiPage')
                ->setParameter('wikiPage', $wikiPage)
                ->getQuery()
                ->getResult();
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
            // Si les tables n'existent pas, on retourne sans ajouter d'événements
            return;
        } catch (\Exception $e) {
            // Pour les autres erreurs de base de données (comme SQLSTATE[42P01] de PostgreSQL)
            if (str_contains($e->getMessage(), 'does not exist') || $e->getCode() === '42P01') {
                // Tables manquantes, on retourne sans ajouter d'événements
                return;
            }
            // Autre erreur, on la laisse remonter
            throw $e;
        }

        /** @var \App\Entity\AgendaSlotPattern $pattern */
        foreach ($patterns as $pattern) {
            // On parcourt les jours dans l'intervalle demandé
            $period = new \DatePeriod(
                (clone $start)->setTime(0, 0),
                new \DateInterval('P1D'),
                (clone $end)->setTime(23, 59)
            );

            foreach ($period as $day) {
                // 1 (lundi) à 7 (dimanche)
                $dayOfWeek = (int) $day->format('N');

                if ($dayOfWeek !== $pattern->getDayOfWeek()) {
                    continue;
                }

                // Filtre sur validFrom / validTo
                if ($pattern->getValidFrom() && $day < $pattern->getValidFrom()) {
                    continue;
                }
                if ($pattern->getValidTo() && $day > $pattern->getValidTo()) {
                    continue;
                }

                // Construit le DateTime de début/fin pour ce jour
                $startTime = $pattern->getStartTime();
                $endTime = $pattern->getEndTime();

                $occurrenceStart = (clone $day)->setTime(
                    (int) $startTime->format('H'),
                    (int) $startTime->format('i')
                );
                $occurrenceEnd = (clone $day)->setTime(
                    (int) $endTime->format('H'),
                    (int) $endTime->format('i')
                );

                // Si l'heure de fin est <= heure de début, on considère que le créneau
                // se termine le lendemain (créneau "inter-jours", ex: 21h-02h)
                if ($endTime <= $startTime) {
                    $occurrenceEnd->modify('+1 day');
                }

                // Vérifie combien de réservations existent déjà pour ce pattern + ce créneau
                try {
                    $existingCount = $this->agendaRepository->createQueryBuilder('a')
                        ->select('COUNT(a.id)')
                        ->andWhere('a.slotPattern = :pattern')
                        ->andWhere('a.start = :start')
                        ->setParameter('pattern', $pattern)
                        ->setParameter('start', $occurrenceStart)
                        ->getQuery()
                        ->getSingleScalarResult();
                } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
                    // Si les tables n'existent pas, on considère le créneau comme plein
                    continue;
                } catch (\Exception $e) {
                    // Pour les autres erreurs de base de données
                    if (str_contains($e->getMessage(), 'does not exist') || $e->getCode() === '42P01') {
                        // Tables manquantes, on considère le créneau comme plein
                        continue;
                    }
                    // Autre erreur, on la laisse remonter
                    throw $e;
                }

                if ($existingCount >= $pattern->getCapacity()) {
                    // créneau plein, on peut soit ne pas l'afficher,
                    // soit l'afficher comme non cliquable (ici, on ne l'ajoute pas)
                    continue;
                }

                // Ajoute l'événement au calendrier avec une URL de réservation
                $calEvent = new Event(
                    $pattern->getTitle(),
                    $occurrenceStart,
                    $occurrenceEnd
                );

                $calEvent->setOptions([
                    'backgroundColor' => '#16a34a',
                    'borderColor' => '#16a34a',
                ]);

                $calEvent->addOption(
                    'url',
                    $this->router->generate('app_creneau_show', [
                        'wikiId' => $wikiId,
                        'patternId' => $pattern->getId(),
                        'start' => $occurrenceStart->format('Y-m-d\TH:i:s'),
                    ])
                );

                $event->addEvent($calEvent);
            }
        }
    }
}

