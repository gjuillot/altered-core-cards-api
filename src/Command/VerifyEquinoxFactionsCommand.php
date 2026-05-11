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
    name: 'app:verify:equinox:factions',
    description: 'Check that DB faction and transfuge flag match Equinox JSON mainFaction',
)]
class VerifyEquinoxFactionsCommand extends Command
{
    private const BATCH_SIZE = 500;

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
            ->addOption('set', 's', InputOption::VALUE_OPTIONAL, 'Limit to a specific set (e.g. BISE, CORE, COREKS)')
            ->addOption('faction', 'f', InputOption::VALUE_OPTIONAL, 'Limit to a specific faction directory (e.g. AX, LY)')
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

        $io->title('Equinox faction / transfuge verification');
        $io->writeln(sprintf('Files: <info>%d</info>', $total));

        $ok         = 0;
        $missing    = 0;
        $mismatches = [];
        $batch      = [];

        foreach ($files as $file) {
            $data      = json_decode($file->getContents(), true, 512, JSON_THROW_ON_ERROR);
            $reference = $data['reference'] ?? '';

            if ($reference === '') {
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

            $jsonFaction = strtoupper($data['mainFaction']['reference'] ?? '');
            $refParts    = explode('_', $reference);
            $refFaction  = strtoupper($refParts[3] ?? '');

            $batch[$reference] = [
                'jsonFaction' => $jsonFaction,
                'refFaction'  => $refFaction,
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $this->verifyBatch($batch, $ok, $missing, $mismatches, $showOk, $io);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $this->verifyBatch($batch, $ok, $missing, $mismatches, $showOk, $io);
        }

        $io->newLine();
        $io->definitionList(
            ['OK'         => $ok],
            ['Missing'    => $missing],
            ['Mismatches' => count($mismatches)],
        );

        if (!empty($mismatches)) {
            $io->section('Mismatches');
            $io->table(
                ['Reference', 'JSON faction', 'DB faction', 'DB transfuge', 'Issue'],
                $mismatches,
            );
            return Command::FAILURE;
        }

        $io->success('All cards match.');
        return Command::SUCCESS;
    }

    private function verifyBatch(
        array        $batch,
        int          &$ok,
        int          &$missing,
        array        &$mismatches,
        bool         $showOk,
        SymfonyStyle $io,
    ): void {
        $placeholders = implode(',', array_fill(0, count($batch), '?'));

        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.reference, c.transfuge, f.code AS faction_code
             FROM card c
             LEFT JOIN card_group cg ON cg.id = c.card_group_id
             LEFT JOIN faction f ON f.id = cg.faction_id
             WHERE c.reference IN (' . $placeholders . ')',
            array_values(array_keys($batch)),
        );

        $dbMap = [];
        foreach ($rows as $row) {
            $dbMap[$row['reference']] = [
                'faction'   => strtoupper($row['faction_code'] ?? ''),
                'transfuge' => (bool) $row['transfuge'],
            ];
        }

        foreach ($batch as $reference => $entry) {
            $jsonFaction = $entry['jsonFaction'];
            $refFaction  = $entry['refFaction'];

            if (!isset($dbMap[$reference])) {
                $missing++;
                $mismatches[] = [$reference, $jsonFaction, '<not in DB>', '—', 'missing'];
                continue;
            }

            $dbFaction   = $dbMap[$reference]['faction'];
            $dbTransfuge = $dbMap[$reference]['transfuge'];
            $issues      = [];

            if ($jsonFaction !== '' && $dbFaction !== $jsonFaction) {
                $issues[] = sprintf('faction DB=%s JSON=%s', $dbFaction, $jsonFaction);
            }

            $expectedTransfuge = $refFaction !== '' && $jsonFaction !== '' && $refFaction !== $jsonFaction;
            if ($dbTransfuge !== $expectedTransfuge) {
                $issues[] = sprintf('transfuge DB=%s expected=%s', $dbTransfuge ? 'true' : 'false', $expectedTransfuge ? 'true' : 'false');
            }

            if (empty($issues)) {
                $ok++;
                if ($showOk) {
                    $io->writeln(sprintf('  <info>OK</info>  %s  faction=%s  transfuge=%s', $reference, $dbFaction, $dbTransfuge ? 'true' : 'false'));
                }
                continue;
            }

            $mismatches[] = [$reference, $jsonFaction, $dbFaction, $dbTransfuge ? 'true' : 'false', implode(' | ', $issues)];
        }
    }
}
