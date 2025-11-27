<?php

namespace App\Entity;

use App\Repository\ForumRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: ForumRepository::class)]
class Forum
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'forum', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?WikiPage $wikiPage = null;

    #[ORM\OneToOne(inversedBy: 'forum', cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(nullable: true)]
    private ?LocationTag $locationTag = null;

    #[ORM\OneToMany(mappedBy: 'forum', targetEntity: Message::class, orphanRemoval: true)]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWikiPage(): ?WikiPage
    {
        return $this->wikiPage;
    }

    public function setWikiPage(?WikiPage $wikiPage): static
    {
        $this->wikiPage = $wikiPage;

        return $this;
    }

    public function getLocationTag(): ?LocationTag
    {
        return $this->locationTag;
    }

    public function setLocationTag(?LocationTag $locationTag): static
    {
        $this->locationTag = $locationTag;

        return $this;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(Message $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setForum($this);
        }

        return $this;
    }

    public function removeMessage(Message $message): static
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getForum() === $this) {
                $message->setForum(null);
            }
        }

        return $this;
    }
}


