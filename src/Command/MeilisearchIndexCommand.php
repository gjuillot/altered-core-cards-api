<?php

namespace App\Command;

use App\Repository\CardDocumentRepository;
use App\Service\MeilisearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:meilisearch:index',
    description: 'Index all cards into Meilisearch',
)]
final class MeilisearchIndexCommand extends Command
{
    public function __construct(
        private readonly MeilisearchService $meilisearch,
        private readonly CardDocumentRepository $cardDocumentRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('configure', null, InputOption::VALUE_NONE, 'Configure index settings before indexing');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Delete all documents before re-indexing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Indexing cards into Meilisearch…');

        if ($input->getOption('configure')) {
            $io->text('Configuring index attributes…');
            $this->meilisearch->configureIndex();
        }

        if ($input->getOption('clear')) {
            $io->text('Clearing existing documents…');
            $this->meilisearch->getIndex()->deleteAllDocuments();
        }

        $total = $this->cardDocumentRepository->countAll();
        $io->text(sprintf('Streaming %d cards…', $total));

        $progressBar = $io->createProgressBar($total);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        $indexed = 0;
        foreach ($this->cardDocumentRepository->streamDocuments() as $batch) {
            $json = json_encode($batch, JSON_INVALID_UTF8_IGNORE);

            if ($json === false) {
                $indexed += count($batch);
                $progressBar->advance(count($batch));
                continue;
            }

            try {
                $this->meilisearch->getIndex()->addDocumentsJson($json);
            } catch (\Throwable $e) {
                // One document in this batch is malformed — send them one by one to identify it.
                $progressBar->clear();
                foreach ($batch as $doc) {
                    $docJson = json_encode($doc, JSON_INVALID_UTF8_IGNORE);
                    if ($docJson === false) {
                        $io->warning(sprintf('Card ID %d — json_encode failed', $doc['id']));
                        continue;
                    }
                    try {
                        $this->meilisearch->getIndex()->addDocumentsJson($docJson);
                    } catch (\Throwable $inner) {
                        $io->warning(sprintf(
                            'Card ID %d skipped — Meilisearch rejected it: %s',
                            $doc['id'],
                            $inner->getMessage(),
                        ));
                    }
                }
                $progressBar->display();
            }

            $indexed += count($batch);
            $progressBar->advance(count($batch));
        }

        $progressBar->finish();
        $io->newLine(2);
        $io->success(sprintf('%d cards indexed.', $indexed));

        return Command::SUCCESS;
    }
}
