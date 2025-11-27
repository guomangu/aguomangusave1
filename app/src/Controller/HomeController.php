<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\WikiPageRepository;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(Request $request, WikiPageRepository $wikiRepo): Response
    {
        $query = trim((string) $request->query->get('q', ''));

        $results = [];
        if ($query !== '') {
            $qb = $wikiRepo->createQueryBuilder('w')
                ->andWhere('LOWER(w.title) LIKE :q')
                ->setParameter('q', '%'.mb_strtolower($query).'%')
                ->setMaxResults(20);

            $results = $qb->getQuery()->getResult();
        }

        return $this->render('home/index.html.twig', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
