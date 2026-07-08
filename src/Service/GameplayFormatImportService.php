<?php

namespace App\Service;

use App\Repository\CardGroupRepository;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports a gameplay-format definition file (e.g. frontier.json) from a URL.
 *
 * Expected shape:
 *   { "id": "frontier", "version": 1, "included_refs": ["ALT_..._U_1234", ...] }
 */
final readonly class GameplayFormatImportService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CardRepository $cardRepository,
        private CardGroupRepository $cardGroupRepository,
        private EntityManagerInterface $em,
    ) {}

    /**
     * Fetches the URL, validates the JSON shape, and resolves included_refs
     * against known Card references. Does not write anything.
     */
    public function fetchAndValidate(string $url): GameplayFormatImportResult
    {
        if (!preg_match('#^https?://#i', $url)) {
            return GameplayFormatImportResult::error("L'URL doit commencer par http:// ou https://.");
        }

        try {
            $data = $this->httpClient->request('GET', $url, ['timeout' => 10, 'max_duration' => 15])->toArray(false);
        } catch (\Throwable $e) {
            return GameplayFormatImportResult::error('Impossible de récupérer le fichier : ' . $e->getMessage());
        }

        $errors = $this->validateShape($data);
        if (!empty($errors)) {
            return GameplayFormatImportResult::error(implode(' ', $errors));
        }

        $references = array_values(array_unique($data['included_refs']));
        [$map, $unmatched] = $this->cardRepository->resolveCardGroupIdsByReferences($references);

        return GameplayFormatImportResult::success(
            sourceId: (string) $data['id'],
            version: (int) $data['version'],
            totalRefs: count($references),
            matchedCardGroupIds: array_values(array_unique($map)),
            unmatchedRefs: $unmatched,
        );
    }

    /**
     * Adds $formatName to the gameplayFormat array of every given CardGroup (idempotent).
     *
     * @param int[] $cardGroupIds
     * @return int Number of CardGroups actually modified.
     */
    public function apply(string $formatName, array $cardGroupIds): int
    {
        $formatName = strtoupper(trim($formatName));
        if ($formatName === '' || empty($cardGroupIds)) {
            return 0;
        }

        $updated = 0;
        foreach ($this->cardGroupRepository->findBy(['id' => $cardGroupIds]) as $cardGroup) {
            $current = $cardGroup->getGameplayFormat();
            if (!in_array($formatName, $current, true)) {
                $cardGroup->setGameplayFormat([...$current, $formatName]);
                $updated++;
            }
        }
        $this->em->flush();

        return $updated;
    }

    /** @return string[] */
    private function validateShape(mixed $data): array
    {
        if (!is_array($data)) {
            return ['Le fichier ne contient pas un objet JSON valide.'];
        }

        $errors = [];

        if (empty($data['id']) || !is_string($data['id'])) {
            $errors[] = 'Champ "id" manquant ou invalide.';
        }

        if (!isset($data['version']) || !is_int($data['version'])) {
            $errors[] = 'Champ "version" manquant ou invalide.';
        }

        if (empty($data['included_refs']) || !is_array($data['included_refs'])) {
            $errors[] = 'Champ "included_refs" manquant ou vide.';
        } else {
            foreach ($data['included_refs'] as $ref) {
                if (!is_string($ref) || trim($ref) === '') {
                    $errors[] = 'Le champ "included_refs" doit contenir uniquement des chaînes non vides.';
                    break;
                }
            }
        }

        return $errors;
    }
}
