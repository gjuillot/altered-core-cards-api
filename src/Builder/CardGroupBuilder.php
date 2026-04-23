<?php

namespace App\Builder;

use App\Entity\CardGroup;
use App\Entity\CardGroupTranslation;
use App\Entity\CardHistoryStatus;
use App\Entity\CardRuling;
use App\Entity\CardSubType;
use App\Entity\CardType;
use App\Entity\LoreEntry;
use App\Entity\MainEffect;
use App\Repository\CardGroupRepository;
use App\Repository\CardHistoryStatusRepository;
use App\Repository\CardSubTypeRepository;
use App\Repository\CardTypeRepository;
use App\Repository\FactionRepository;
use App\Repository\LoreEntryRepository;
use App\Repository\MainEffectRepository;
use App\Repository\RarityRepository;
use App\Service\EffectParser;
use Doctrine\ORM\EntityManagerInterface;

class CardGroupBuilder
{
    /** @var array<string, CardGroup> */
    private array $groupCache = [];

    /** @var array<string, true>  slug+locale pairs already built this batch */
    private array $builtCache = [];

    /** @var array<string, CardType> */
    private array $cardTypeCache = [];

    /** @var array<string, CardSubType> */
    private array $subTypeCache = [];

    /** @var array<string, CardHistoryStatus> */
    private array $historyStatusCache = [];

    /** @var array<string, MainEffect> */
    private array $effectCache = [];

    /** @var array<string, MainEffect> new (un-persisted) effects created this batch */
    private array $newEffects = [];

    public function __construct(
        private readonly FactionRepository           $factionRepository,
        private readonly CardTypeRepository          $cardTypeRepository,
        private readonly CardSubTypeRepository       $cardSubTypeRepository,
        private readonly CardHistoryStatusRepository $cardHistoryStatusRepository,
        private readonly MainEffectRepository        $mainEffectRepository,
        private readonly CardGroupRepository         $cardGroupRepository,
        private readonly LoreEntryRepository         $loreEntryRepository,
        private readonly RarityRepository            $rarityRepository,
        private readonly EffectParser                $effectParser,
    ) {}

    public function clearCache(): void
    {
        $this->groupCache         = [];
        $this->builtCache         = [];
        $this->cardTypeCache      = [];
        $this->subTypeCache       = [];
        $this->historyStatusCache = [];
        $this->effectCache        = [];
        $this->newEffects         = [];
    }

    /**
     * Before flushing a batch: ensure every MainEffect in this batch (new or existing)
     * has a safe DB representation with no composite collision.
     *
     * - New effects (id=null): INSERT via DBAL, then load the managed entity.
     * - Existing effects modified this batch: detect UPDATE collisions via COALESCE SELECT.
     * In both cases, detach the stale Doctrine entity and redirect CardGroup references
     * to the correct DB-backed entity. This prevents uniq_main_effect_texts violations
     * from both INSERT and UPDATE paths.
     */
    public function reconcileNewEffects(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();

        $selectSql = "SELECT id FROM main_effect
                      WHERE COALESCE(text_fr,'') = :fr AND COALESCE(text_en,'') = :en
                        AND COALESCE(text_de,'') = :de AND COALESCE(text_es,'') = :es
                        AND COALESCE(text_it,'') = :it
                      LIMIT 1";

        foreach ($this->effectCache as $text => $effect) {
            $params = [
                'fr' => $effect->getTextFr() ?? '',
                'en' => $effect->getTextEn() ?? '',
                'de' => $effect->getTextDe() ?? '',
                'es' => $effect->getTextEs() ?? '',
                'it' => $effect->getTextIt() ?? '',
            ];

            $foundId = $conn->fetchOne($selectSql, $params);

            if (!$foundId && $effect->getId() === null) {
                // New effect not in DB yet — INSERT directly via DBAL.
                // ON CONFLICT DO NOTHING guards against the edge case where the composite
                // was inserted by a concurrent process between our SELECT and this INSERT.
                $conn->executeStatement(
                    'INSERT INTO main_effect (text_fr, text_en, text_de, text_es, text_it, keywords)
                     VALUES (:fr_v, :en_v, :de_v, :es_v, :it_v, :kw)
                     ON CONFLICT DO NOTHING',
                    [
                        'fr_v' => $effect->getTextFr(),
                        'en_v' => $effect->getTextEn(),
                        'de_v' => $effect->getTextDe(),
                        'es_v' => $effect->getTextEs(),
                        'it_v' => $effect->getTextIt(),
                        'kw'   => $effect->getKeywords() !== null ? json_encode($effect->getKeywords()) : null,
                    ]
                );
                $foundId = $conn->fetchOne($selectSql, $params);
            }

            if (!$foundId) {
                continue;
            }

            $foundId = (int) $foundId;

            // No conflict: this entity IS the row that matches its composite
            if ($effect->getId() === $foundId) {
                continue;
            }

            // Conflict — detach stale entity, load the canonical row
            $em->detach($effect);

            $managed = $em->find(MainEffect::class, $foundId);
            if ($managed === null) {
                continue;
            }

            foreach ($this->groupCache as $group) {
                if ($group->getEffect1() === $effect) $group->setEffect1($managed);
                if ($group->getEffect2() === $effect) $group->setEffect2($managed);
                if ($group->getEffect3() === $effect) $group->setEffect3($managed);
            }

            $this->effectCache[$text] = $managed;
        }

        $this->newEffects = [];
    }

    /**
     * Find or create the CardGroup for the given card data.
     * The slug is derived from faction code + zero-padded card number extracted from the reference.
     */
    public function findOrCreate(array $data): CardGroup
    {
        $slug = $this->buildSlug($data);

        if (isset($this->groupCache[$slug])) {
            return $this->groupCache[$slug];
        }

        $group = $this->cardGroupRepository->findOneBy(['slug' => $slug]);
        if (!$group) {
            $group = new CardGroup();
            $group->setSlug($slug);
        }

        return $this->groupCache[$slug] = $group;
    }

    public function build(CardGroup $group, array $data, string $locale): CardGroup
    {
        $cacheKey = $group->getSlug() . ':' . $locale;
        if (isset($this->builtCache[$cacheKey])) {
            return $group;
        }
        $this->builtCache[$cacheKey] = true;

        $language = explode('-', $locale)[0];

        // Gameplay flags — canonical from any locale (fr-fr is processed first)
        $group->setIsBanned((bool) ($data['isBanned'] ?? false));
        $group->setIsErrated((bool) ($data['isErrated'] ?? false));
        $group->setIsSuspended((bool) ($data['isSuspended'] ?? false));

        if (!$group->getRarity() && isset($data['rarity']['reference'])) {
            $rarity = $this->rarityRepository->findOneByReference($data['rarity']['reference']);
            if ($rarity) {
                $group->setRarity($rarity);
            }
        }

        if (isset($data['cardHistoryStatus']['reference'])) {
            $group->setCardHistoryStatus($this->findOrCreateHistoryStatus($data['cardHistoryStatus'], $locale));
        } else {
            $group->setCardHistoryStatus(null);
        }

        if (array_key_exists('cardType', $data) && isset($data['cardType']['reference'])) {
            $group->setCardType($this->findOrCreateCardType($data['cardType'], $locale));
        }

        if (array_key_exists('cardSubTypes', $data) && is_array($data['cardSubTypes'])) {
            foreach ($data['cardSubTypes'] as $subTypeData) {
                if (!isset($subTypeData['reference'])) continue;
                $group->addSubType($this->findOrCreateCardSubType($subTypeData, $locale));
            }
        }

        if (isset($data['mainFaction']['reference'])) {
            $faction = $this->factionRepository->findOneByCode($data['mainFaction']['reference']);
            if ($faction) {
                $group->setFaction($faction);
            }
        }

        // Gameplay stats — set from en-us (canonical) only
        if ($locale === 'en-us') {
            if (array_key_exists('elements', $data)) {
                $elements = $data['elements'];
                $group->setMainCost(isset($elements['MAIN_COST']) ? (int) $elements['MAIN_COST'] : null);
                $group->setRecallCost(isset($elements['RECALL_COST']) ? (int) $elements['RECALL_COST'] : null);
                $group->setOceanPower(isset($elements['OCEAN_POWER']) ? (int) $elements['OCEAN_POWER'] : null);
                $group->setMountainPower(isset($elements['MOUNTAIN_POWER']) ? (int) $elements['MOUNTAIN_POWER'] : null);
                $group->setForestPower(isset($elements['FOREST_POWER']) ? (int) $elements['FOREST_POWER'] : null);
                $group->setPermanent($elements['PERMANENT'] ?? null);

                if (array_key_exists('MAIN_EFFECT', $elements)) {
                    $parts = array_values(array_filter(array_map('trim', explode('  ', $elements['MAIN_EFFECT']))));
                    $group->setEffect1($this->findOrCreateEffect($parts[0] ?? null, 'en'));
                    $group->setEffect2($this->findOrCreateEffect($parts[1] ?? null, 'en'));
                    $group->setEffect3($this->findOrCreateEffect($parts[2] ?? null, 'en'));
                }
            }
        }

        // CardGroup translation (name + effects per locale)
        $translation = $group->getTranslation($language);
        if (!$translation) {
            $translation = new CardGroupTranslation();
            $translation->setLocale($language);
            $translation->setCardGroup($group);
            $group->getTranslations()->add($translation);
        }

        if (array_key_exists('name', $data)) {
            $translation->setName($data['name']);
        }

        if (array_key_exists('elements', $data)) {
            $elements = $data['elements'];
            if (array_key_exists('MAIN_EFFECT', $elements)) {
                $translation->setMainEffect($elements['MAIN_EFFECT']);

                $parts  = array_values(array_filter(array_map('trim', explode('  ', $elements['MAIN_EFFECT']))));
                $setter = 'setText' . ucfirst($language);

                if (isset($parts[0]) && $group->getEffect1() && method_exists($group->getEffect1(), $setter)) {
                    $group->getEffect1()->{$setter}($parts[0]);
                }
                if (isset($parts[1]) && $group->getEffect2() && method_exists($group->getEffect2(), $setter)) {
                    $group->getEffect2()->{$setter}($parts[1]);
                }
                if (isset($parts[2]) && $group->getEffect3() && method_exists($group->getEffect3(), $setter)) {
                    $group->getEffect3()->{$setter}($parts[2]);
                }
            } else {
                $translation->setMainEffect(null);
                if ($locale === 'en-us') {
                    $group->setEffect1(null);
                    $group->setEffect2(null);
                    $group->setEffect3(null);
                }
            }
            if (array_key_exists('ECHO_EFFECT', $elements)) {
                $translation->setEchoEffect($elements['ECHO_EFFECT']);
            } else {
                $translation->setEchoEffect(null);
            }
        }

        // Rulings
        if (array_key_exists('cardRulings', $data)) {
            $this->buildCardRulings($group, $data['cardRulings'], $locale);
        }

        // Lore entries
        if (array_key_exists('loreEntries', $data)) {
            $this->buildLoreEntries($group, $data['loreEntries'], $locale);
        }

        return $group;
    }

    private function buildSlug(array $data): string
    {
        // Reference format: ALT_CORE_B_OR_17_U_185
        // parts[3]=faction, parts[4]=card number, parts[5]=rarity, parts[6]=variant (U only)
        $parts      = explode('_', $data['reference'] ?? '');
        $faction    = strtoupper($parts[3] ?? ($data['mainFaction']['reference'] ?? 'NEUTRAL'));
        $cardNumber = (int) ($parts[4] ?? 0);
        $rarity     = strtoupper($parts[5] ?? 'C');

        // Unique cards: each variant has its own CardGroup (different effects per copy)
        if ($rarity === 'U' && isset($parts[6]) && $parts[6] !== '') {
            return sprintf('%s-%03d-U-%s', $faction, $cardNumber, $parts[6]);
        }

        // Common / Rare: group all reprints together
        return sprintf('%s-%03d-%s', $faction, $cardNumber, $rarity);
    }

    private function buildCardRulings(CardGroup $group, array $rulings, string $locale): void
    {
        $language = explode('-', $locale)[0];

        foreach ($group->getCardRulings() as $existing) {
            if ($existing->getLocale() === $language) {
                $group->getCardRulings()->removeElement($existing);
            }
        }

        foreach ($rulings as $rulingData) {
            $ruling = new CardRuling();
            $ruling->setCardGroup($group);
            $ruling->setLocale($language);
            $ruling->setQuestion($rulingData['question'] ?? '');
            $ruling->setAnswer($rulingData['answer'] ?? '');
            $ruling->setEventFormat($rulingData['eventFormat'] ?? null);

            if (isset($rulingData['createdAt'])) {
                try {
                    $ruling->setRulingDate(new \DateTimeImmutable($rulingData['createdAt']));
                } catch (\Exception) {}
            }

            $group->getCardRulings()->add($ruling);
        }
    }

    private function buildLoreEntries(CardGroup $group, array $entries, string $locale): void
    {
        $language = explode('-', $locale)[0];

        foreach ($entries as $entryData) {
            $alteredId = $entryData['id'] ?? null;
            if (!$alteredId) continue;

            // Find existing lore entry for this group+alteredId+locale
            $entry = null;
            foreach ($group->getLoreEntries() as $existing) {
                if ($existing->getAlteredId() === $alteredId && $existing->getLocale() === $language) {
                    $entry = $existing;
                    break;
                }
            }

            if (!$entry) {
                $entry = new LoreEntry();
                $entry->setAlteredId($alteredId);
                $entry->setLocale($language);
                $entry->setCardGroup($group);
                $group->getLoreEntries()->add($entry);
            }

            $entry->setType($entryData['loreEntryType']['reference'] ?? null);

            $elements = [];
            foreach ($entryData['loreEntryElements'] ?? [] as $element) {
                $elements[] = [
                    'type' => $element['loreEntryElementType']['reference'] ?? null,
                    'text' => $element['text'] ?? null,
                ];
            }
            $entry->setElements($elements);
        }
    }

    private function findOrCreateEffect(?string $text, string $locale): ?MainEffect
    {
        if ($text === null) return null;

        $text = trim($text);
        if ($text === '') return null;

        if (isset($this->effectCache[$text])) {
            return $this->effectCache[$text];
        }

        $finder = 'findOneByText' . ucfirst($locale);
        $effect = $this->mainEffectRepository->{$finder}($text);

        if (!$effect) {
            $effect = new MainEffect();
            $setter = 'setText' . ucfirst($locale);
            $effect->{$setter}($text);
            $this->newEffects[$text] = $effect;
        }

        // Parse keywords from the French text (canonical locale)
        if ($locale === 'en' && $effect->getKeywords() === null) {
            $keywords = $this->effectParser->parseKeywords($text);
            $effect->setKeywords($keywords ?: null);
        }

        return $this->effectCache[$text] = $effect;
    }

    private function findOrCreateCardType(array $data, string $locale): CardType
    {
        $reference = $data['reference'];

        if (!isset($this->cardTypeCache[$reference])) {
            $cardType = $this->cardTypeRepository->findOneByReference($reference);
            if (!$cardType) {
                $cardType = new CardType();
                $cardType->setReference($reference);
            }
            $this->cardTypeCache[$reference] = $cardType;
        }

        $cardType = $this->cardTypeCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($cardType, $setter)) {
            $cardType->{$setter}($data['name']);
        }

        return $cardType;
    }

    private function findOrCreateCardSubType(array $data, string $locale): CardSubType
    {
        $reference = $data['reference'];

        if (!isset($this->subTypeCache[$reference])) {
            $subType = $this->cardSubTypeRepository->findOneByReference($reference);
            if (!$subType) {
                $subType = new CardSubType();
                $subType->setReference($reference);
            }
            $this->subTypeCache[$reference] = $subType;
        }

        $subType  = $this->subTypeCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($subType, $setter)) {
            $subType->{$setter}($data['name']);
        }

        return $subType;
    }

    private function findOrCreateHistoryStatus(array $data, string $locale): CardHistoryStatus
    {
        $reference = $data['reference'];

        if (!isset($this->historyStatusCache[$reference])) {
            $status = $this->cardHistoryStatusRepository->findOneByReference($reference);
            if (!$status) {
                $status = new CardHistoryStatus();
                $status->setReference($reference);
            }
            $this->historyStatusCache[$reference] = $status;
        }

        $status   = $this->historyStatusCache[$reference];
        $language = explode('-', $locale)[0];
        $setter   = 'setName' . ucfirst($language);
        if (isset($data['name']) && method_exists($status, $setter)) {
            $status->{$setter}($data['name']);
        }

        return $status;
    }
}
