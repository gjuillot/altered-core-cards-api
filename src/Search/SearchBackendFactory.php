<?php

namespace App\Search;

final class SearchBackendFactory
{
    public static function create(string $url, MeilisearchBackend $meilisearch, NullSearchBackend $null): SearchBackendInterface
    {
        return $url !== '' ? $meilisearch : $null;
    }
}
