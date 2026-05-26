<?php

namespace App\Command;

use App\Builder\CardBuilder;
use App\Entity\Artist;
use App\Entity\Card;
use App\EventListener\CardSearchListener;
use App\EventListener\MeilisearchSyncListener;
use App\Repository\ArtistRepository;
use App\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'app:import:equinox',
    description: 'Import cards from AlteredEquinox community JSON format (SET/FACTION/NUM/file.json)',
)]
class ImportEquinoxCardsCommand extends Command
{
    private const BATCH_SIZE = 3000;

    // Maps underscore-locale keys (en_US) to dash-locale keys (en-us) used by builders
    private const LOCALE_MAP = [
        'en_US' => 'en-us',
        'fr_FR' => 'fr-fr',
        'de_DE' => 'de-de',
        'es_ES' => 'es-es',
        'it_IT' => 'it-it',
    ];

    // Rarity abbreviations in filenames → filter keys (e.g. ALT_BISE_B_AX_49_U_1 → position 5 = U)
    private const RARITY_ABBREV = [
        'COMMON'  => ['C'],
        'RARE'    => ['R1', 'R2'],
        'EXALTED' => ['E'],
        'UNIQUE'  => ['U'],
    ];

    /** @var array<string, Artist> — cleared after every em->clear() to avoid detached entities */
    private array $artistCache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CardRepository $cardRepository,
        private readonly ArtistRepository $artistRepository,
        private readonly CardBuilder $cardBuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('directory', InputArgument::OPTIONAL, 'Root directory containing JSON files', 'datas/equinox')
            ->addOption('set', 's', InputOption::VALUE_OPTIONAL, 'Import only a specific set directory (e.g. BISE, CORE, ALIZE)')
            ->addOption('faction', 'f', InputOption::VALUE_OPTIONAL, 'Import only a specific faction directory (e.g. AX, BR, LY, MU, OR, YZ, NE)')
            ->addOption('rarity', 'r', InputOption::VALUE_OPTIONAL, 'Comma-separated rarities to import (COMMON, RARE, EXALTED, UNIQUE)')
            ->addOption('non-unique', null, InputOption::VALUE_NONE, 'Shortcut for --rarity=COMMON,RARE,EXALTED')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse files and show what would be imported without writing to the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $startTime = microtime(true);

        $directory     = $input->getArgument('directory');
        $setFilter     = $input->getOption('set') ? strtoupper($input->getOption('set')) : null;
        $factionFilter = $input->getOption('faction') ? strtoupper($input->getOption('faction')) : null;
        $dryRun        = (bool) $input->getOption('dry-run');

        $rarityAbbrevs = null;
        $rarityOption  = $input->getOption('non-unique') ? 'COMMON,RARE,EXALTED' : $input->getOption('rarity');
        if ($rarityOption) {
            $rarityKeys    = array_map('strtoupper', array_map('trim', explode(',', $rarityOption)));
            $rarityAbbrevs = [];
            foreach ($rarityKeys as $key) {
                $rarityAbbrevs = array_merge($rarityAbbrevs, self::RARITY_ABBREV[$key] ?? []);
            }
        }

        $io->title('Equinox Card Import');

        $io->definitionList(
            ['Directory'      => $directory],
            ['Set filter'     => $setFilter ?? '(all)'],
            ['Faction filter' => $factionFilter ?? '(all)'],
            ['Rarity filter'  => $rarityAbbrevs ? implode(', ', $rarityAbbrevs) : '(all)' . ($input->getOption('non-unique') ? ' [--non-unique]' : '')],
            ['Dry run'        => $dryRun ? 'YES — no database writes' : 'no'],
        );

        if (!is_dir($directory)) {
            $io->error(sprintf('Directory not found: %s', $directory));
            return Command::FAILURE;
        }

        $this->em->getConnection()->getConfiguration()->setMiddlewares([]);

        // Disable search index sync during import — re-indexed in bulk afterwards
        MeilisearchSyncListener::$disabled = true;
        CardSearchListener::$disabled = true;

        $finder = new Finder();
        $finder->files()->name('*.json')->in($directory);

        if ($setFilter) {
            // Set directory is at depth 1: SET/…
            $finder->path(sprintf('/^%s\//', preg_quote($setFilter, '/')));
        }
        if ($factionFilter) {
            // Faction directory is at depth 2: SET/FACTION/…
            $finder->path(sprintf('/^[^\/]+\/%s\//', preg_quote($factionFilter, '/')));
        }

        $files = iterator_to_array($finder);
        $total = count($files);

        if ($total === 0) {
            $io->warning('No JSON files found matching the given filters.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('<info>Found %d file(s) to process.</info>', $total));
        $io->newLine();
        $io->progressStart($total);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = 0;
        $batch   = [];

        foreach ($files as $file) {
            try {
                $data      = json_decode($file->getContents(), true, 512, JSON_THROW_ON_ERROR);
                $alteredId = $data['id'] ?? null;
                $reference = $data['reference'] ?? $file->getFilename();

                if (!$alteredId) {
                    $io->writeln(sprintf(
                        "\n  <comment>[SKIP]</comment> %s — missing id field",
                        $file->getRelativePathname()
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    $skipped++;
                    $io->progressAdvance();
                    continue;
                }

                // Rarity filter: check position 5 of the underscore-split reference (ALT_BISE_B_AX_49_U_1 → U)
                if ($rarityAbbrevs !== null) {
                    $parts         = explode('_', $reference);
                    $rarityInRef   = $parts[5] ?? '';
                    $matchesRarity = false;
                    foreach ($rarityAbbrevs as $abbrev) {
                        if (str_starts_with($rarityInRef, $abbrev)) {
                            $matchesRarity = true;
                            break;
                        }
                    }
                    if (!$matchesRarity) {
                        $skipped++;
                        $io->progressAdvance();
                        continue;
                    }
                }

                $batch[$alteredId] = [
                    'data'      => $data,
                    'file'      => $file->getRelativePathname(),
                    'reference' => $reference,
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    if (!$dryRun) {
                        $this->processBatch($batch, $created, $updated, $errors, $io);
                    } else {
                        $created += count($batch);
                    }
                    $batch = [];
                }
            } catch (\Throwable $e) {
                $io->progressFinish();
                $io->error(sprintf('Fatal error parsing %s: %s', $file->getRelativePathname(), $e->getMessage()));
                if ($output->isVeryVerbose()) {
                    $io->writeln($e->getTraceAsString());
                }
                return Command::FAILURE;
            }

            $io->progressAdvance();
        }

        if (!empty($batch)) {
            if (!$dryRun) {
                try {
                    $this->processBatch($batch, $created, $updated, $errors, $io);
                } catch (\Throwable $e) {
                    $io->progressFinish();
                    $io->error(sprintf('Fatal error during final batch flush: %s', $e->getMessage()));
                    if ($output->isVeryVerbose()) {
                        $io->writeln($e->getTraceAsString());
                    }
                    return Command::FAILURE;
                }
            } else {
                $created += count($batch);
            }
        }

        $io->progressFinish();

        $elapsed = round(microtime(true) - $startTime, 2);
        $io->newLine();
        $io->success(sprintf(
            'Import complete in %.2fs — %d created, %d updated, %d skipped, %d errors.',
            $elapsed, $created, $updated, $skipped, $errors
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processBatch(array $batch, int &$created, int &$updated, int &$errors, SymfonyStyle $io): void
    {
        $alteredIds    = array_keys($batch);
        $alteredIdMap  = $this->cardRepository->findAlteredIdMap($alteredIds);
        $existingCards = $this->cardRepository->findByAlteredIds($alteredIdMap);

        // Pre-warm builder caches — 2 bulk SELECTs instead of N+1 per card
        $slugs = [];
        $abilityKeys = [];
        foreach ($batch as $entry) {
            $slugs[] = $this->cardBuilder->computeSlug($entry['data']);
            foreach ($this->extractEffectKeys($entry['data']['cardElements'] ?? []) as $slotKeys) {
                array_push($abilityKeys, ...$slotKeys);
            }
        }
        $this->cardBuilder->preloadCaches(array_unique($slugs), array_unique($abilityKeys));

        foreach ($batch as $alteredId => $entry) {
            $data      = $entry['data'];
            $filePath  = $entry['file'];
            $reference = $entry['reference'];

            try {
                $isNew = !isset($alteredIdMap[$alteredId]);
                $card  = $existingCards[$alteredId] ?? new Card();

                $action  = $isNew ? 'CREATE' : 'UPDATE';
                $name    = $data['name'] ?? '?';
                $set     = $data['cardSet']['reference'] ?? '?';
                $faction = $data['mainFaction']['reference'] ?? '?';
                $rarity  = $data['rarity']['reference'] ?? '?';
                $product = $data['cardProduct']['reference'] ?? '?';

                $io->writeln(sprintf(
                    "\n  [<info>%s</info>] <comment>%s</comment> — \"%s\" — %s/%s/%s (product: %s) — %s",
                    $action, $reference, $name, $set, $faction, $rarity, $product, $filePath
                ), OutputInterface::VERBOSITY_VERBOSE);

                $localizedPayloads = $this->buildLocalizedPayloads($data);

                // Single pass: builds CardGroup + gameplay stats once, then applies all locales
                $card = $this->cardBuilder->buildAllLocales($card, $localizedPayloads);

                $io->writeln(sprintf(
                    '    exclusive=%s  lowerPrice=%s  product=%s  serialized=%s',
                    $data['isExclusive'] ? 'yes' : 'no',
                    $data['lowerPrice'] ?? 'n/a',
                    $data['cardProduct']['reference'] ?? 'n/a',
                    $data['isSerialized'] ? 'yes' : 'no',
                ), OutputInterface::VERBOSITY_VERY_VERBOSE);

                // Illustrator — find or create Artist by nickName, link to card
                if (isset($data['illustrator']['nickName'])) {
                    $nickName = trim($data['illustrator']['nickName']);
                    if ($nickName !== '') {
                        $artist = $this->resolveArtist($nickName);
                        $card->addArtist($artist);
                        $io->writeln(sprintf('    🎨 illustrator: %s', $nickName), OutputInterface::VERBOSITY_VERY_VERBOSE);
                    }
                }

                $this->em->persist($card->getCardGroup());
                $this->em->persist($card);

                if ($isNew) {
                    $created++;
                } else {
                    $updated++;
                    $io->writeln(sprintf('    ↻ updated existing card alteredId=%s', $alteredId), OutputInterface::VERBOSITY_VERY_VERBOSE);
                }
            } catch (\Throwable $e) {
                $errors++;
                $io->writeln(sprintf("\n  [<error>ERROR</error>] %s — %s", $reference, $e->getMessage()));
                $io->writeln(sprintf('    File: %s', $filePath), OutputInterface::VERBOSITY_VERBOSE);
                if ($io->isVeryVerbose()) {
                    $io->writeln($e->getTraceAsString());
                }
            }
        }

        try {
            $this->cardBuilder->reconcileNewEffects($this->em);
            $this->em->flush();
            $io->writeln(sprintf(
                '  <info>Batch flushed</info> — running totals: created=%d updated=%d errors=%d',
                $created, $updated, $errors
            ), OutputInterface::VERBOSITY_VERBOSE);
        } catch (\Throwable $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->getConnection()->rollBack();
            }
            $this->em->clear();
            $this->cardBuilder->clearCache();
            throw new \RuntimeException(sprintf('Flush failed: %s', $e->getMessage()), 0, $e);
        }

        $this->em->clear();
        $this->artistCache = []; // detach all — will be re-fetched from DB next batch
        $this->cardBuilder->clearCache();
    }

    private function resolveArtist(string $nickName): Artist
    {
        if (isset($this->artistCache[$nickName])) {
            return $this->artistCache[$nickName];
        }

        $artist = $this->artistRepository->findOneByReference($nickName);

        if (!$artist) {
            $artist = new Artist();
            $artist->setReference($nickName);
            $artist->setName($nickName);
            $this->em->persist($artist);
        }

        return $this->artistCache[$nickName] = $artist;
    }

    /**
     * Converts an Equinox JSON payload (en_US-style locale keys, minimal translations)
     * into a map of locale → data array ready for CardBuilder::build().
     *
     * Translations in this format only carry {name, image, locale}; gameplay data is at root.
     * Every payload gets id + reference so CardBuilder never resets those to null on secondary
     * locale passes. The fr-fr payload additionally receives the fields that CardBuilder's fr-fr
     * block needs: collectorNumberFormatted, allImagePath, assets, transfuge detection data.
     */
    private function buildLocalizedPayloads(array $data): array
    {
        $allImagePath = $data['allImagePath'] ?? [];

        // CardBuilder accesses id/reference without array_key_exists — must be present in every payload
        $basePayload = [
            'id'                 => $data['id'],
            'reference'          => $data['reference'],
            'isSerialized'       => $data['isSerialized'] ?? false,
            'isParentSerialized' => $data['isParentSerialized'] ?? false,
            'isOwnerless'        => $data['isOwnerless'] ?? false,
            'isPublic'           => $data['isPublic'] ?? false,
        ];

        // fr-fr payload extras: fills CardBuilder's locale === 'fr-fr' block
        $frFrExtras = [
            'imagePath'                => $allImagePath['fr-fr'] ?? ($data['imagePath'] ?? null),
            'collectorNumberFormatted' => $data['collectorNumberFormatted'] ?? null,
            'allImagePath'             => $allImagePath,
            'assets'                   => $data['assets'] ?? null,
            'mainFaction'              => $data['mainFaction'] ?? null,
        ];

        // en-us payload = full root data, enriched with MAIN_EFFECT_KEYS from cardElements
        // so CardGroupBuilder uses cardEffect.reference (abilityKey) as the primary effect lookup
        $enUsData   = $data;
        $effectKeys = $this->extractEffectKeys($data['cardElements'] ?? []);
        if (!empty($effectKeys['MAIN_EFFECT'])) {
            $enUsData['elements']['MAIN_EFFECT_KEYS'] = $effectKeys['MAIN_EFFECT'];
        }
        if (!empty($effectKeys['ECHO_EFFECT'])) {
            $enUsData['elements']['ECHO_EFFECT_KEYS'] = $effectKeys['ECHO_EFFECT'];
        }

        // Extract per-locale effect display texts so CardGroupBuilder can set
        // CardGroupTranslation.mainEffect / echoEffect for every locale.
        // Without this, fr/de/es/it locales keep a stale or null mainEffect after an Equinox update.
        // en-us is NOT overridden: data['elements']['MAIN_EFFECT'] already holds the canonical
        // pre-formatted en text produced by Equinox (correct order + punctuation).
        $effectTexts = $this->extractEffectTextsPerLocale($data['cardElements'] ?? []);

        // Equinox loreEntries carry all locale texts inline under `translations`.
        // Normalize to per-locale format that CardGroupBuilder.buildLoreEntries() expects.
        $lorePerLocale = $this->normalizeLoreEntriesForLocales($data['loreEntries'] ?? []);
        $enUsData['loreEntries'] = $lorePerLocale['en-us'] ?? [];

        $payloads = ['en-us' => $enUsData];

        foreach ($data['translations'] ?? [] as $localeKey => $trans) {
            $locale = self::LOCALE_MAP[$localeKey] ?? strtolower(str_replace('_', '-', $localeKey));

            if ($locale === 'en-us') {
                continue;
            }

            $localeImage = $trans['image'] ?? ($allImagePath[$locale] ?? null);

            $payload = array_merge($basePayload, [
                'name'        => $trans['name'] ?? null,
                'imagePath'   => $localeImage,
                'loreEntries' => $lorePerLocale[$locale] ?? [],
            ]);

            if ($locale === 'fr-fr') {
                $payload = array_merge($payload, $frFrExtras);
            }

            if (isset($effectTexts['MAIN_EFFECT'][$locale])) {
                $payload['elements']['MAIN_EFFECT'] = $effectTexts['MAIN_EFFECT'][$locale];
            }
            if (isset($effectTexts['ECHO_EFFECT'][$locale])) {
                $payload['elements']['ECHO_EFFECT'] = $effectTexts['ECHO_EFFECT'][$locale];
            }

            $payloads[$locale] = $payload;
        }

        // Ensure fr-fr is always present (uses root data as fallback when absent from translations)
        if (!isset($payloads['fr-fr'])) {
            $frFrPayload = array_merge(
                $basePayload,
                ['name' => $data['name'] ?? null, 'loreEntries' => $lorePerLocale['fr-fr'] ?? []],
                $frFrExtras,
            );
            if (isset($effectTexts['MAIN_EFFECT']['fr-fr'])) {
                $frFrPayload['elements']['MAIN_EFFECT'] = $effectTexts['MAIN_EFFECT']['fr-fr'];
            }
            if (isset($effectTexts['ECHO_EFFECT']['fr-fr'])) {
                $frFrPayload['elements']['ECHO_EFFECT'] = $effectTexts['ECHO_EFFECT']['fr-fr'];
            }
            $payloads['fr-fr'] = $frFrPayload;
        }

        return $payloads;
    }

    /**
     * Converts Equinox loreEntries (translations inline) to the per-locale format that
     * CardGroupBuilder::buildLoreEntries() expects (loreEntryType + loreEntryElements[]).
     *
     * @return array<string, list<array>> locale (en-us, fr-fr, …) → normalized entries
     */
    private function normalizeLoreEntriesForLocales(array $loreEntries): array
    {
        $result = [];

        foreach ($loreEntries as $entry) {
            foreach (self::LOCALE_MAP as $apiLocale => $dashLocale) {
                $text = $entry['translations'][$apiLocale]['text'] ?? null;

                $result[$dashLocale][] = [
                    'id'                => $entry['id'] ?? null,
                    'loreEntryType'     => $entry['loreEntryElementType'] ?? null,
                    'loreEntryElements' => $text !== null ? [
                        [
                            'loreEntryElementType' => $entry['loreEntryElementType'] ?? null,
                            'text'                 => $text,
                        ],
                    ] : [],
                ];
            }
        }

        return $result;
    }

    /**
     * Extracts MAIN_EFFECT and ECHO_EFFECT display texts per locale from Equinox cardElements.
     *
     * Elements are collected BY TYPE (trigger / condition / output) and reassembled in
     * trigger → condition → output order — matching the order used in data['elements']['MAIN_EFFECT']
     * for en-us.  Trailing punctuation (—, :, …) is intentionally preserved so that joining
     * with a space produces the same readable compound sentence as the canonical en string.
     *
     * Slots (one per cardEffectDisplay) are joined with double space, matching the split used
     * by CardGroupBuilder (explode('  ', ...)).
     *
     * Cards that carry no inline translations (e.g. UNIQUE variants referencing shared effects)
     * produce no entry — CardGroupBuilder then leaves translation.mainEffect unchanged.
     *
     * @return array<string, array<string, string>> effectType → locale (en-us, fr-fr, …) → text
     */
    private function extractEffectTextsPerLocale(array $cardElements): array
    {
        $result = [];

        foreach ($cardElements as $cardElement) {
            $type = $cardElement['cardElementType']['reference'] ?? null;
            if (!in_array($type, ['MAIN_EFFECT', 'ECHO_EFFECT'], true)) {
                continue;
            }

            $displays = $cardElement['cardEffectDisplays'] ?? [];
            usort($displays, static fn($a, $b) => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

            $slotTexts = []; // dashLocale → list of per-slot texts

            foreach ($displays as $display) {
                $cardEffect = $display['cardEffect'] ?? null;
                if (!$cardEffect) {
                    continue;
                }

                // Collect texts keyed by element type so we can reorder them correctly.
                $byType = []; // dashLocale → ['trigger' => text, 'condition' => text, 'output' => text]

                foreach ($cardEffect['cardEffectElements'] ?? [] as $el) {
                    $elType = strtolower($el['type'] ?? '');
                    foreach (self::LOCALE_MAP as $apiLocale => $dashLocale) {
                        $text = $el['translations'][$apiLocale]['text'] ?? null;
                        if ($text === null || trim($text) === '') {
                            continue;
                        }
                        // Last value for a given type wins (shouldn't happen in practice).
                        $byType[$dashLocale][$elType] = $text;
                    }
                }

                // Reassemble in trigger → condition → output order.
                // This mirrors the ordering Equinox uses in data['elements']['MAIN_EFFECT'] for en-us,
                // so the resulting fr/de/… text reads in the same natural sentence structure.
                foreach ($byType as $dashLocale => $types) {
                    $ordered = array_filter([
                        $types['trigger']   ?? null,
                        $types['condition'] ?? null,
                        $types['output']    ?? null,
                    ]);
                    if (!empty($ordered)) {
                        $slotTexts[$dashLocale][] = implode(' ', $ordered);
                    }
                }
            }

            foreach ($slotTexts as $dashLocale => $slots) {
                $result[$type][$dashLocale] = implode('  ', $slots);
            }
        }

        return $result;
    }

    /**
     * Extracts cardEffect.reference values (= MainEffect.abilityKey) per effect slot.
     * Returns: ['MAIN_EFFECT' => ['1_191_495', '1_97_362'], 'ECHO_EFFECT' => ['191_192_264']]
     */
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

}
