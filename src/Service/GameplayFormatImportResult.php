<?php

namespace App\Service;

final readonly class GameplayFormatImportResult
{
    private function __construct(
        public bool $ok,
        public ?string $error = null,
        public ?string $sourceId = null,
        public ?int $version = null,
        public string $mode = 'inclusion',
        public int $totalRefs = 0,
        /** @var int[] */
        public array $matchedCardGroupIds = [],
        /** @var string[] */
        public array $unmatchedRefs = [],
        /** @var string[] */
        public array $excludedSetCodes = [],
    ) {}

    public static function error(string $message): self
    {
        return new self(ok: false, error: $message);
    }

    /**
     * @param int[]    $matchedCardGroupIds
     * @param string[] $unmatchedRefs
     */
    public static function success(
        string $sourceId,
        int $version,
        int $totalRefs,
        array $matchedCardGroupIds,
        array $unmatchedRefs,
    ): self {
        return new self(
            ok: true,
            sourceId: $sourceId,
            version: $version,
            mode: 'inclusion',
            totalRefs: $totalRefs,
            matchedCardGroupIds: $matchedCardGroupIds,
            unmatchedRefs: $unmatchedRefs,
        );
    }

    /**
     * @param int[]    $matchedCardGroupIds
     * @param string[] $unmatchedRefs
     * @param string[] $excludedSetCodes
     */
    public static function successExclusion(
        string $sourceId,
        int $version,
        int $totalRefs,
        array $matchedCardGroupIds,
        array $unmatchedRefs,
        array $excludedSetCodes,
    ): self {
        return new self(
            ok: true,
            sourceId: $sourceId,
            version: $version,
            mode: 'exclusion',
            totalRefs: $totalRefs,
            matchedCardGroupIds: $matchedCardGroupIds,
            unmatchedRefs: $unmatchedRefs,
            excludedSetCodes: $excludedSetCodes,
        );
    }
}
