<?php

namespace App\Service;

final readonly class CardPatchApplyResult
{
    private function __construct(
        public string $filename,
        public string $status,
        /** @var string[] */
        public array $errors = [],
        public int $rowsUpdated = 0,
        public int $rowsSkipped = 0,
        /** @var array<string, string[]> wildcard pattern => matched references */
        public array $wildcardMatches = [],
    ) {}

    public static function alreadyApplied(string $filename): self
    {
        return new self($filename, 'already_applied');
    }

    /** @param string[] $errors */
    public static function invalid(string $filename, array $errors): self
    {
        return new self($filename, 'invalid', errors: $errors);
    }

    /** @param array<string, string[]> $wildcardMatches */
    public static function applied(
        string $filename,
        int $rowsUpdated,
        int $rowsSkipped,
        array $wildcardMatches,
    ): self {
        return new self(
            $filename,
            'applied',
            rowsUpdated: $rowsUpdated,
            rowsSkipped: $rowsSkipped,
            wildcardMatches: $wildcardMatches,
        );
    }
}
