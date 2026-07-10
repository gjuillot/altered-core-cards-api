<?php

namespace App\Service;

use App\Repository\CardGroupRepository;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Imports a gameplay-format definition file (e.g. frontier.json, living-legend.json) from a URL.
 *
 * Two manifest shapes are supported:
 *   - allowlist:  { "id": "frontier", "version": 1, "included_refs": ["ALT_..._U_1234", ...] }
 *   - ban-list:   { "id": "living-legend", "version": 1, "excluded_sets": ["CORE", ...], "excluded_refs": ["ALT_..._U_1234", ...] }
 *
 * The shape is auto-detected from which keys are present in the JSON.
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

        if (isset($data['included_refs'])) {
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

        $excludedSetCodes = array_values(array_unique($data['excluded_sets'] ?? []));
        $excludedRefs     = array_values(array_unique($data['excluded_refs'] ?? []));
        [$matchedCardGroupIds, $unmatched] = $this->cardRepository->resolveCardGroupIdsExcluding($excludedSetCodes, $excludedRefs);

        return GameplayFormatImportResult::successExclusion(
            sourceId: (string) $data['id'],
            version: (int) $data['version'],
            totalRefs: count($excludedRefs),
            matchedCardGroupIds: $matchedCardGroupIds,
            unmatchedRefs: $unmatched,
            excludedSetCodes: $excludedSetCodes,
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

        $hasIncludedRefs = array_key_exists('included_refs', $data);
        $hasExclusionKeys = array_key_exists('excluded_sets', $data) || array_key_exists('excluded_refs', $data);

        if (!$hasIncludedRefs && !$hasExclusionKeys) {
            $errors[] = 'Le fichier doit contenir soit "included_refs", soit "excluded_sets"/"excluded_refs".';
            return $errors;
        }

        if ($hasIncludedRefs) {
            $errors = [...$errors, ...$this->validateStringArray($data['included_refs'], 'included_refs', allowEmpty: false)];
        } else {
            if (empty($data['excluded_sets']) && empty($data['excluded_refs'])) {
                $errors[] = 'Au moins un des champs "excluded_sets" ou "excluded_refs" doit être non vide.';
            }
            if (array_key_exists('excluded_sets', $data)) {
                $errors = [...$errors, ...$this->validateStringArray($data['excluded_sets'], 'excluded_sets', allowEmpty: true)];
            }
            if (array_key_exists('excluded_refs', $data)) {
                $errors = [...$errors, ...$this->validateStringArray($data['excluded_refs'], 'excluded_refs', allowEmpty: true)];
            }
        }

        return $errors;
    }

    /** @return string[] */
    private function validateStringArray(mixed $value, string $field, bool $allowEmpty): array
    {
        if (!is_array($value) || (!$allowEmpty && empty($value))) {
            return [sprintf('Champ "%s" manquant ou invalide.', $field)];
        }

        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return [sprintf('Le champ "%s" doit contenir uniquement des chaînes non vides.', $field)];
            }
        }

        return [];
    }
}
