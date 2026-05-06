<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import:collector-numbers',
    description: 'Set collectorNumberFormatedId on cards from datas/collectorNumber.csv, then derives unique card numbers from their base (common/rare)',
)]
class ImportCollectorNumbersCommand extends Command
{
    private const DATA_FILE  = __DIR__ . '/../../datas/collectorNumber.csv';
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report without writing to DB');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $collectorMap = $this->loadCsv($io);
        if ($collectorMap === null) {
            return Command::FAILURE;
        }

        $io->title(sprintf('Phase 1 — direct import (%d rows%s)', count($collectorMap), $dryRun ? ', dry-run' : ''));
        $this->importDirect($io, $collectorMap, $dryRun);

        $io->title(sprintf('Phase 2 — unique cards%s', $dryRun ? ' (dry-run)' : ''));
        $this->importUniques($io, $collectorMap, $dryRun);

        return Command::SUCCESS;
    }

    /** @return array<string,string>|null  reference → collectorNumber */
    private function loadCsv(SymfonyStyle $io): ?array
    {
        $handle = fopen(self::DATA_FILE, 'r');
        if ($handle === false) {
            $io->error('Cannot read ' . self::DATA_FILE);
            return null;
        }

        $map = [];
        while (($line = fgetcsv($handle, 0, ';')) !== false) {
            if (count($line) < 2) {
                continue;
            }
            $map[trim($line[0])] = trim($line[1]);
        }
        fclose($handle);

        return $map;
    }

    /** @param array<string,string> $collectorMap */
    private function importDirect(SymfonyStyle $io, array $collectorMap, bool $dryRun): void
    {
        $updated = 0;
        $unknown = 0;

        foreach (array_chunk(array_keys($collectorMap), self::BATCH_SIZE) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $existing = $this->connection->fetchAllKeyValue(
                "SELECT reference, id FROM card WHERE reference IN ($placeholders)",
                $chunk,
            );

            foreach ($chunk as $reference) {
                if (!isset($existing[$reference])) {
                    ++$unknown;
                    continue;
                }

                if (!$dryRun) {
                    $this->connection->executeStatement(
                        'UPDATE card SET collector_number_formated_id = ? WHERE reference = ?',
                        [$collectorMap[$reference], $reference],
                    );
                }

                ++$updated;
            }
        }

        if ($dryRun) {
            $io->note(sprintf('Dry-run: %d would be updated, %d unknown references skipped.', $updated, $unknown));
        } else {
            $io->success(sprintf('%d card(s) updated, %d unknown references skipped.', $updated, $unknown));
        }
    }

    /** @param array<string,string> $collectorMap */
    private function importUniques(SymfonyStyle $io, array $collectorMap, bool $dryRun): void
    {
        // Build base-ref → base collector number (strip rarity suffix -C / -R / -F)
        $baseCollectorMap = [];
        foreach ($collectorMap as $reference => $collectorNumber) {
            if (preg_match('/^(.+)_(C|R\d*|F)$/', $reference, $m)) {
                $baseRef = $m[1];
                $baseCollector = (string) preg_replace('/-[^-]+$/', '', $collectorNumber);
                if (!isset($baseCollectorMap[$baseRef])) {
                    $baseCollectorMap[$baseRef] = $baseCollector;
                }
            }
        }

        $uniqueRarityId = $this->connection->fetchOne("SELECT id FROM rarity WHERE reference = 'UNIQUE'");
        if ($uniqueRarityId === false) {
            $io->warning('Rarity UNIQUE not found in DB, skipping phase 2.');
            return;
        }

        $updated = 0;
        $noBase  = 0;
        $lastId  = 0;

        while (true) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, reference FROM card
                 WHERE rarity_id = ? AND id > ?
                 ORDER BY id
                 LIMIT ?',
                [$uniqueRarityId, $lastId, self::BATCH_SIZE],
            );

            if (empty($rows)) {
                break;
            }

            $lastId = $rows[array_key_last($rows)]['id'];

            // Compute collector numbers for the whole batch
            $updates = [];
            foreach ($rows as $row) {
                $reference = $row['reference'];

                if (!preg_match('/^(.+)_U_(\d+)$/', $reference, $m)) {
                    ++$noBase;
                    $io->text(sprintf('  <comment>unexpected unique format:</comment> %s', $reference));
                    continue;
                }

                $baseCollector = $baseCollectorMap[$m[1]] ?? null;
                if ($baseCollector === null) {
                    ++$noBase;
                    $io->text(sprintf('  <comment>no base collector found for:</comment> %s', $reference));
                    continue;
                }

                $updates[$reference] = sprintf('%s-U-%s', $baseCollector, $m[2]);
            }

            if (!$dryRun && $updates !== []) {
                $cases  = '';
                $params = [];
                foreach ($updates as $ref => $collector) {
                    $cases    .= ' WHEN ? THEN ?';
                    $params[]  = $ref;
                    $params[]  = $collector;
                }
                $inList = implode(',', array_fill(0, count($updates), '?'));
                $params = array_merge($params, array_keys($updates));

                $this->connection->executeStatement(
                    "UPDATE card SET collector_number_formated_id = CASE reference $cases END WHERE reference IN ($inList)",
                    $params,
                );
            }

            $updated += count($updates);
        }

        if ($dryRun) {
            $io->note(sprintf('Dry-run: %d unique(s) would be updated, %d with no base found.', $updated, $noBase));
        } else {
            $io->success(sprintf('%d unique card(s) updated, %d with no base found.', $updated, $noBase));
        }
    }
}
