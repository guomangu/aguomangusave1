<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\EventSubscriber\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

class DatabaseExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly Environment $twig
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        
        // Vérifier si c'est une erreur de table manquante
        // PostgreSQL retourne SQLSTATE[42P01] pour les tables manquantes
        $isTableNotFound = false;
        
        if ($exception instanceof TableNotFoundException || 
            ($exception->getPrevious() instanceof TableNotFoundException)) {
            $isTableNotFound = true;
        } elseif ($exception->getCode() === '42P01' || 
                  ($exception->getPrevious() && $exception->getPrevious()->getCode() === '42P01')) {
            $isTableNotFound = true;
        } elseif (str_contains($exception->getMessage(), 'does not exist') || 
                  str_contains($exception->getMessage(), 'relation') && str_contains($exception->getMessage(), 'does not exist')) {
            $isTableNotFound = true;
        }
        
        if ($isTableNotFound) {
            
            $request = $event->getRequest();
            $path = $request->getPathInfo();
            
            // Pour la page /wiki, afficher une page vide avec un message
            if ($path === '/wiki' || str_starts_with($path, '/wiki/')) {
                try {
                    $content = $this->twig->render('wiki/empty.html.twig', [
                        'message' => 'La base de données n\'est pas encore initialisée. Veuillez contacter l\'administrateur.',
                    ]);
                    $response = new Response($content, Response::HTTP_OK);
                    $event->setResponse($response);
                    return;
                } catch (\Exception $e) {
                    // Si le template n'existe pas, on affiche un message simple
                    $response = new Response(
                        '<html><body><h1>Base de données non initialisée</h1><p>La base de données n\'est pas encore configurée. Veuillez contacter l\'administrateur.</p></body></html>',
                        Response::HTTP_OK
                    );
                    $event->setResponse($response);
                    return;
                }
            }
            
            // Pour les autres pages, rediriger vers la page d'accueil avec un message
            $session = $request->hasSession() ? $request->getSession() : null;
            if ($session) {
                $session->getFlashBag()->add('error', 'La base de données n\'est pas encore initialisée. Veuillez contacter l\'administrateur.');
            }
            
            $response = new RedirectResponse($this->router->generate('app_home'));
            $event->setResponse($response);
        }
    }
}

