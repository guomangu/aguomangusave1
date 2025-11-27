<?php

namespace App\Controller;

use App\Entity\Utilisateurs;
use App\Entity\WikiPage;
use App\Entity\Agenda;
use App\Form\UserType;
use App\Form\WikiPageType;
use App\Repository\UtilisateursRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    #[Route('/u/{email}', name: 'app_user_public', methods: ['GET', 'POST'])]
    public function publicPage(
        string $email,
        UtilisateursRepository $userRepository,
        Request $request,
        EntityManagerInterface $em
    ): Response
    {
        $user = $userRepository->findOneBy(['email' => $email]);

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

            return $this->redirectToRoute('app_user_public', ['email' => $user->getEmail()]);
        }

        $wikis = $user->getWikiPages();
        $reservations = $em->getRepository(Agenda::class)->findBy(
            ['user' => $user],
            ['start' => 'ASC']
        );

        return $this->render('user/public.html.twig', [
            'user' => $user,
            'wikiForm' => $form,
            'wikis' => $wikis,
            'reservations' => $reservations,
        ]);
    }
}


