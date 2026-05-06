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
    name: 'app:check:abilities',
    description: 'Reads ability data from Equinox JSON files and verifies consistency with the DB',
)]
class CheckAbilitiesCommand extends Command
{
    private const LOCALE_MAP = [
        'fr_FR' => 'fr',
        'en_US' => 'en',
        'de_DE' => 'de',
        'es_ES' => 'es',
        'it_IT' => 'it',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Root directory of Equinox JSON files', 'datas/equinox')
            ->addOption('set', null, InputOption::VALUE_OPTIONAL, 'Filter by set (e.g. BISE)')
            ->addOption('missing', null, InputOption::VALUE_NONE, 'Show only ability keys missing from DB')
            ->addOption('mismatch', null, InputOption::VALUE_NONE, 'Show only text mismatches between JSON and DB')
            ->addOption('parts', null, InputOption::VALUE_NONE, 'Also check ability_trigger / ability_condition / ability_effect tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $directory = $input->getArgument('directory');
        $setFilter = $input->getOption('set');
        $onlyMissing  = $input->getOption('missing');
        $onlyMismatch = $input->getOption('mismatch');
        $checkParts   = $input->getOption('parts');

        $io->title('Ability consistency check — JSON vs DB');

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
         * $jsonEffects[abilityKey] = [
         *   'fr' => string|null,
         *   'en' => string|null,
         *   ...
         *   'cards' => [reference, ...],
         *   'elements' => [
         *     ['idGd' => int, 'type' => string, 'fr' => string|null, ...]
         *   ],
         * ]
         */
        $jsonEffects = [];

        /** $jsonParts['trigger'|'condition'|'effect'][idGd] = ['fr'=>.., 'en'=>.., ..] */
        $jsonParts = ['trigger' => [], 'condition' => [], 'effect' => []];

        $fileCount = 0;
        foreach ($finder as $file) {
            try {
                $data = json_decode($file->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                continue;
            }

            $cardRef    = $data['reference'] ?? $file->getFilename();
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

                    if (!isset($jsonEffects[$abilityKey])) {
                        $jsonEffects[$abilityKey] = [
                            'fr' => null, 'en' => null, 'de' => null, 'es' => null, 'it' => null,
                            'cards'    => [],
                            'elements' => [],
                        ];
                    }

                    $jsonEffects[$abilityKey]['cards'][] = $cardRef;

                    // Build per-locale combined text from element translations
                    $elementTexts = ['fr' => [], 'en' => [], 'de' => [], 'es' => [], 'it' => []];

                    foreach ($cardEffect['cardEffectElements'] ?? [] as $el) {
                        $idGd = (int) ($el['idGd'] ?? 0);
                        $type = strtolower($el['type'] ?? '');
                        // Map OUTPUT → effect for the parts table
                        $partType = match($type) {
                            'trigger'   => 'trigger',
                            'condition' => 'condition',
                            default     => 'effect',
                        };

                        $elEntry = ['idGd' => $idGd, 'type' => $type];

                        foreach (self::LOCALE_MAP as $apiLocale => $col) {
                            $text = $el['translations'][$apiLocale]['text'] ?? null;
                            if ($text !== null) {
                                $elementTexts[$col][] = $text;
                                $elEntry[$col] = $text;

                                // Register in parts map
                                if ($idGd > 0 && $checkParts) {
                                    if (!isset($jsonParts[$partType][$idGd])) {
                                        $jsonParts[$partType][$idGd] = ['fr' => null, 'en' => null, 'de' => null, 'es' => null, 'it' => null];
                                    }
                                    $jsonParts[$partType][$idGd][$col] ??= $text;
                                }
                            }
                        }

                        $jsonEffects[$abilityKey]['elements'][] = $elEntry;
                    }

                    // Combined text = elements joined with space (first time only)
                    foreach (['fr', 'en', 'de', 'es', 'it'] as $col) {
                        if ($jsonEffects[$abilityKey][$col] === null && !empty($elementTexts[$col])) {
                            $jsonEffects[$abilityKey][$col] = implode(' ', $elementTexts[$col]);
                        }
                    }
                }
            }
        }

        $io->writeln(sprintf('  Parsed %d files — found <info>%d</info> unique ability keys.', $fileCount, count($jsonEffects)));

        // ── 2. Load DB data ───────────────────────────────────────────────────

        $io->section('Loading DB data…');

        $dbEffects = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, ability_key, text_fr, text_en, text_de, text_es, text_it FROM main_effect WHERE ability_key IS NOT NULL'
        );
        foreach ($rows as $row) {
            $dbEffects[$row['ability_key']] = $row;
        }

        $io->writeln(sprintf('  Found <info>%d</info> main_effect rows with an ability_key in DB.', count($dbEffects)));

        // ── 3. Compare main_effect ────────────────────────────────────────────

        $io->section('Comparing main_effect…');

        $missing    = [];   // in JSON but not in DB
        $mismatches = [];   // in both but text differs
        $ok         = 0;

        foreach ($jsonEffects as $abilityKey => $jsonData) {
            if (!isset($dbEffects[$abilityKey])) {
                $missing[] = [
                    'key'   => $abilityKey,
                    'cards' => implode(', ', array_unique(array_slice($jsonData['cards'], 0, 3))),
                    'en'    => mb_substr($jsonData['en'] ?? '—', 0, 60),
                    'fr'    => mb_substr($jsonData['fr'] ?? '—', 0, 60),
                ];
                continue;
            }

            $dbRow = $dbEffects[$abilityKey];
            $diffs = [];

            foreach (['fr', 'en', 'de', 'es', 'it'] as $col) {
                $jsonText = $jsonData[$col];
                $dbText   = $dbRow["text_{$col}"] ?? null;

                if ($jsonText === null || $dbText === null) {
                    continue; // skip if either side has no data for this locale
                }

                // Normalise for comparison (trim, collapse whitespace)
                $jsonNorm = $this->normalise($jsonText);
                $dbNorm   = $this->normalise($dbText);

                if ($jsonNorm !== $dbNorm) {
                    $diffs[$col] = ['json' => mb_substr($jsonText, 0, 60), 'db' => mb_substr($dbText, 0, 60)];
                }
            }

            if ($diffs) {
                $mismatches[] = ['key' => $abilityKey, 'diffs' => $diffs];
            } else {
                $ok++;
            }
        }

        // Keys in DB but not in JSON files (extra in DB)
        $extra = array_diff(array_keys($dbEffects), array_keys($jsonEffects));

        // ── 4. Report ─────────────────────────────────────────────────────────

        $showAll = !$onlyMissing && !$onlyMismatch;

        if ($showAll || $onlyMissing) {
            $io->section(sprintf('Missing from DB (%d)', count($missing)));
            if ($missing) {
                $io->table(
                    ['ability_key', 'sample cards', 'en (json)', 'fr (json)'],
                    array_map(fn($r) => [$r['key'], $r['cards'], $r['en'], $r['fr']], $missing)
                );
            } else {
                $io->writeln('  <info>None — all JSON ability keys are present in DB.</info>');
            }
        }

        if ($showAll || $onlyMismatch) {
            $io->section(sprintf('Text mismatches (%d)', count($mismatches)));
            if ($mismatches) {
                foreach ($mismatches as $m) {
                    $io->writeln(sprintf('  <comment>%s</comment>', $m['key']));
                    foreach ($m['diffs'] as $col => $diff) {
                        $io->writeln(sprintf(
                            '    [<fg=yellow>%s</fg=yellow>] JSON: %s',
                            strtoupper($col), $diff['json']
                        ));
                        $io->writeln(sprintf(
                            '         DB  : %s',
                            $diff['db']
                        ));
                    }
                }
            } else {
                $io->writeln('  <info>None — all matching keys have consistent texts.</info>');
            }
        }

        if ($showAll) {
            $io->section(sprintf('Extra in DB (not found in any JSON file): %d', count($extra)));
            if ($extra && count($extra) <= 30) {
                $io->listing(array_values($extra));
            } elseif ($extra) {
                $io->writeln(sprintf('  (showing first 30 of %d)', count($extra)));
                $io->listing(array_slice(array_values($extra), 0, 30));
            } else {
                $io->writeln('  <info>None.</info>');
            }
        }

        // ── 5. Ability parts check ────────────────────────────────────────────

        if ($checkParts) {
            $this->checkParts($io, $jsonParts);
        }

        // ── 6. Summary ────────────────────────────────────────────────────────

        $io->success(sprintf(
            'Done — %d OK | %d missing from DB | %d mismatches | %d extra in DB',
            $ok, count($missing), count($mismatches), count($extra)
        ));

        return Command::SUCCESS;
    }

    // ── Ability parts (trigger / condition / effect) ──────────────────────────

    private function checkParts(SymfonyStyle $io, array $jsonParts): void
    {
        $tableMap = [
            'trigger'   => 'ability_trigger',
            'condition' => 'ability_condition',
            'effect'    => 'ability_effect',
        ];

        foreach ($tableMap as $type => $table) {
            $dbRows = $this->connection->fetchAllAssociative(
                "SELECT altered_id, text_fr, text_en, text_de, text_es, text_it FROM {$table}"
            );
            $dbMap = [];
            foreach ($dbRows as $row) {
                $dbMap[(int)$row['altered_id']] = $row;
            }

            $missing  = [];
            $mismatches = [];
            $ok = 0;

            foreach ($jsonParts[$type] as $idGd => $jsonTexts) {
                if (!isset($dbMap[$idGd])) {
                    $missing[] = [$idGd, $jsonTexts['en'] ?? '—', $jsonTexts['fr'] ?? '—'];
                    continue;
                }
                $dbRow = $dbMap[$idGd];
                $hasDiff = false;
                foreach (['fr', 'en', 'de', 'es', 'it'] as $col) {
                    $jt = $jsonTexts[$col];
                    $dt = $dbRow["text_{$col}"] ?? null;
                    if ($jt !== null && $dt !== null && $this->normalise($jt) !== $this->normalise($dt)) {
                        $hasDiff = true;
                        break;
                    }
                }
                $hasDiff ? $mismatches[] = $idGd : $ok++;
            }

            $io->section(sprintf('%s — %d OK | %d missing | %d mismatches', ucfirst($type), $ok, count($missing), count($mismatches)));
            if ($missing) {
                $io->table(['idGd', 'en (json)', 'fr (json)'], $missing);
            }
            if ($mismatches) {
                $io->writeln('Mismatched idGd values: ' . implode(', ', $mismatches));
            }
        }
    }

    private function normalise(string $text): string
    {
        return preg_replace('/\s+/', ' ', trim($text));
    }
}
