<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\WikiPageRepository;
use App\Repository\LocationTagRepository;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, WikiPageRepository $wikiRepo, LocationTagRepository $tagRepo): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        $results = [];
        $tagResults = [];
        if ($query !== '') {
            $qb = $wikiRepo->createQueryBuilder('w')
                ->andWhere('LOWER(w.title) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($query).'%')
                ->setMaxResults(20);

            $results = $qb->getQuery()->getResult();

            $tq = $tagRepo->createQueryBuilder('t')
                ->andWhere('LOWER(t.name) LIKE :q OR LOWER(t.description) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($query).'%')
                ->setMaxResults(20);

            $tagResults = $tq->getQuery()->getResult();
            
            // Construire la hiérarchie des parents pour chaque tag
            $tagsWithParents = [];
            foreach ($tagResults as $tag) {
                $parents = [];
                $current = $tag->getParent();
                while ($current) {
                    $parents[] = $current;
                    $current = $current->getParent();
                }
                // Inverser pour avoir du plus général au plus spécifique
                $parents = array_reverse($parents);
                $tagsWithParents[$tag->getId()] = $parents;
            }
        } else {
            $tagsWithParents = [];
        }

        return $this->render('home/index.html.twig', [
            'query' => $query,
            'results' => $results,
            'tagResults' => $tagResults,
            'tagsWithParents' => $tagsWithParents,
        ]);
    }
}
