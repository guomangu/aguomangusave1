<?php

namespace App\Entity;

use App\Repository\UtilisateursRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UtilisateursRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_PSEUDO', fields: ['pseudo'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
#[UniqueEntity(fields: ['pseudo'], message: 'Ce pseudo est déjà utilisé')]
class Utilisateurs implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $pseudo = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private bool $isVerified = false;

    #[ORM\OneToMany(mappedBy: 'owner', targetEntity: WikiPage::class)]
    private Collection $wikiPages;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Agenda::class)]
    private Collection $reservations;

    public function __construct()
    {
        $this->wikiPages = new ArrayCollection();
        $this->reservations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPseudo(): ?string
    {
        return $this->pseudo;
    }

    public function setPseudo(?string $pseudo): static
    {
        $this->pseudo = $pseudo;

        return $this;
    }

    /**
     * Retourne le pseudo ou l'email si le pseudo n'est pas défini
     */
    public function getDisplayName(): string
    {
        return $this->pseudo ?: $this->email;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0" . self::class . "\0password"] = hash('crc32c', $this->password);
        
        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, to be removed when upgrading to Symfony 8
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    /**
     * @return Collection<int, WikiPage>
     */
    public function getWikiPages(): Collection
    {
        return $this->wikiPages;
    }

    public function addWikiPage(WikiPage $wikiPage): static
    {
        if (!$this->wikiPages->contains($wikiPage)) {
            $this->wikiPages->add($wikiPage);
            $wikiPage->setOwner($this);
        }

        return $this;
    }

    public function removeWikiPage(WikiPage $wikiPage): static
    {
        if ($this->wikiPages->removeElement($wikiPage)) {
            if ($wikiPage->getOwner() === $this) {
                $wikiPage->setOwner(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Agenda>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Agenda $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setUser($this);
        }

        return $this;
    }

    public function removeReservation(Agenda $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            if ($reservation->getUser() === $this) {
                $reservation->setUser(null);
            }
        }

        return $this;
    }

    /**
     * Statistiques d'utilisation du site
     * Ces méthodes calculent les stats à partir des relations existantes
     */

    /**
     * Nombre de wikis créés (en tant que propriétaire)
     */
    public function getStatsWikisCreated(): int
    {
        return $this->wikiPages->count();
    }

    /**
     * Nombre d'articles créés (via les wikis dont l'utilisateur est propriétaire)
     */
    public function getStatsArticlesCreated(): int
    {
        $count = 0;
        foreach ($this->wikiPages as $wiki) {
            $count += $wiki->getArticles()->count();
        }
        return $count;
    }

    /**
     * Nombre de messages postés dans les forums
     * Note: nécessite une injection du repository Message dans le contexte d'utilisation
     */
    public function getStatsMessagesCount($messageRepository = null): int
    {
        if ($messageRepository) {
            return $messageRepository->count(['author' => $this]);
        }
        return 0; // Retourne 0 si le repository n'est pas fourni
    }

    /**
     * Nombre de notifications créées (actions déclenchées)
     * Note: nécessite une injection du repository Notification dans le contexte d'utilisation
     */
    public function getStatsNotificationsCreated($notificationRepository = null): int
    {
        if ($notificationRepository) {
            return $notificationRepository->count(['author' => $this]);
        }
        return 0; // Retourne 0 si le repository n'est pas fourni
    }

    /**
     * Nombre de réservations de créneaux
     */
    public function getStatsReservationsCount(): int
    {
        return $this->reservations->count();
    }

    /**
     * Nombre total de wikis enfants créés
     */
    public function getStatsChildWikisCreated(): int
    {
        $count = 0;
        foreach ($this->wikiPages as $wiki) {
            $count += $wiki->getChildren()->count();
        }
        return $count;
    }

    /**
     * Retourne un tableau avec toutes les statistiques
     */
    public function getAllStats($messageRepository = null, $notificationRepository = null): array
    {
        return [
            'wikis_created' => $this->getStatsWikisCreated(),
            'child_wikis_created' => $this->getStatsChildWikisCreated(),
            'articles_created' => $this->getStatsArticlesCreated(),
            'messages_count' => $this->getStatsMessagesCount($messageRepository),
            'notifications_created' => $this->getStatsNotificationsCreated($notificationRepository),
            'reservations_count' => $this->getStatsReservationsCount(),
        ];
    }
}
