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

        $wikis = $user->getWikiPages();
        $reservations = $em->getRepository(Agenda::class)->findBy(
            ['user' => $user],
            ['start' => 'ASC']
        );

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

        return $this->render('user/profile.html.twig', [
            'user' => $user,
            'profileForm' => $form,
            'deleteForm' => $deleteForm,
        ]);
    }
}


