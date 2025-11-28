<?php

namespace App\Controller;

use App\Entity\Utilisateurs;
use App\Entity\WikiPage;
use App\Entity\Agenda;
use App\Form\UserType;
use App\Form\UserProfileType;
use App\Form\DeleteAccountType;
use App\Form\WikiPageType;
use App\Repository\UtilisateursRepository;
use App\Repository\MessageRepository;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    #[Route('/register', name: 'app_user_register')]
    public function register(Request $request, EntityManagerInterface $em): Response
    {
        $user = new Utilisateurs();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_user_public', ['username' => $user->getUsername()]);
        }

        return $this->render('user/register.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/u/{identifier}', name: 'app_user_public', methods: ['GET', 'POST'])]
    public function publicPage(
        string $identifier,
        UtilisateursRepository $userRepository,
        MessageRepository $messageRepository,
        NotificationRepository $notificationRepository,
        Request $request,
        EntityManagerInterface $em
    ): Response
    {
        // Chercher d'abord par pseudo, puis par email (pour rétrocompatibilité)
        $user = $userRepository->findOneBy(['pseudo' => $identifier]);
        if (!$user) {
            $user = $userRepository->findOneBy(['email' => $identifier]);
        }

        if (!$user) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        $wiki = new WikiPage();
        $form = $this->createForm(WikiPageType::class, $wiki);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $wiki->setOwner($user);

            if (method_exists($wiki, 'setCreatedAt')) {
                $wiki->setCreatedAt(new \DateTimeImmutable());
            }

            $imageFile = $form->get('imageFile')->getData();
            if ($imageFile) {
                $uploadDir = $this->getParameter('kernel.project_dir') . '/public/uploads/wiki';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0775, true);
                }

                $newFilename = uniqid('wiki_', true) . '.' . $imageFile->guessExtension();
                $imageFile->move($uploadDir, $newFilename);
                $wiki->setImage('/uploads/wiki/' . $newFilename);
            }

            $em->persist($wiki);
            $em->flush();

            // Rediriger vers l'URL avec le pseudo (ou email si pas de pseudo)
            $identifier = $user->getPseudo() ?: $user->getEmail();
            return $this->redirectToRoute('app_user_public', ['identifier' => $identifier]);
        }

        // Limiter les wikis affichés
        $wikis = $em->getRepository(WikiPage::class)->findBy(
            ['owner' => $user],
            ['id' => 'DESC'],
            30
        );
        // Charger les réservations avec slotPattern pour pouvoir créer des liens
        $reservations = $em->getRepository(Agenda::class)->createQueryBuilder('a')
            ->leftJoin('a.slotPattern', 'sp')
            ->addSelect('sp')
            ->leftJoin('a.wikiPage', 'w')
            ->addSelect('w')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.start', 'DESC')
            ->setMaxResults(30)
            ->getQuery()
            ->getResult();

        // Calculer les statistiques
        $stats = $user->getAllStats($messageRepository, $notificationRepository);

        return $this->render('user/public.html.twig', [
            'user' => $user,
            'wikiForm' => $form,
            'wikis' => $wikis,
            'reservations' => $reservations,
            'stats' => $stats,
        ]);
    }

    #[Route('/profile', name: 'app_user_profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $userPasswordHasher
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $form = $this->createForm(UserProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Gérer le changement de mot de passe si fourni
            $plainPasswordData = $form->get('plainPassword')->getData();
            if ($plainPasswordData && !empty($plainPasswordData['first'])) {
                $plainPassword = $plainPasswordData['first'];
                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
            }

            $em->flush();

            $this->addFlash('success', 'Profil mis à jour avec succès.');

            return $this->redirectToRoute('app_user_profile');
        }

        // Formulaire de suppression de compte
        $deleteForm = $this->createForm(DeleteAccountType::class);
        $deleteForm->handleRequest($request);

        if ($deleteForm->isSubmitted() && $deleteForm->isValid()) {
            $password = $deleteForm->get('password')->getData();
            
            // Vérifier le mot de passe
            if (!$userPasswordHasher->isPasswordValid($user, $password)) {
                $this->addFlash('error', 'Mot de passe incorrect. La suppression du compte a été annulée.');
                return $this->redirectToRoute('app_user_profile');
            }

            // Supprimer le compte
            $em->remove($user);
            $em->flush();

            // Déconnecter l'utilisateur et invalider la session
            $request->getSession()->invalidate();
            
            // Rediriger vers la page d'accueil avec un message
            $this->addFlash('success', 'Votre compte a été supprimé avec succès.');
            return $this->redirectToRoute('app_home');
        }

        // Récupérer les créneaux réservés par les clients sur les wikis dont l'utilisateur est propriétaire
        // On récupère d'abord les IDs des réservations
        $reservationIds = $em->getRepository(Agenda::class)->createQueryBuilder('a')
            ->select('a.id')
            ->join('a.wikiPage', 'w')
            ->where('w.owner = :owner')
            ->setParameter('owner', $user)
            ->orderBy('a.start', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        // Récupérer les réservations avec leurs utilisateurs (si ils existent encore)
        $reservationsOnMyWikis = [];
        if (!empty($reservationIds)) {
            $ids = array_column($reservationIds, 'id');
            $reservations = $em->getRepository(Agenda::class)->createQueryBuilder('a')
                ->leftJoin('a.user', 'u')
                ->addSelect('u')
                ->leftJoin('a.wikiPage', 'w')
                ->addSelect('w')
                ->leftJoin('a.slotPattern', 'sp')
                ->addSelect('sp')
                ->where('a.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('a.start', 'DESC')
                ->getQuery()
                ->getResult();

            // Filtrer et gérer les utilisateurs supprimés
            foreach ($reservations as $reservation) {
                try {
                    // Tenter d'accéder à l'utilisateur pour vérifier s'il existe
                    $reservationUser = $reservation->getUser();
                    if ($reservationUser !== null) {
                        // Vérifier que l'utilisateur existe vraiment dans la base
                        // En accédant à une propriété, on force Doctrine à charger l'entité
                        $userId = $reservationUser->getId();
                        $userExists = $em->getRepository(Utilisateurs::class)->find($userId);
                        if (!$userExists) {
                            // L'utilisateur a été supprimé, on met user à null
                            $reservation->setUser(null);
                        }
                    }
                } catch (\Doctrine\ORM\EntityNotFoundException $e) {
                    // L'utilisateur a été supprimé et Doctrine ne peut pas le charger
                    // On met user à null
                    $reservation->setUser(null);
                } catch (\Exception $e) {
                    // Autre erreur, on met user à null par sécurité
                    $reservation->setUser(null);
                }
                $reservationsOnMyWikis[] = $reservation;
            }
        }

        return $this->render('user/profile.html.twig', [
            'user' => $user,
            'profileForm' => $form,
            'deleteForm' => $deleteForm,
            'reservationsOnMyWikis' => $reservationsOnMyWikis,
        ]);
    }
}


