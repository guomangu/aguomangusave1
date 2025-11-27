<?php

namespace App\Service;

use App\Entity\LocationTag;
use App\Entity\WikiPage;
use App\Repository\LocationTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Csv\Reader;
use Symfony\Component\HttpKernel\KernelInterface;

class LocationTagService
{
    public function __construct(
        private readonly LocationTagRepository $locationTagRepository,
        private readonly EntityManagerInterface $em,
        private readonly KernelInterface $kernel,
    ) {
    }

    private function getCsvPath(): string
    {
        // Nouveau CSV: app/datapi/adresses-with-ids-68.csv
        return $this->kernel->getProjectDir().'/datapi/adresses-with-ids-68.csv';
    }

    /**
     * Recherche des adresses correspondant à une chaîne,
     * et renvoie quelques propositions (numéro + voie + commune).
     *
     * @return array<int, array<string, string>>
     */
    public function searchPlaces(string $query, int $limit = 20): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $csv = Reader::createFromPath($this->getCsvPath(), 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

        $results = [];

        foreach ($csv->getRecords() as $record) {
            /** @var array<string,string> $record */
            $numero   = mb_strtolower($record['numero'] ?? '');
            $voie     = mb_strtolower($record['nom_voie'] ?? '');
            $commune  = mb_strtolower($record['nom_commune'] ?? '');
            $cp       = mb_strtolower($record['code_postal'] ?? '');

            $haystack = trim($numero.' '.$voie.' '.$commune.' '.$cp);

            if ($haystack === '') {
                continue;
            }

            if (str_contains($haystack, $query)) {
                $results[] = [
                    'id' => $record['id'] ?? '',
                    'numero' => $record['numero'] ?? '',
                    'nom_voie' => $record['nom_voie'] ?? '',
                    'nom_commune' => $record['nom_commune'] ?? '',
                    'code_postal' => $record['code_postal'] ?? '',
                    'code_insee' => $record['code_insee'] ?? '',
                ];
            }

            if (\count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Retrouve un enregistrement CSV par son identifiant externe (colonne "id").
     *
     * @return array<string,string>|null
     */
    public function findRecordByExternalId(string $externalId): ?array
    {
        $csv = Reader::createFromPath($this->getCsvPath(), 'r');
        $csv->setDelimiter(';');
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $record) {
            /** @var array<string,string> $record */
            if (($record['id'] ?? '') === $externalId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * À partir d'une ligne CSV (id, nom_lieu_dit, nom_commune, ...),
     * crée (ou retrouve) les tags de localisation hiérarchiques
     * et les associe au wiki.
     *
     * Avec le CSV des adresses 68:
     * - Niveau 0 : France
     * - Niveau 1 : Grand Est
     * - Niveau 2 : commune
     * - Niveau 3 : voie
     * - Niveau 4 : numéro (si présent)
     *
     * @param array<string,string> $record
     * @return LocationTag[] tags créés / retrouvés
     */
    public function attachLocationTagsToWiki(WikiPage $wikiPage, array $record): array
    {
        $tags = [];

        $communeName = $record['nom_commune'] ?? null;
        $voieName    = $record['nom_voie'] ?? null;
        $numero      = $record['numero'] ?? null;
        $codePostal  = $record['code_postal'] ?? null;
        $codeInsee   = $record['code_insee'] ?? null;
        $externalId  = $record['id'] ?? null;

        // Niveau 0 : France
        $franceTag = $this->locationTagRepository->findOneBy([
            'name' => 'France',
            'type' => 'pays',
        ]);
        if (!$franceTag) {
            $franceTag = new LocationTag();
            $franceTag->setName('France')->setType('pays');
            $this->em->persist($franceTag);
        }
        $wikiPage->addLocationTag($franceTag);
        $tags[] = $franceTag;

        // Niveau 1 : Grand Est (donnée implicite pour le 68)
        $regionTag = $this->locationTagRepository->findOneBy([
            'name' => 'Grand Est',
            'type' => 'region',
            'parent' => $franceTag,
        ]);
        if (!$regionTag) {
            $regionTag = new LocationTag();
            $regionTag->setName('Grand Est')
                ->setType('region')
                ->setParent($franceTag);
            $this->em->persist($regionTag);
        }
        $wikiPage->addLocationTag($regionTag);
        $tags[] = $regionTag;

        // Niveau 2 : commune
        if ($communeName) {
            $communeTag = $this->locationTagRepository->findOneBy([
                'name' => $communeName,
                'type' => 'commune',
                'parent' => $regionTag,
            ]);

            if (!$communeTag) {
                $communeTag = new LocationTag();
                $communeTag->setName($communeName)
                    ->setType('commune')
                    ->setParent($regionTag)
                    ->setCodePostal($codePostal)
                    ->setCodeInsee($codeInsee);

                $this->em->persist($communeTag);
            }

            $wikiPage->addLocationTag($communeTag);
            $tags[] = $communeTag;

            // Niveau 3 : voie
            if ($voieName) {
                $voieTag = $this->locationTagRepository->findOneBy([
                    'name' => $voieName,
                    'type' => 'voie',
                    'parent' => $communeTag,
                ]);

                if (!$voieTag) {
                    $voieTag = new LocationTag();
                    $voieTag->setName($voieName)
                        ->setType('voie')
                        ->setParent($communeTag)
                        ->setCodePostal($codePostal)
                        ->setCodeInsee($codeInsee);

                    $this->em->persist($voieTag);
                }

                $wikiPage->addLocationTag($voieTag);
                $tags[] = $voieTag;

                // Niveau 4 : numéro
                if ($numero !== null && $numero !== '') {
                    $numeroTag = $this->locationTagRepository->findOneBy([
                        'name' => $numero,
                        'type' => 'numero',
                        'parent' => $voieTag,
                    ]);

                    if (!$numeroTag) {
                        $numeroTag = new LocationTag();
                        $numeroTag->setName($numero)
                            ->setType('numero')
                            ->setParent($voieTag)
                            ->setCodePostal($codePostal)
                            ->setCodeInsee($codeInsee)
                            ->setExternalId($externalId);

                        $this->em->persist($numeroTag);
                    }

                    $wikiPage->addLocationTag($numeroTag);
                    $tags[] = $numeroTag;
                }
            }
        }

        $this->em->flush();

        return $tags;
    }
}


