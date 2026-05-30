<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:import:abilities:equinox',
    description: 'Pre-populate ability_trigger/condition/effect and main_effect from Equinox JSON files',
)]
class ImportEquinoxAbilitiesCommand extends Command
{
    private const LOCALE_MAP = [
        'fr_FR' => 'fr',
        'en_US' => 'en',
        'de_DE' => 'de',
        'es_ES' => 'es',
        'it_IT' => 'it',
    ];

    private const BATCH_SIZE = 500;

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Root directory of Equinox JSON files', 'datas/equinox')
            ->addOption('set', null, InputOption::VALUE_OPTIONAL, 'Filter by set (e.g. BISE)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Dump JSON vs DB text comparison without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');
        $setFilter = $input->getOption('set');
        $dryRun    = $input->getOption('dry-run');

        $io->title('Import abilities from Equinox JSON files');

        if (!is_dir($directory)) {
            $io->error("Directory not found: $directory");
            return Command::FAILURE;
        }

        // ── 1. Parse JSON files ───────────────────────────────────────────────

        $io->section('Parsing JSON files…');

        $finder = new Finder();
        $finder->files()->name('*.json')->in($directory);
        if ($setFilter) {
            $finder->path(sprintf('/^%s\//', preg_quote($setFilter, '/')));
        }

        /**
         * $parts['trigger'|'condition'|'effect'][idGd] = ['fr'=>.., 'en'=>.., ..]
         * $effects[abilityKey] = [
         *   'fr'=>.., 'en'=>.., 'de'=>.., 'es'=>.., 'it'=>..,
         *   'trigger_idgd'=>int, 'condition_idgd'=>int, 'effect_idgd'=>int,
         * ]
         */
        $parts   = ['trigger' => [], 'condition' => [], 'effect' => []];
        $effects = [];

        $fileCount = 0;
        foreach ($finder as $file) {
            try {
                $data = json_decode($file->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }
            $fileCount++;

            foreach ($data['cardElements'] ?? [] as $element) {
                $elementType = $element['cardElementType']['reference'] ?? null;
                if (!in_array($elementType, ['MAIN_EFFECT', 'ECHO_EFFECT'], true)) {
                    continue;
                }

                foreach ($element['cardEffectDisplays'] ?? [] as $display) {
                    $cardEffect = $display['cardEffect'] ?? null;
                    if (!$cardEffect) continue;

                    $abilityKey = $cardEffect['reference'] ?? null;
                    if (!$abilityKey) continue;

                    if (!isset($effects[$abilityKey])) {
                        $effects[$abilityKey] = [
                            'fr' => null, 'en' => null, 'de' => null, 'es' => null, 'it' => null,
                            'trigger_idgd'   => 0,
                            'condition_idgd' => 0,
                            'effect_idgd'    => 0,
                        ];
                    }

                    $elementTexts = ['fr' => [], 'en' => [], 'de' => [], 'es' => [], 'it' => []];

                    foreach ($cardEffect['cardEffectElements'] ?? [] as $el) {
                        $idGd = (int) ($el['idGd'] ?? 0);
                        $type = strtolower($el['type'] ?? '');

                        // Equinox element types vs our table naming:
                        //   TRIGGER   → ability_trigger  (position 1 in t_c_e key)
                        //   OUTPUT    → ability_effect    (position 2 in t_c_e key — the main output text)
                        //   CONDITION → ability_condition (position 3 in t_c_e key — the conditional clause)
                        $partType = match ($type) {
                            'trigger'   => 'trigger',
                            'output'    => 'effect',
                            'condition' => 'condition',
                            default     => 'effect',
                        };

                        // Use idGd from elements directly — works regardless of reference format
                        if ($idGd > 0 && $effects[$abilityKey]["{$partType}_idgd"] === 0) {
                            $effects[$abilityKey]["{$partType}_idgd"] = $idGd;
                        }

                        // Always register the part so it exists in ability tables even when
                        // this JSON file provides no translations (e.g. UNIQUE cards reference
                        // shared effects without redefining their text).
                        if ($idGd > 0 && !isset($parts[$partType][$idGd])) {
                            $parts[$partType][$idGd] = ['fr' => null, 'en' => null, 'de' => null, 'es' => null, 'it' => null];
                        }

                        foreach (self::LOCALE_MAP as $apiLocale => $col) {
                            $text = $el['translations'][$apiLocale]['text'] ?? null;
                            if ($text === null) continue;

                            $text = rtrim($text, " \t—–-");
                            if ($text === '') continue;

                            $elementTexts[$col][] = $text;

                            if ($idGd > 0) {
                                $parts[$partType][$idGd][$col] ??= $text;
                            }
                        }
                    }

                    foreach (['fr', 'en', 'de', 'es', 'it'] as $col) {
                        if ($effects[$abilityKey][$col] === null && !empty($elementTexts[$col])) {
                            $effects[$abilityKey][$col] = implode(' ', $elementTexts[$col]);
                        }
                    }
                }
            }
        }

        $io->writeln(sprintf(
            '  Parsed <info>%d</info> files → <info>%d</info> triggers, <info>%d</info> conditions, <info>%d</info> effects, <info>%d</info> main_effects',
            $fileCount,
            count($parts['trigger']),
            count($parts['condition']),
            count($parts['effect']),
            count($effects),
        ));

        // ── 2. Dry-run: dump JSON vs DB comparison ────────────────────────────

        if ($dryRun) {
            $this->dumpDryRun($io, $parts, $effects);
            return Command::SUCCESS;
        }

        // ── 3. Upsert ability parts ───────────────────────────────────────────

        $io->section('Upserting ability parts…');

        $tableMap = [
            'trigger'   => 'ability_trigger',
            'condition' => 'ability_condition',
            'effect'    => 'ability_effect',
        ];

        foreach ($tableMap as $type => $table) {
            $rows = $parts[$type];
            if (empty($rows)) continue;

            $count  = 0;
            $chunks = array_chunk($rows, self::BATCH_SIZE, preserve_keys: true);

            foreach ($chunks as $chunk) {
                $values     = [];
                $bindParams = [];
                $i          = 0;

                foreach ($chunk as $idGd => $texts) {
                    $values[] = "(:idgd_{$i}, :fr_{$i}, :en_{$i}, :de_{$i}, :es_{$i}, :it_{$i}, false)";
                    $bindParams["idgd_{$i}"] = $idGd;
                    $bindParams["fr_{$i}"]   = $texts['fr'];
                    $bindParams["en_{$i}"]   = $texts['en'];
                    $bindParams["de_{$i}"]   = $texts['de'];
                    $bindParams["es_{$i}"]   = $texts['es'];
                    $bindParams["it_{$i}"]   = $texts['it'];
                    $i++;
                }

                $this->connection->executeStatement(
                    sprintf(
                        'INSERT INTO %s (altered_id, text_fr, text_en, text_de, text_es, text_it, is_support)
                         VALUES %s
                         ON CONFLICT (altered_id) DO UPDATE SET
                             text_fr = COALESCE(EXCLUDED.text_fr, %s.text_fr),
                             text_en = COALESCE(EXCLUDED.text_en, %s.text_en),
                             text_de = COALESCE(EXCLUDED.text_de, %s.text_de),
                             text_es = COALESCE(EXCLUDED.text_es, %s.text_es),
                             text_it = COALESCE(EXCLUDED.text_it, %s.text_it)',
                        $table,
                        implode(', ', $values),
                        $table, $table, $table, $table, $table,
                    ),
                    $bindParams,
                );

                $count += count($chunk);
            }

            $io->writeln(sprintf('  %s: <info>%d</info> upserted', $table, $count));
        }

        // ── 3. Load alteredId → internal id maps + texts ─────────────────────

        $io->section('Loading ability ID maps…');

        $idMaps    = [];
        $textCache = []; // type → altered_id → {fr,en,de,es,it}
        foreach ($tableMap as $type => $table) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, altered_id, text_fr, text_en, text_de, text_es, text_it FROM {$table}"
            );
            foreach ($rows as $row) {
                $idMaps[$type][$row['altered_id']] = $row['id'];
                $textCache[$type][$row['altered_id']] = [
                    'fr' => $row['text_fr'],
                    'en' => $row['text_en'],
                    'de' => $row['text_de'],
                    'es' => $row['text_es'],
                    'it' => $row['text_it'],
                ];
            }
        }

        // ── 4. Upsert main_effect ─────────────────────────────────────────────

        $io->section('Upserting main_effect…');

        // Load existing ability_key → id map (first occurrence wins for duplicates)
        $existingRows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT ON (ability_key) id, ability_key FROM main_effect WHERE ability_key IS NOT NULL ORDER BY ability_key, id'
        );
        $existingMap = array_column($existingRows, 'id', 'ability_key');

        $toInsert    = [];
        $toUpdate    = []; // [['id'=>int, 'tid'=>?int, 'cid'=>?int, 'eid'=>?int], ...]
        $seenTexts   = []; // text fingerprint → true, to deduplicate within $toInsert

        foreach ($effects as $abilityKey => $data) {
            $tId = $data['trigger_idgd'] > 0   ? ($idMaps['trigger'][$data['trigger_idgd']]     ?? null) : null;
            $cId = $data['condition_idgd'] > 0 ? ($idMaps['condition'][$data['condition_idgd']] ?? null) : null;
            $eId = $data['effect_idgd'] > 0    ? ($idMaps['effect'][$data['effect_idgd']]       ?? null) : null;

            // When the JSON carries no element texts (e.g. serialized UNIQUE cards),
            // fall back to concatenating texts from the ability component tables.
            $hasText = ($data['en'] ?? '') !== '' || ($data['fr'] ?? '') !== '';
            if (!$hasText) {
                foreach (['fr', 'en', 'de', 'es', 'it'] as $col) {
                    $parts = array_filter([
                        $textCache['trigger'][$data['trigger_idgd']][$col]     ?? null,
                        $textCache['condition'][$data['condition_idgd']][$col] ?? null,
                        $textCache['effect'][$data['effect_idgd']][$col]       ?? null,
                    ]);
                    if ($parts) {
                        $data[$col] = implode(' ', $parts);
                    }
                }
            }

            if (isset($existingMap[$abilityKey])) {
                if ($tId !== null || $cId !== null || $eId !== null) {
                    $toUpdate[] = ['id' => $existingMap[$abilityKey], 'tid' => $tId, 'cid' => $cId, 'eid' => $eId];
                }
            } else {
                // Two different abilityKeys can share identical texts — only insert the first
                $textKey = ($data['fr'] ?? '') . '|' . ($data['en'] ?? '') . '|' . ($data['de'] ?? '') . '|' . ($data['es'] ?? '') . '|' . ($data['it'] ?? '');
                if (!isset($seenTexts[$textKey])) {
                    $seenTexts[$textKey]     = true;
                    $toInsert[$abilityKey]   = compact('data', 'tId', 'cId', 'eId');
                }
            }
        }

        // INSERT new effects
        $inserted = 0;
        foreach (array_chunk($toInsert, self::BATCH_SIZE, preserve_keys: true) as $chunk) {
            $values     = [];
            $bindParams = [];
            $i          = 0;

            foreach ($chunk as $abilityKey => $row) {
                $values[] = "(:ak_{$i}, :fr_{$i}, :en_{$i}, :de_{$i}, :es_{$i}, :it_{$i}, :tid_{$i}, :cid_{$i}, :eid_{$i})";
                $bindParams["ak_{$i}"]  = $abilityKey;
                $bindParams["fr_{$i}"]  = $row['data']['fr'];
                $bindParams["en_{$i}"]  = $row['data']['en'];
                $bindParams["de_{$i}"]  = $row['data']['de'];
                $bindParams["es_{$i}"]  = $row['data']['es'];
                $bindParams["it_{$i}"]  = $row['data']['it'];
                $bindParams["tid_{$i}"] = $row['tId'];
                $bindParams["cid_{$i}"] = $row['cId'];
                $bindParams["eid_{$i}"] = $row['eId'];
                $i++;
            }

            $sql = 'INSERT INTO main_effect
                         (ability_key, text_fr, text_en, text_de, text_es, text_it,
                          ability_trigger_id, ability_condition_id, ability_effect_id)
                     VALUES ' . implode(', ', $values) . "
                     ON CONFLICT (COALESCE(text_fr,''), COALESCE(text_en,''), COALESCE(text_de,''), COALESCE(text_es,''), COALESCE(text_it,''))
                     DO UPDATE SET
                         ability_key          = COALESCE(EXCLUDED.ability_key,          main_effect.ability_key),
                         ability_trigger_id   = COALESCE(EXCLUDED.ability_trigger_id,   main_effect.ability_trigger_id),
                         ability_condition_id = COALESCE(EXCLUDED.ability_condition_id, main_effect.ability_condition_id),
                         ability_effect_id    = COALESCE(EXCLUDED.ability_effect_id,    main_effect.ability_effect_id)";
            $this->connection->executeStatement($sql, $bindParams);

            $inserted += count($chunk);
        }

        // BACKFILL FK links on existing effects (COALESCE preserves already-set ids)
        $updated = 0;
        foreach (array_chunk($toUpdate, self::BATCH_SIZE) as $chunk) {
            $values     = [];
            $bindParams = [];
            $i          = 0;

            foreach ($chunk as $row) {
                $values[] = "(:id_{$i}::int, :tid_{$i}::int, :cid_{$i}::int, :eid_{$i}::int)";
                $bindParams["id_{$i}"]  = $row['id'];
                $bindParams["tid_{$i}"] = $row['tid'];
                $bindParams["cid_{$i}"] = $row['cid'];
                $bindParams["eid_{$i}"] = $row['eid'];
                $i++;
            }

            $this->connection->executeStatement(
                sprintf(
                    'UPDATE main_effect m
                     SET ability_trigger_id   = COALESCE(m.ability_trigger_id,   v.tid),
                         ability_condition_id = COALESCE(m.ability_condition_id, v.cid),
                         ability_effect_id    = COALESCE(m.ability_effect_id,    v.eid)
                     FROM (VALUES %s) AS v(id, tid, cid, eid)
                     WHERE m.id = v.id',
                    implode(', ', $values),
                ),
                $bindParams,
            );

            $updated += count($chunk);
        }

        $io->writeln(sprintf(
            '  main_effect: <info>%d</info> inserted, <info>%d</info> FK links backfilled',
            $inserted, $updated,
        ));

        $io->success('Done. Run app:import:equinox — effects will be pre-loaded from DB, no new inserts needed.');

        return Command::SUCCESS;
    }

    private function dumpDryRun(SymfonyStyle $io, array $parts, array $effects): void
    {
        $tableMap = [
            'trigger'   => 'ability_trigger',
            'condition' => 'ability_condition',
            'effect'    => 'ability_effect',
        ];

        // Load card_search to know which altered_ids are actually used by imported cards
        $usedTriggers   = array_flip($this->connection->fetchFirstColumn('SELECT DISTINCT t1 FROM card_search WHERE t1 IS NOT NULL UNION SELECT t2 FROM card_search WHERE t2 IS NOT NULL UNION SELECT t3 FROM card_search WHERE t3 IS NOT NULL'));
        $usedConditions = array_flip($this->connection->fetchFirstColumn('SELECT DISTINCT c1 FROM card_search WHERE c1 IS NOT NULL UNION SELECT c2 FROM card_search WHERE c2 IS NOT NULL UNION SELECT c3 FROM card_search WHERE c3 IS NOT NULL'));
        $usedEffects    = array_flip($this->connection->fetchFirstColumn('SELECT DISTINCT e1 FROM card_search WHERE e1 IS NOT NULL UNION SELECT e2 FROM card_search WHERE e2 IS NOT NULL UNION SELECT e3 FROM card_search WHERE e3 IS NOT NULL'));
        $usedByType = ['trigger' => $usedTriggers, 'condition' => $usedConditions, 'effect' => $usedEffects];

        // ── Ability parts ─────────────────────────────────────────────────────

        foreach ($tableMap as $type => $table) {
            $jsonRows = $parts[$type];
            if (empty($jsonRows)) {
                continue;
            }

            $dbRows = $this->connection->fetchAllAssociative(
                "SELECT altered_id, text_fr, text_en FROM {$table}"
            );
            $dbMap = array_column($dbRows, null, 'altered_id');

            $newCount = $diffCount = $wsOnlyCount = 0;
            $diffDetails = [];

            foreach ($jsonRows as $idGd => $json) {
                $db = $dbMap[$idGd] ?? null;
                if ($db === null) {
                    $newCount++;
                } elseif ($db['text_fr'] !== $json['fr']) {
                    if ($this->normalize($db['text_fr']) === $this->normalize($json['fr'])) {
                        $wsOnlyCount++;
                    } else {
                        $diffCount++;
                        $diffDetails[] = [$idGd, $json['fr'], $db['text_fr'], $json['en'], $db['text_en']];
                    }
                }
            }

            $io->section(sprintf(
                '%s — JSON: %d, DB: %d | <info>%d new</info> | <comment>%d ws-only diff</comment> | <fg=red>%d real diff</>',
                $table, count($jsonRows), count($dbMap), $newCount, $wsOnlyCount, $diffCount,
            ));

            // Show NEW rows — flag ones already used by cards (unexpected gap)
            $newRows = [];
            foreach ($jsonRows as $idGd => $json) {
                if (!isset($dbMap[$idGd])) {
                    $usedByCard = isset($usedByType[$type][$idGd]) ? '<fg=red>YES</>' : 'no';
                    $newRows[] = [$idGd, $usedByCard, $this->truncate($json['fr']), $this->truncate($json['en'])];
                }
            }
            if ($newRows) {
                $io->writeln('  <fg=yellow>NEW entries:</>');
                $io->table(['altered_id', 'used by card?', 'JSON fr', 'JSON en'], $newRows);
            }

            // Show real text diffs
            if ($diffDetails) {
                $io->writeln('  <fg=red>Real text diffs:</>');
                $diffRows = array_map(fn($d) => [
                    $d[0],
                    $this->truncate($d[1]), $this->truncate($d[2]),
                    $this->truncate($d[3]), $this->truncate($d[4]),
                ], $diffDetails);
                $io->table(['altered_id', 'JSON fr', 'DB fr', 'JSON en', 'DB en'], $diffRows);
            }
        }

        // ── Main effects ──────────────────────────────────────────────────────

        $dbEffects = $this->connection->fetchAllAssociative(
            'SELECT ability_key, text_fr, text_en FROM main_effect WHERE ability_key IS NOT NULL'
        );
        $dbEffectMap = array_column($dbEffects, null, 'ability_key');

        $newCount = $diffCount = $wsOnlyCount = 0;
        $diffDetails = [];

        foreach ($effects as $abilityKey => $json) {
            $db = $dbEffectMap[$abilityKey] ?? null;
            if ($db === null) {
                $newCount++;
            } elseif ($db['text_fr'] !== $json['fr']) {
                if ($this->normalize($db['text_fr']) === $this->normalize($json['fr'])) {
                    $wsOnlyCount++;
                } else {
                    $diffCount++;
                    $diffDetails[] = [$abilityKey, $json['fr'], $db['text_fr'], $json['en'], $db['text_en']];
                }
            }
        }

        $io->section(sprintf(
            'main_effect — JSON: %d, DB: %d | <info>%d new</info> | <comment>%d ws-only diff</comment> | <fg=red>%d real diff</>',
            count($effects), count($dbEffects), $newCount, $wsOnlyCount, $diffCount,
        ));

        if ($diffDetails) {
            $io->writeln('  <fg=red>Real text diffs:</>');
            $diffRows = array_map(fn($d) => [
                $d[0],
                $this->truncate($d[1]), $this->truncate($d[2]),
                $this->truncate($d[3]), $this->truncate($d[4]),
            ], $diffDetails);
            $io->table(['ability_key', 'JSON fr', 'DB fr', 'JSON en', 'DB en'], $diffRows);
        }
    }

    private function normalize(?string $s): string
    {
        if ($s === null) return '';
        $s = preg_replace('/\s+/', ' ', trim($s));
        // strip trailing em/en dash separators that appear in JSON but not in DB
        return rtrim($s, " \t—–-");
    }

    private function truncate(?string $s, int $len = 50): string
    {
        if ($s === null) return '—';
        return mb_strlen($s) > $len ? mb_substr($s, 0, $len - 1) . '…' : $s;
    }
}
