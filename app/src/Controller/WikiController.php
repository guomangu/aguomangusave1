<?php

namespace App\Controller;

use App\Form\WikiPageType; 
use App\Entity\WikiPage;
use App\Entity\Article;
use App\Form\ArticleType;
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

        // Formulaire pour ajouter un article enfant (réservé au propriétaire)
        $articleForm = null;
        $childForm = null;

        if ($isOwner) {
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

            // Formulaire pour ajouter un wiki enfant (réservé au propriétaire)
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
        }

        return $this->render('wiki/index.html.twig', [
            'page' => $wikiPage,
            'articleForm' => $articleForm,
            'childWikiForm' => $childForm,
            'isOwner' => $isOwner,
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
}