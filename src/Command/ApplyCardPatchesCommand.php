<?php

namespace App\Command;

use App\Service\CardPatchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:cards:apply-patches',
    description: 'Applies pending dated card-patch files from datas/card_patches/, in filename order, skipping files already recorded in card_patch_log',
)]
final class ApplyCardPatchesCommand extends Command
{
    public function __construct(
        private readonly CardPatchService $cardPatchService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve and report matches without writing to the DB')
            ->addOption('file', null, InputOption::VALUE_OPTIONAL, 'Process only this filename instead of every pending file')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Reapply a file even if already recorded in card_patch_log')
            ->addOption('validate-only', null, InputOption::VALUE_NONE, 'Only validate file structure, no resolution or writes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $onlyFile     = $input->getOption('file');
        $validateOnly = (bool) $input->getOption('validate-only');

        if ($validateOnly) {
            return $this->runValidateOnly($io, $onlyFile);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $results = $this->cardPatchService->applyPending(dryRun: $dryRun, force: $force, onlyFile: $onlyFile);

        if ($results === []) {
            $io->note('Aucun fichier de patch trouvé.');

            return Command::SUCCESS;
        }

        $hasInvalid = false;

        foreach ($results as $result) {
            switch ($result->status) {
                case 'already_applied':
                    $io->text(sprintf('<comment>skip</comment>   %s (déjà appliqué)', $result->filename));
                    break;

                case 'invalid':
                    $hasInvalid = true;
                    $io->text(sprintf('<error>invalid</error> %s', $result->filename));
                    foreach ($result->errors as $error) {
                        $io->text('         - ' . $error);
                    }
                    break;

                case 'applied':
                    $io->text(sprintf(
                        '<info>%s</info>  %s (%d mise(s) à jour, %d ignorée(s))',
                        $dryRun ? 'dry-run' : 'ok',
                        $result->filename,
                        $result->rowsUpdated,
                        $result->rowsSkipped,
                    ));
                    foreach ($result->wildcardMatches as $pattern => $refs) {
                        $preview = array_slice($refs, 0, 20);
                        $suffix  = count($refs) > 20 ? sprintf(' … (%d de plus)', count($refs) - 20) : '';
                        $io->text(sprintf('         %s -> %d référence(s) : %s%s', $pattern, count($refs), implode(', ', $preview), $suffix));
                    }
                    break;
            }
        }

        if ($hasInvalid) {
            $io->error('Un ou plusieurs fichiers de patch sont invalides — rien n\'a été écrit pour ceux-ci.');

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry-run terminé.' : 'Patches appliqués.');

        return Command::SUCCESS;
    }

    private function runValidateOnly(SymfonyStyle $io, ?string $onlyFile): int
    {
        $files = $this->cardPatchService->listPatchFiles();
        if ($onlyFile !== null) {
            $files = array_values(array_filter($files, static fn(string $f) => $f === $onlyFile));
        }

        if ($files === []) {
            $io->note('Aucun fichier de patch trouvé.');

            return Command::SUCCESS;
        }

        $hasInvalid = false;

        foreach ($files as $filename) {
            $errors = $this->cardPatchService->validateFile($filename);
            if ($errors === []) {
                $io->text(sprintf('<info>valid</info>   %s', $filename));
                continue;
            }

            $hasInvalid = true;
            $io->text(sprintf('<error>invalid</error> %s', $filename));
            foreach ($errors as $error) {
                $io->text('         - ' . $error);
            }
        }

        return $hasInvalid ? Command::FAILURE : Command::SUCCESS;
    }
}
