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
    name: 'app:check:unique-card-groups',
    description: 'Show UNIQUE card_groups that have more than one card, grouped by set pair',
)]
class CheckUniqueCardGroupsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('min-cards', null, InputOption::VALUE_OPTIONAL, 'Minimum number of cards per card_group to report', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $minCards = (int) $input->getOption('min-cards');

        $io->title('UNIQUE card_groups with multiple cards');

        // ── 1. Distribution: how many cards per card_group? ─────────────────
        $io->section('Distribution (cards per card_group)');

        $dist = $this->connection->fetchAllAssociative(
            "SELECT nb_cards, COUNT(*) AS nb_groups
             FROM (
                 SELECT c.card_group_id, COUNT(cs.card_id) AS nb_cards
                 FROM card_search cs
                 JOIN card c ON c.id = cs.card_id
                 WHERE c.is_serialized = true
                 GROUP BY c.card_group_id
             ) sub
             GROUP BY nb_cards
             ORDER BY nb_cards"
        );

        $io->table(['Cards per group', 'Nb card_groups'], array_map(
            fn($r) => [$r['nb_cards'], number_format((int) $r['nb_groups'])],
            $dist,
        ));

        // ── 2. By set pair: which sets share the same UNIQUE card_group? ─────
        $io->section('Set pairs sharing UNIQUE card_groups');

        $pairRows = $this->connection->fetchAllAssociative(
            "SELECT sets, COUNT(*) AS nb_groups, SUM(nb_cards) AS nb_cards
             FROM (
                 SELECT
                     c.card_group_id,
                     COUNT(DISTINCT cs.card_id)              AS nb_cards,
                     STRING_AGG(DISTINCT s.reference, ',')   AS sets
                 FROM card_search cs
                 JOIN card c     ON c.id    = cs.card_id
                 JOIN card_set s ON s.id    = c.set_id
                 WHERE c.is_serialized = true
                 GROUP BY c.card_group_id
                 HAVING COUNT(DISTINCT cs.card_id) >= :min
             ) sub
             GROUP BY sets
             ORDER BY nb_groups DESC",
            ['min' => $minCards],
        );

        $bySets = array_map(fn($r) => [
            'sets'      => $r['sets'],
            'nb_groups' => (int) $r['nb_groups'],
            'nb_cards'  => (int) $r['nb_cards'],
        ], $pairRows);

        if (empty($bySets)) {
            $io->success('No UNIQUE card_groups with multiple cards found.');
            return Command::SUCCESS;
        }

        $io->table(
            ['Sets', 'Shared card_groups', 'Total cards'],
            array_map(fn($r) => [
                $r['sets'],
                number_format($r['nb_groups']),
                number_format($r['nb_cards']),
            ], $bySets),
        );

        // ── 3. Sample: show a few offending card_groups ──────────────────────
        $io->section(sprintf('Sample card_groups with >= %d cards', $minCards));

        $samples = $this->connection->fetchAllAssociative(
            "SELECT
                 cg.slug,
                 STRING_AGG(c.reference, ', ' ORDER BY c.reference) AS card_refs,
                 STRING_AGG(DISTINCT s.reference, ',')              AS sets
             FROM card_search cs
             JOIN card c      ON c.id   = cs.card_id
             JOIN card_set s  ON s.id   = c.set_id
             JOIN card_group cg ON cg.id = c.card_group_id
             WHERE c.is_serialized = true
             GROUP BY cg.id, cg.slug
             HAVING COUNT(DISTINCT cs.card_id) >= :min
             ORDER BY COUNT(DISTINCT cs.card_id) DESC
             LIMIT 20",
            ['min' => $minCards],
        );

        $io->table(
            ['Slug', 'Sets', 'Card references'],
            array_map(fn($r) => [$r['slug'], $r['sets'], $r['card_refs']], $samples),
        );

        return Command::SUCCESS;
    }
}
