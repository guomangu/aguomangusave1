<?php

namespace App\Entity;

use App\Repository\WikiPageRepository;
use App\Entity\Utilisateurs;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: WikiPageRepository::class)]
class WikiPage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    #[ORM\ManyToOne(inversedBy: 'wikiPages')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Utilisateurs $owner = null;

    #[ORM\ManyToOne(targetEntity: WikiPage::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true)]
    private ?WikiPage $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: WikiPage::class)]
    private Collection $children;

    #[ORM\OneToMany(mappedBy: 'wikiPage', targetEntity: Article::class, orphanRemoval: true)]
    private Collection $articles;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->articles = new ArrayCollection();
    }

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

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getOwner(): ?Utilisateurs
    {
        return $this->owner;
    }

    public function setOwner(?Utilisateurs $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getParent(): ?WikiPage
    {
        return $this->parent;
    }

    public function setParent(?WikiPage $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /**
     * @return Collection<int, WikiPage>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(WikiPage $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(WikiPage $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Article>
     */
    public function getArticles(): Collection
    {
        return $this->articles;
    }

    public function addArticle(Article $article): static
    {
        if (!$this->articles->contains($article)) {
            $this->articles->add($article);
            $article->setWikiPage($this);
        }

        return $this;
    }

    public function removeArticle(Article $article): static
    {
        if ($this->articles->removeElement($article)) {
            // set the owning side to null (unless already changed)
            if ($article->getWikiPage() === $this) {
                $article->setWikiPage(null);
            }
        }

        return $this;
    }
}
