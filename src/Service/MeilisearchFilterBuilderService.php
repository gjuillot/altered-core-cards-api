<?php

namespace App\Service;

final class MeilisearchFilterBuilderService
{
    /** API Platform filter key → Meilisearch filterable attribute (simple 1:1 mapping) */
    public const FILTER_MAP = [
        'faction.code'  => 'faction_code',
        'set.reference' => 'set_reference',
        'rarity'        => 'rarity',
        'cardType'      => 'card_type',
        'subTypes'      => 'sub_types',
        'mainCost'      => 'main_cost',
        'recallCost'    => 'recall_cost',
        'isSerialized'  => 'is_serialized',
        'promo'         => 'promo',
        'kickstarter'   => 'kickstarter',
        'transfuge'     => 'transfuge',
        'isBanned'      => 'is_banned',
        'isSuspended'   => 'is_suspended',
        'isErrated'     => 'is_errated',
        'variation'     => 'variation',
    ];

    /** Filters handled by buildEffectFilters() — not in FILTER_MAP but still mappable */
    public const EFFECT_FILTER_KEYS = [
        'effectTriggerType', 'effectKeyword', 'effectKeywordMode',
        'effectSlot', 'effectSlotMode', 'hasNoEffect', 'minSameTriggerCount',
        'echoSlot', 'echoSlotMode', 'hasEchoEffect',
    ];

    private const SLOT_NAMES = ['slot1', 'slot2', 'slot3', 'echo'];

    public function hasUnmappedFilters(array $filters): bool
    {
        static $skip = ['name', 'page', 'itemsPerPage', 'pagination', 'order'];
        foreach (array_keys($filters) as $key) {
            if (
                !isset(self::FILTER_MAP[$key])
                && !in_array($key, $skip, true)
                && !in_array($key, self::EFFECT_FILTER_KEYS, true)
            ) {
                return true;
            }
        }
        return false;
    }

    public function buildFilter(array $filters): ?string
    {
        $parts = [];

        foreach ($filters as $key => $value) {
            if ($key === 'name') {
                continue;
            }

            $field = self::FILTER_MAP[$key] ?? null;
            if ($field === null || $value === null || $value === '') {
                continue;
            }

            if (is_array($value) && !array_is_list($value)) {
                static $rangeOps = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
                foreach ($value as $op => $val) {
                    $mOp = $rangeOps[$op] ?? null;
                    if ($mOp !== null && $val !== '' && $val !== null) {
                        $parts[] = sprintf('%s %s %s', $field, $mOp, $this->meiliValue($field, (string) $val));
                    }
                }
                continue;
            }

            $values = array_values(array_filter((array) $value, fn($v) => $v !== '' && $v !== null));
            if (empty($values)) {
                continue;
            }

            if (count($values) === 1) {
                $parts[] = sprintf('%s = %s', $field, $this->meiliValue($field, (string) $values[0]));
            } else {
                $quoted  = array_map(fn($v) => $this->meiliValue($field, (string) $v), $values);
                $parts[] = sprintf('%s IN [%s]', $field, implode(', ', $quoted));
            }
        }

        $effectParts = $this->buildEffectFilters($filters);
        if ($effectParts !== null) {
            $parts[] = $effectParts;
        }

        return !empty($parts) ? implode(' AND ', $parts) : null;
    }

    private function buildEffectFilters(array $filters): ?string
    {
        $parts = [];

        if (isset($filters['hasNoEffect']) && $filters['hasNoEffect'] !== '') {
            $noEffect = in_array(strtolower((string) $filters['hasNoEffect']), ['true', '1'], true);
            $parts[]  = 'has_effect = ' . ($noEffect ? 'false' : 'true');
        }

        if (isset($filters['minSameTriggerCount']) && $filters['minSameTriggerCount'] !== '') {
            $n       = max(1, (int) $filters['minSameTriggerCount']);
            $parts[] = "trigger_repeat_count >= $n";
        }

        if (isset($filters['effectTriggerType']) && $filters['effectTriggerType'] !== '') {
            $ids = array_values(array_filter(array_map('intval', (array) $filters['effectTriggerType'])));
            if (!empty($ids)) {
                $parts[] = $this->buildTriggerFilter($ids);
            }
        }

        if (isset($filters['effectKeyword'])) {
            $kws  = array_values(array_filter((array) $filters['effectKeyword'], fn($v) => $v !== '' && $v !== null));
            $mode = strtolower((string) ($filters['effectKeywordMode'] ?? 'or'));
            if (!empty($kws)) {
                $kwParts = array_map(fn($kw) => sprintf('keywords = "%s"', addslashes((string) $kw)), $kws);
                $parts[] = '(' . implode($mode === 'and' ? ' AND ' : ' OR ', $kwParts) . ')';
            }
        }

        if (isset($filters['effectSlot']) && is_array($filters['effectSlot'])) {
            $slotFilter = $this->buildEffectSlotFilter(
                $filters['effectSlot'],
                (string) ($filters['effectSlotMode'] ?? 'or'),
            );
            if ($slotFilter !== null) {
                $parts[] = $slotFilter;
            }
        }

        if (isset($filters['hasEchoEffect']) && $filters['hasEchoEffect'] !== '') {
            $hasEcho = in_array(strtolower((string) $filters['hasEchoEffect']), ['true', '1'], true);
            if ($hasEcho) {
                $parts[] = '(echo_trigger IS NOT NULL OR echo_condition IS NOT NULL OR echo_effect IS NOT NULL)';
            } else {
                $parts[] = '(echo_trigger IS NULL AND echo_condition IS NULL AND echo_effect IS NULL)';
            }
        }

        if (isset($filters['echoSlot']) && is_array($filters['echoSlot'])) {
            $echoFilter = $this->buildEchoSlotFilter(
                $filters['echoSlot'],
                (string) ($filters['echoSlotMode'] ?? 'or'),
            );
            if ($echoFilter !== null) {
                $parts[] = $echoFilter;
            }
        }

        return !empty($parts) ? implode(' AND ', $parts) : null;
    }

    private function buildTriggerFilter(array $alteredIds): string
    {
        $conditions = [];
        foreach (self::SLOT_NAMES as $slot) {
            foreach ($alteredIds as $id) {
                $conditions[] = "{$slot}_trigger = $id";
            }
        }
        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function buildEffectSlotFilter(array $slots, string $mode): ?string
    {
        $specFilters = [];

        foreach ($slots as $slotDef) {
            if (!is_array($slotDef)) {
                continue;
            }

            $trigger   = isset($slotDef['trigger'])   && $slotDef['trigger']   !== '' ? (int) $slotDef['trigger']   : null;
            $condition = isset($slotDef['condition'])  && $slotDef['condition']  !== '' ? (int) $slotDef['condition']  : null;
            $effect    = isset($slotDef['effect'])     && $slotDef['effect']     !== '' ? (int) $slotDef['effect']     : null;

            if ($trigger === null && $condition === null && $effect === null) {
                continue;
            }

            $perSlot = [];
            foreach (self::SLOT_NAMES as $slotName) {
                $slotConds = [];
                if ($trigger   !== null) $slotConds[] = "{$slotName}_trigger = $trigger";
                if ($condition !== null) $slotConds[] = "{$slotName}_condition = $condition";
                if ($effect    !== null) $slotConds[] = "{$slotName}_effect = $effect";

                if (!empty($slotConds)) {
                    $perSlot[] = '(' . implode(' AND ', $slotConds) . ')';
                }
            }

            if (!empty($perSlot)) {
                $specFilters[] = '(' . implode(' OR ', $perSlot) . ')';
            }
        }

        if (empty($specFilters)) {
            return null;
        }

        $glue = strtolower($mode) === 'and' ? ' AND ' : ' OR ';
        return '(' . implode($glue, $specFilters) . ')';
    }

    private function buildEchoSlotFilter(array $slots, string $mode): ?string
    {
        $specFilters = [];

        foreach ($slots as $slotDef) {
            if (!is_array($slotDef)) {
                continue;
            }

            $trigger   = isset($slotDef['trigger'])   && $slotDef['trigger']   !== '' ? (int) $slotDef['trigger']   : null;
            $condition = isset($slotDef['condition'])  && $slotDef['condition']  !== '' ? (int) $slotDef['condition']  : null;
            $effect    = isset($slotDef['effect'])     && $slotDef['effect']     !== '' ? (int) $slotDef['effect']     : null;

            if ($trigger === null && $condition === null && $effect === null) {
                continue;
            }

            $conds = [];
            if ($trigger   !== null) $conds[] = "echo_trigger = $trigger";
            if ($condition !== null) $conds[] = "echo_condition = $condition";
            if ($effect    !== null) $conds[] = "echo_effect = $effect";

            $specFilters[] = '(' . implode(' AND ', $conds) . ')';
        }

        if (empty($specFilters)) {
            return null;
        }

        $glue = strtolower($mode) === 'and' ? ' AND ' : ' OR ';
        return '(' . implode($glue, $specFilters) . ')';
    }

    private function meiliValue(string $field, string $value): string
    {
        static $numeric = ['main_cost', 'recall_cost', 'ocean_power', 'mountain_power', 'forest_power'];
        static $bool    = ['is_serialized', 'is_banned', 'is_suspended', 'is_errated', 'promo', 'kickstarter', 'transfuge'];

        if (in_array($field, $numeric, true) && is_numeric($value)) {
            return $value;
        }
        if (in_array($field, $bool, true)) {
            return in_array(strtolower($value), ['true', '1'], true) ? 'true' : 'false';
        }
        return sprintf('"%s"', addslashes($value));
    }
}
