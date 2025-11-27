<?php

namespace App\EventSubscriber;

use Symfony\Component\EventSubscriber\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Bundle\SecurityBundle\Security;

class AdminAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RouterInterface $router
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Vérifier si la requête est pour l'admin
        if (str_starts_with($path, '/admin')) {
            $user = $this->security->getUser();
            
            // Seul guillaume@gmail.com peut accéder
            if (!$user || $user->getUserIdentifier() !== 'guillaume@gmail.com') {
                // Créer la réponse de redirection
                $redirectResponse = new RedirectResponse($this->router->generate('app_home'));
                
                // Ajouter un message flash si la session existe
                if ($request->hasSession()) {
                    $session = $request->getSession();
                    $session->getFlashBag()->add('error', 'Accès refusé. Seul l\'administrateur peut accéder à cette page.');
                }
                
                $event->setResponse($redirectResponse);
            }
        }
    }
}

