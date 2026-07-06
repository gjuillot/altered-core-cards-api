<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Maps PHP string[] to a native PostgreSQL TEXT[] column.
 * DBAL has no built-in Postgres array type — without this, ORM persistence
 * (fixtures, builders) falls back to JSON-encoding, which Postgres rejects
 * as a malformed array literal.
 */
final class TextArrayType extends Type
{
    public const NAME = 'text_array';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TEXT[]';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        $escaped = array_map(
            static fn (string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"',
            $value,
        );

        return '{' . implode(',', $escaped) . '}';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        if ($value === null || $value === '' || $value === '{}') {
            return [];
        }

        $trimmed = substr((string) $value, 1, -1);
        if ($trimmed === '') {
            return [];
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $trimmed, $matches, PREG_SET_ORDER);

        $result = [];
        foreach ($matches as $match) {
            $result[] = $match[1] !== ''
                ? str_replace(['\\"', '\\\\'], ['"', '\\'], $match[1])
                : $match[2];
        }

        return $result;
    }
}
