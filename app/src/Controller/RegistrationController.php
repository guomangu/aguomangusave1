<?php

namespace App\Controller;

use App\Entity\Utilisateurs;
use App\Form\RegistrationFormType;
use App\Security\AuthentificatorAuthenticator;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private EmailVerifier $emailVerifier)
    {
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, Security $security, EntityManagerInterface $entityManager): Response
    {
        $user = new Utilisateurs();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                /** @var string $plainPassword */
                $plainPassword = $form->get('plainPassword')->getData();

                // encode the plain password
                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

                $entityManager->persist($user);
                $entityManager->flush();

                // generate a signed url and email it to the user
                try {
                    $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user,
                        (new TemplatedEmail())
                            ->from(new Address('mailer@after.com', 'gubot'))
                            ->to((string) $user->getEmail())
                            ->subject('Please Confirm your Email')
                            ->htmlTemplate('registration/confirmation_email.html.twig')
                    );
                } catch (\Exception $e) {
                    // Si l'envoi d'email échoue, on continue quand même
                    // L'utilisateur est créé, on peut juste logger l'erreur
                }

                return $security->login($user, AuthentificatorAuthenticator::class, 'main');
            } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
                $this->addFlash('error', 'La base de données n\'est pas encore initialisée. Veuillez contacter l\'administrateur.');
                return $this->redirectToRoute('app_home');
            } catch (\Doctrine\DBAL\Exception $e) {
                // Capturer toutes les erreurs Doctrine/DBAL
                $errorCode = $e->getCode();
                $errorMessage = $e->getMessage();
                
                // Vérifier si c'est une erreur de table manquante (PostgreSQL)
                if ($errorCode === '42P01' || str_contains($errorMessage, 'does not exist') || str_contains($errorMessage, 'relation')) {
                    $this->addFlash('error', 'La base de données n\'est pas encore initialisée. Veuillez contacter l\'administrateur.');
                    return $this->redirectToRoute('app_home');
                }
                
                // Autres erreurs de base de données (contraintes, etc.)
                if (str_contains($errorMessage, 'UNIQUE') || str_contains($errorMessage, 'duplicate')) {
                    $this->addFlash('error', 'Cet email ou ce pseudo est déjà utilisé.');
                } else {
                    // Logger l'erreur pour déboguer
                    error_log('Erreur lors de la création du compte: ' . $errorMessage);
                    $this->addFlash('error', 'Une erreur est survenue lors de la création du compte. Veuillez réessayer.');
                }
                
                // On reste sur la page d'inscription pour que l'utilisateur puisse réessayer
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            } catch (\Exception $e) {
                // Autres erreurs inattendues
                error_log('Erreur inattendue lors de la création du compte: ' . $e->getMessage());
                $this->addFlash('error', 'Une erreur inattendue est survenue. Veuillez réessayer.');
                
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form,
                ]);
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            /** @var Utilisateurs $user */
            $user = $this->getUser();
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_register');
        }

        // @TODO Change the redirect on success and handle or remove the flash message in your templates
        $this->addFlash('success', 'Your email address has been verified.');

        return $this->redirectToRoute('app_register');
    }
}
