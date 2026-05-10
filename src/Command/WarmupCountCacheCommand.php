<?php

namespace App\Command;

use App\Entity\Card;
use App\Entity\CardGroup;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pre-fills the Redis count cache with COUNT results for common filter combinations,
 * so the first real request for each combo is instant (cache hit) rather than slow (COUNT query).
 *
 * The cache key format is identical to CachedCountCollectionProvider, so entries
 * created here are consumed transparently by the provider.
 */
#[AsCommand(
    name: 'app:warmup:counts',
    description: 'Pre-fill Redis count cache for common Card/CardGroup filter combinations',
)]
class WarmupCountCacheCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'cache.card_counts')]
        private readonly CacheItemPoolInterface $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing cache entries');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io    = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $io->title('Count cache warmup');

        // ── Load dimension values ─────────────────────────────────────────────
        $sets      = $this->connection->fetchFirstColumn('SELECT reference FROM card_set  ORDER BY reference');
        $factions  = $this->connection->fetchFirstColumn('SELECT code      FROM faction   ORDER BY code');
        $rarities  = $this->connection->fetchFirstColumn('SELECT reference FROM rarity    ORDER BY reference');
        $cardTypes = $this->connection->fetchFirstColumn('SELECT reference FROM card_type ORDER BY reference');
        $costs     = range(0, 12);

        $io->definitionList(
            ['Sets'       => implode(', ', $sets)],
            ['Factions'   => implode(', ', $factions)],
            ['Rarities'   => implode(', ', $rarities)],
            ['Card types' => implode(', ', $cardTypes)],
            ['Costs'      => '0–12'],
        );

        // ── Build combination list ────────────────────────────────────────────
        $jobs = [];

        // ── Card — 1 dimension ────────────────────────────────────────────────
        foreach ($sets      as $v) { $jobs[] = [Card::class, ['set.reference' => $v]]; }
        foreach ($factions  as $v) { $jobs[] = [Card::class, ['faction.code'  => $v]]; }
        foreach ($rarities  as $v) { $jobs[] = [Card::class, ['rarity'        => $v]]; }
        foreach ($cardTypes as $v) { $jobs[] = [Card::class, ['cardType'      => $v]]; }
        foreach ($costs     as $v) { $jobs[] = [Card::class, ['mainCost'      => (string) $v]]; }
        foreach ($costs     as $v) { $jobs[] = [Card::class, ['recallCost'    => (string) $v]]; }
        $jobs[] = [Card::class, ['isSerialized' => '1']];
        $jobs[] = [Card::class, ['promo'        => '1']];
        $jobs[] = [Card::class, ['kickstarter'  => '1']];

        // ── Card — 2 dimensions ───────────────────────────────────────────────
        foreach ($sets as $set) {
            foreach ($factions  as $v) { $jobs[] = [Card::class, ['faction.code' => $v,  'set.reference' => $set]]; }
            foreach ($rarities  as $v) { $jobs[] = [Card::class, ['rarity'       => $v,  'set.reference' => $set]]; }
            foreach ($cardTypes as $v) { $jobs[] = [Card::class, ['cardType'     => $v,  'set.reference' => $set]]; }
        }
        foreach ($factions as $faction) {
            foreach ($rarities  as $v) { $jobs[] = [Card::class, ['faction.code' => $faction, 'rarity'   => $v]]; }
            foreach ($cardTypes as $v) { $jobs[] = [Card::class, ['cardType'     => $v,  'faction.code'   => $faction]]; }
        }
        foreach ($rarities as $rarity) {
            foreach ($cardTypes as $v) { $jobs[] = [Card::class, ['cardType' => $v, 'rarity' => $rarity]]; }
        }

        // ── Card — 3 dimensions ───────────────────────────────────────────────
        foreach ($sets as $set) {
            foreach ($factions as $faction) {
                foreach ($rarities as $rarity) {
                    $jobs[] = [Card::class, ['faction.code' => $faction, 'rarity' => $rarity, 'set.reference' => $set]];
                }
            }
        }

        // ── CardGroup — 1 dimension ───────────────────────────────────────────
        foreach ($sets      as $v) { $jobs[] = [CardGroup::class, ['set.reference' => $v]]; }
        foreach ($factions  as $v) { $jobs[] = [CardGroup::class, ['faction'       => $v]]; }
        foreach ($rarities  as $v) { $jobs[] = [CardGroup::class, ['rarity'        => $v]]; }
        foreach ($cardTypes as $v) { $jobs[] = [CardGroup::class, ['cardType'      => $v]]; }

        // ── CardGroup — 2 dimensions ──────────────────────────────────────────
        foreach ($sets as $set) {
            foreach ($factions  as $v) { $jobs[] = [CardGroup::class, ['faction'  => $v, 'set.reference' => $set]]; }
            foreach ($rarities  as $v) { $jobs[] = [CardGroup::class, ['rarity'   => $v, 'set.reference' => $set]]; }
            foreach ($cardTypes as $v) { $jobs[] = [CardGroup::class, ['cardType' => $v, 'set.reference' => $set]]; }
        }
        foreach ($factions as $faction) {
            foreach ($rarities  as $v) { $jobs[] = [CardGroup::class, ['faction' => $faction, 'rarity'   => $v]]; }
            foreach ($cardTypes as $v) { $jobs[] = [CardGroup::class, ['cardType' => $v, 'faction'        => $faction]]; }
        }
        foreach ($rarities as $rarity) {
            foreach ($cardTypes as $v) { $jobs[] = [CardGroup::class, ['cardType' => $v, 'rarity' => $rarity]]; }
        }

        // ── CardGroup — 3 dimensions ──────────────────────────────────────────
        foreach ($sets as $set) {
            foreach ($factions as $faction) {
                foreach ($rarities as $rarity) {
                    $jobs[] = [CardGroup::class, ['faction' => $faction, 'rarity' => $rarity, 'set.reference' => $set]];
                }
            }
        }

        $io->writeln(sprintf('<info>%d combinations to compute</info>', count($jobs)));
        $io->progressStart(count($jobs));

        $stored  = 0;
        $skipped = 0;

        foreach ($jobs as [$entityClass, $filters]) {
            $key  = $this->buildCacheKey($entityClass, $filters);
            $item = $this->cache->getItem($key);

            if ($item->isHit() && !$force) {
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            $count = $entityClass === Card::class
                ? $this->countCards($filters)
                : $this->countCardGroups($filters);

            $item->set((float) $count);
            $item->expiresAfter(null);
            $this->cache->save($item);

            $stored++;
            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf('%d stored, %d skipped (already cached — use --force to refresh).', $stored, $skipped));

        return Command::SUCCESS;
    }

    // ── SQL count helpers ─────────────────────────────────────────────────────

    private function countCards(array $filters): int
    {
        $sql    = 'SELECT COUNT(c.id) FROM card c';
        $joins  = [];
        $where  = [];
        $params = [];

        // card_group is needed by faction, cardType, mainCost, recallCost
        $needsCardGroup = isset($filters['faction.code'], $filters['cardType'], $filters['mainCost'], $filters['recallCost'])
            || array_intersect_key($filters, array_flip(['faction.code', 'cardType', 'mainCost', 'recallCost']));

        if ($needsCardGroup) {
            $joins[] = 'JOIN card_group cg ON c.card_group_id = cg.id';
        }

        if (isset($filters['set.reference'])) {
            $joins[]           = 'JOIN card_set cs ON c.set_id = cs.id';
            $where[]           = 'cs.reference = :set_ref';
            $params['set_ref'] = $filters['set.reference'];
        }

        if (isset($filters['faction.code'])) {
            $joins[]                = 'JOIN faction f ON cg.faction_id = f.id';
            $where[]                = 'f.code = :faction_code';
            $params['faction_code'] = $filters['faction.code'];
        }

        if (isset($filters['rarity'])) {
            $joins[]          = 'JOIN rarity r ON c.rarity_id = r.id';
            $where[]          = 'r.reference = :rarity';
            $params['rarity'] = $filters['rarity'];
        }

        if (isset($filters['cardType'])) {
            $joins[]               = 'JOIN card_type ct ON cg.card_type_id = ct.id';
            $where[]               = 'ct.reference = :card_type';
            $params['card_type']   = $filters['cardType'];
        }

        if (isset($filters['mainCost'])) {
            $where[]              = 'cg.main_cost = :main_cost';
            $params['main_cost']  = (int) $filters['mainCost'];
        }

        if (isset($filters['recallCost'])) {
            $where[]                = 'cg.recall_cost = :recall_cost';
            $params['recall_cost']  = (int) $filters['recallCost'];
        }

        if (isset($filters['isSerialized'])) {
            $where[] = 'c.is_serialized = true';
        }

        if (isset($filters['promo'])) {
            $where[] = 'c.promo = true';
        }

        if (isset($filters['kickstarter'])) {
            $where[] = 'c.kickstarter = true';
        }

        return $this->runSql($sql, $joins, $where, $params);
    }

    private function countCardGroups(array $filters): int
    {
        $sql    = 'SELECT COUNT(DISTINCT cg.id) FROM card_group cg';
        $joins  = [];
        $where  = [];
        $params = [];

        if (isset($filters['set.reference'])) {
            $joins[]           = 'JOIN card c ON c.card_group_id = cg.id';
            $joins[]           = 'JOIN card_set cs ON c.set_id = cs.id';
            $where[]           = 'cs.reference = :set_ref';
            $params['set_ref'] = $filters['set.reference'];
        }

        if (isset($filters['faction'])) {
            $joins[]                = 'JOIN faction f ON cg.faction_id = f.id';
            $where[]                = 'f.code = :faction_code';
            $params['faction_code'] = $filters['faction'];
        }

        if (isset($filters['rarity'])) {
            $joins[]          = 'JOIN rarity r ON cg.rarity_id = r.id';
            $where[]          = 'r.reference = :rarity';
            $params['rarity'] = $filters['rarity'];
        }

        if (isset($filters['cardType'])) {
            $joins[]             = 'JOIN card_type ct ON cg.card_type_id = ct.id';
            $where[]             = 'ct.reference = :card_type';
            $params['card_type'] = $filters['cardType'];
        }

        return $this->runSql($sql, $joins, $where, $params);
    }

    private function runSql(string $base, array $joins, array $where, array $params): int
    {
        $sql = $base;

        if ($joins) {
            $sql .= ' ' . implode(' ', array_unique($joins));
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return (int) $this->connection->fetchOne($sql, $params);
    }

    // ── Cache key — must match CachedCountCollectionProvider exactly ──────────

    private function buildCacheKey(string $entityClass, array $filters): string
    {
        $this->recursiveNormalize($filters);

        return 'count_' . md5($entityClass . serialize($filters));
    }

    private function recursiveNormalize(array &$arr): void
    {
        ksort($arr);

        foreach ($arr as &$value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                sort($value);
            } else {
                $this->recursiveNormalize($value);
            }
        }
    }
}
