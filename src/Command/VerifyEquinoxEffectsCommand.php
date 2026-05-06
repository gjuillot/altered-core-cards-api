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
    name: 'app:verify:equinox:effects',
    description: 'Compare card effect IDs in DB against Equinox JSON files',
)]
class VerifyEquinoxEffectsCommand extends Command
{
    private const BATCH_SIZE = 500;

    private const LOCALE_MAP = [
        'en_US' => 'en-us',
        'fr_FR' => 'fr-fr',
        'de_DE' => 'de-de',
        'es_ES' => 'es-es',
        'it_IT' => 'it-it',
    ];

    private const RARITY_ABBREV = [
        'COMMON'  => ['C'],
        'RARE'    => ['R1', 'R2'],
        'EXALTED' => ['E'],
        'UNIQUE'  => ['U'],
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Root directory containing JSON files', 'datas/equinox')
            ->addOption('set', 's', InputOption::VALUE_OPTIONAL, 'Limit to a specific set (e.g. BISE, CORE)')
            ->addOption('faction', 'f', InputOption::VALUE_OPTIONAL, 'Limit to a specific faction (e.g. AX, LY)')
            ->addOption('rarity', 'r', InputOption::VALUE_OPTIONAL, 'Comma-separated rarities (COMMON, RARE, EXALTED, UNIQUE)')
            ->addOption('show-ok', null, InputOption::VALUE_NONE, 'Also print cards where everything matches');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory     = $input->getArgument('directory');
        $setFilter     = $input->getOption('set') ? strtoupper($input->getOption('set')) : null;
        $factionFilter = $input->getOption('faction') ? strtoupper($input->getOption('faction')) : null;
        $showOk        = (bool) $input->getOption('show-ok');

        $rarityAbbrevs = null;
        if ($input->getOption('rarity')) {
            $keys          = array_map('strtoupper', array_map('trim', explode(',', $input->getOption('rarity'))));
            $rarityAbbrevs = array_merge(...array_map(fn($k) => self::RARITY_ABBREV[$k] ?? [], $keys));
        }

        if (!is_dir($directory)) {
            $io->error(sprintf('Directory not found: %s', $directory));
            return Command::FAILURE;
        }

        $finder = new Finder();
        $finder->files()->name('*.json')->in($directory);

        if ($setFilter) {
            $finder->path(sprintf('/^%s\//', preg_quote($setFilter, '/')));
        }
        if ($factionFilter) {
            $finder->path(sprintf('/^[^\/]+\/%s\//', preg_quote($factionFilter, '/')));
        }

        $files = iterator_to_array($finder);
        $total = count($files);

        if ($total === 0) {
            $io->warning('No JSON files found.');
            return Command::SUCCESS;
        }

        $io->title('Equinox effect verification');
        $io->writeln(sprintf('Files: <info>%d</info>', $total));

        $ok       = 0;
        $missing  = 0;  // card not found in DB
        $mismatches = [];
        $batch    = [];

        foreach ($files as $file) {
            $data      = json_decode($file->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $alteredId = $data['id'] ?? null;
            $reference = $data['reference'] ?? '';

            if (!$alteredId) {
                continue;
            }

            if ($rarityAbbrevs !== null) {
                $parts       = explode('_', $reference);
                $rarityInRef = $parts[5] ?? '';
                $matches     = false;
                foreach ($rarityAbbrevs as $abbrev) {
                    if (str_starts_with($rarityInRef, $abbrev)) { $matches = true; break; }
                }
                if (!$matches) continue;
            }

            $effectKeys = $this->extractEffectKeys($data['cardElements'] ?? []);
            $jsonKeys   = $effectKeys['MAIN_EFFECT'] ?? [];

            $jsonTexts = $this->extractEffectTexts($data['cardElements'] ?? []);

            $batch[$alteredId] = [
                'reference' => $reference,
                'jsonKeys'  => $jsonKeys,
                'jsonTexts' => $jsonTexts['MAIN_EFFECT'] ?? [],
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->verifyBatch($batch, $ok, $missing, $mismatches, $showOk, $io);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->verifyBatch($batch, $ok, $missing, $mismatches, $showOk, $io);
        }

        $realMismatches = array_filter($mismatches, fn($r) => ($r[6] ?? '') !== 'alias');
        $aliases        = array_filter($mismatches, fn($r) => ($r[6] ?? '') === 'alias');

        $io->newLine();
        $io->definitionList(
            ['OK'      => $ok],
            ['Alias'   => count($aliases)],
            ['Missing' => $missing],
            ['Mismatch'=> count($realMismatches)],
        );

        if (!empty($aliases) && $showOk) {
            $io->section('Aliases (same text, different Equinox ID — harmless)');
            $io->table(
                ['Reference', 'Slot', 'JSON key', 'DB key'],
                array_map(fn($r) => [$r[0], $r[1], $r[2], $r[3]], $aliases),
            );
        }

        if (!empty($realMismatches)) {
            $io->section('Real mismatches');
            $io->table(
                ['Reference', 'Slot', 'JSON key', 'DB key', 'JSON text (60)', 'DB text (60)'],
                array_map(fn($r) => array_slice($r, 0, 6), $realMismatches),
            );
            return Command::FAILURE;
        }

        $io->success('All cards match (aliases ignored).');
        return Command::SUCCESS;
    }

    private function verifyBatch(
        array    $batch,
        int      &$ok,
        int      &$missing,
        array    &$mismatches,
        bool     $showOk,
        SymfonyStyle $io,
    ): void {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));
        $alteredIds   = array_keys($batch);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.altered_id,
                    c.reference,
                    me1.ability_key AS ek1, me1.text_en AS text1,
                    me2.ability_key AS ek2, me2.text_en AS text2,
                    me3.ability_key AS ek3, me3.text_en AS text3
             FROM card c
             LEFT JOIN card_group cg ON cg.id = c.card_group_id
             LEFT JOIN main_effect me1 ON me1.id = cg.effect1_id
             LEFT JOIN main_effect me2 ON me2.id = cg.effect2_id
             LEFT JOIN main_effect me3 ON me3.id = cg.effect3_id
             WHERE c.altered_id IN ({$placeholders})",
            array_values($alteredIds),
        );

        $dbMap = [];
        foreach ($rows as $row) {
            $dbMap[$row['altered_id']] = [
                'keys'  => [$row['ek1'], $row['ek2'], $row['ek3']],
                'texts' => [$row['text1'], $row['text2'], $row['text3']],
            ];
        }

        foreach ($batch as $alteredId => $entry) {
            $reference = $entry['reference'];
            $jsonKeys  = $entry['jsonKeys'];

            if (!isset($dbMap[$alteredId])) {
                $missing++;
                $mismatches[] = [$reference, '—', '—', '<not in DB>', '', ''];
                continue;
            }

            $dbKeys      = $dbMap[$alteredId]['keys'];
            $dbTexts     = $dbMap[$alteredId]['texts'];
            $jsonTexts   = $entry['jsonTexts'];
            $cardMissed  = false;

            for ($i = 0; $i < 3; $i++) {
                $jsonKey  = $jsonKeys[$i] ?? null;
                $dbKey    = $dbKeys[$i] ?? null;

                if ($jsonKey === $dbKey) {
                    continue;
                }

                $jsonText = isset($jsonTexts[$i]) ? mb_substr($jsonTexts[$i], 0, 60) : '';
                $dbText   = isset($dbTexts[$i])   ? mb_substr($dbTexts[$i],   0, 60) : '';

                // Alias: keys differ but DB text matches — same effect, different Equinox ID
                $isAlias = $dbTexts[$i] !== null
                    && $this->buildExpectedText($jsonKey) === $dbTexts[$i];

                $mismatches[] = [
                    $reference,
                    sprintf('effect%d', $i + 1),
                    $jsonKey ?? '(none)',
                    $dbKey   ?? '(none)',
                    $jsonText,
                    $dbText,
                    $isAlias ? 'alias' : 'mismatch',
                ];
                $cardMissed = true;
            }

            if (!$cardMissed) {
                $ok++;
                if ($showOk) {
                    $io->writeln(sprintf('  <info>OK</info> %s', $reference));
                }
            }
        }
    }

    private function extractEffectKeys(array $cardElements): array
    {
        $result = [];

        foreach ($cardElements as $cardElement) {
            $type = $cardElement['cardElementType']['reference'] ?? null;
            if (!in_array($type, ['MAIN_EFFECT', 'ECHO_EFFECT'], true)) {
                continue;
            }

            $displays = $cardElement['cardEffectDisplays'] ?? [];
            usort($displays, static fn($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

            $result[$type] = array_values(array_filter(
                array_map(static fn($d) => $d['cardEffect']['reference'] ?? null, $displays)
            ));
        }

        return $result;
    }

    private function buildExpectedText(?string $abilityKey): ?string
    {
        if ($abilityKey === null) return null;

        $parts = explode('_', $abilityKey);
        if (count($parts) !== 3) return null;

        [$tIdGd, $cIdGd, $eIdGd] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];

        $rows = $this->connection->fetchAllAssociative(
            "SELECT 'T' AS t, altered_id, text_en FROM ability_trigger WHERE altered_id = ?
             UNION ALL
             SELECT 'C', altered_id, text_en FROM ability_condition WHERE altered_id = ?
             UNION ALL
             SELECT 'E', altered_id, text_en FROM ability_effect WHERE altered_id = ?",
            [$tIdGd, $cIdGd, $eIdGd],
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['t']] = $row['text_en'];
        }

        $textParts = array_filter([$map['T'] ?? null, $map['C'] ?? null, $map['E'] ?? null]);
        return $textParts ? implode(' ', $textParts) : null;
    }

    private function extractEffectTexts(array $cardElements): array
    {
        $result = [];

        foreach ($cardElements as $cardElement) {
            $type = $cardElement['cardElementType']['reference'] ?? null;
            if (!in_array($type, ['MAIN_EFFECT', 'ECHO_EFFECT'], true)) {
                continue;
            }

            $displays = $cardElement['cardEffectDisplays'] ?? [];
            usort($displays, static fn($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

            $texts = [];
            foreach ($displays as $display) {
                $ref = $display['cardEffect']['reference'] ?? null;
                if ($ref === null) continue;
                // Prefer displayText, fallback to cardEffect text fields
                $text = $display['displayTexts']['en_US']
                    ?? $display['cardEffect']['textEn']
                    ?? $display['cardEffect']['text']
                    ?? '';
                $texts[] = $text;
            }

            $result[$type] = $texts;
        }

        return $result;
    }
}
