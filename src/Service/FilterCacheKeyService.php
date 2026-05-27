<?php

namespace App\Service;

final class FilterCacheKeyService
{
    private const SKIP = ['page', 'itemsPerPage', 'pagination', 'order'];

    public function make(string $class, array $filters): string
    {
        foreach (self::SKIP as $k) {
            unset($filters[$k]);
        }
        $this->sort($filters);
        return 'count_' . md5($class . serialize($filters));
    }

    private function sort(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$v) {
            if (!is_array($v)) {
                continue;
            }
            array_is_list($v) ? sort($v) : $this->sort($v);
        }
    }
}
