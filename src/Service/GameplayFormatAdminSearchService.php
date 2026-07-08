<?php

namespace App\Service;

use App\Entity\Card;
use App\Repository\CardRepository;
use Psr\Log\LoggerInterface;

/**
 * Search backing the gameplay-format admin screen.
 *
 * `card` holds every print (millions of rows), so a plain SQL LIKE '%...%'
 * would force a full table scan. Meilisearch already indexes reference /
 * collector_number_formated_id and gameplay_format — use it, and fall back
 * to the slower SQL path only if Meilisearch is unreachable.
 */
final readonly class GameplayFormatAdminSearchService
{
    public function __construct(
        private MeilisearchService $meilisearch,
        private CardRepository $cardRepository,
        private LoggerInterface $logger,
    ) {}

    /** @return array{0: Card[], 1: int} */
    public function search(string $cardNumber, string $gameplayFormat, int $page, int $perPage): array
    {
        try {
            return $this->searchViaMeilisearch($cardNumber, $gameplayFormat, $page, $perPage);
        } catch (\Throwable $e) {
            $this->logger->warning('Gameplay-format admin search: Meilisearch unavailable, falling back to SQL', [
                'error' => $e->getMessage(),
            ]);
            return $this->cardRepository->findFilteredForGameplayFormatAdmin($cardNumber, $gameplayFormat, $page, $perPage);
        }
    }

    /** @return array{0: Card[], 1: int} */
    private function searchViaMeilisearch(string $cardNumber, string $gameplayFormat, int $page, int $perPage): array
    {
        $filter = $gameplayFormat !== ''
            ? sprintf('gameplay_format = "%s"', addslashes(strtoupper($gameplayFormat)))
            : null;
        $attrs = $cardNumber !== '' ? ['reference', 'collector_number_formated_id'] : [];

        $ids = $this->meilisearch->searchIds(
            query: $cardNumber,
            attributesToSearchOn: $attrs,
            filter: $filter,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
        );

        $total = $this->meilisearch->countIds($cardNumber, $filter, $attrs);

        return [$this->cardRepository->findByIdsWithRelations($ids), $total];
    }
}
