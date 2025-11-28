<?php

namespace App\Entity;

use App\Repository\AgendaRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgendaRepository::class)]
class Agenda
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $start = null;

    #[ORM\Column(name: '"end"', type: 'datetime')]
    private ?\DateTimeInterface $end = null;

    #[ORM\ManyToOne(inversedBy: 'agendas')]
    #[ORM\JoinColumn(nullable: false)]
    private ?WikiPage $wikiPage = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?AgendaSlotPattern $slotPattern = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateurs $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getStart(): ?\DateTimeInterface
    {
        return $this->start;
    }

    public function setStart(\DateTimeInterface $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?\DateTimeInterface
    {
        return $this->end;
    }

    public function setEnd(\DateTimeInterface $end): static
    {
        $this->end = $end;

        return $this;
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

    public function getSlotPattern(): ?AgendaSlotPattern
    {
        return $this->slotPattern;
    }

    public function setSlotPattern(?AgendaSlotPattern $slotPattern): static
    {
        $this->slotPattern = $slotPattern;

        return $this;
    }

    public function getUser(): ?Utilisateurs
    {
        return $this->user;
    }

    public function setUser(?Utilisateurs $user): static
    {
        $this->user = $user;

        return $this;
    }
}


