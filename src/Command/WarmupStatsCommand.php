<?php

namespace App\Command;

use App\Service\StatsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stats:warmup',
    description: 'Pre-compute and cache stats page data (avoids 504 on first load)',
)]
class WarmupStatsCommand extends Command
{
    public function __construct(private readonly StatsService $statsService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->write('Computing stats… ');

        $start = microtime(true);
        $data  = $this->statsService->buildAndCache();
        $elapsed = round(microtime(true) - $start, 2);

        $io->writeln(sprintf('<info>done</info> in %.2fs', $elapsed));
        $io->writeln(sprintf('  sets: %d  rarities: %d', count($data['sets']), count($data['globalRarities'])));

        return Command::SUCCESS;
    }
}
