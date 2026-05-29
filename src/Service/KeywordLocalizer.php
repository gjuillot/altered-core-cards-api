<?php

namespace App\Service;

/**
 * Replaces bracketed keyword codes in effect texts with their localized display names.
 *
 * Handles:
 *  - Simple codes:       [FLEETING]      → [Fugace] (fr)
 *  - Numeric variants:  [TOUGH_2]       → [Coriace 2] (fr)
 *  - Suffix variants:   [FLEETING_CHAR] → [Fugace] (fr)  — strips grammar suffix
 *  - Token names:       [AEROLITH]      → [Aerolith] (all locales — proper noun)
 *  - Dynamic vars:      $[BB]           → left unchanged  ($ prefix = runtime value)
 *  - Unknown codes:     left unchanged
 */
final class KeywordLocalizer
{
    /**
     * EN_CODE => [locale => displayName]
     * Codes are the Equinox cardKeyword.reference values (always English uppercase).
     * DE/IT/ES marked as "?" are verified FR+EN only — enrich when Equinox JSONs are available.
     */
    private const MAP = [
        // ── Passive keywords — verified all 5 locales ────────────────────────
        'FLEETING'           => ['fr' => 'Fugace',              'en' => 'Fleeting',           'de' => 'Vergänglich',      'it' => 'Fugace',           'es' => 'Fugacidad'],
        'GIGANTIC'           => ['fr' => 'Gigantesque',         'en' => 'Gigantic',           'de' => 'Gigantisch',       'it' => 'Colossale',        'es' => 'Gigante'],

        // ── Passive keywords — FR + EN (DE/IT/ES fallback to EN) ─────────────
        'SCOUT'              => ['fr' => 'Repérage',            'en' => 'Scout'],
        'TOUGH'              => ['fr' => 'Coriace',             'en' => 'Tough'],
        'DEFENDER'           => ['fr' => 'Défenseur',           'en' => 'Defender'],
        'ETERNAL'            => ['fr' => 'Éternel',             'en' => 'Eternal'],
        'ANCHORED'           => ['fr' => 'Ancré',               'en' => 'Anchored'],
        'ASLEEP'             => ['fr' => 'Endormi',             'en' => 'Asleep'],
        'BOOSTED'            => ['fr' => 'Boosté',              'en' => 'Boosted'],
        'COOLDOWN'           => ['fr' => 'Rafraîchissement',    'en' => 'Cooldown'],
        'SEASONED'           => ['fr' => 'Aguerri',             'en' => 'Seasoned'],
        'COMPLETED'          => ['fr' => 'Accompli',            'en' => 'Completed'],
        'AUGMENT'            => ['fr' => 'Augmentation',        'en' => 'Augment'],
        'IN_CONTACT'         => ['fr' => 'en contact',          'en' => 'in contact'],
        'AFTER_YOU'          => ['fr' => 'Après vous',          'en' => 'After You'],

        // ── Action keywords ────────────────────────────────────────────────────
        'SABOTAGE'           => ['fr' => 'Sabotez',             'en' => 'Sabotage'],
        'RESUPPLY'           => ['fr' => 'Ravitaillez',         'en' => 'Resupply'],
        'EXHAUSTED_RESUPPLY' => ['fr' => 'Ravitaillez Épuisé',  'en' => 'Exhausted Resupply'],
        'RUSH'               => ['fr' => 'Foncer',              'en' => 'Rush'],
        'GIFT'               => ['fr' => 'Don',                 'en' => 'Gift'],

        // ── Ascend mechanic ───────────────────────────────────────────────────
        'ASCEND'             => ['fr' => 'Élève',               'en' => 'Ascend'],

        // ── Token / creature proper nouns (same across all locales) ───────────
        'AEROLITH'           => ['fr' => 'Aerolith',            'en' => 'Aerolith'],
        'BOODA'              => ['fr' => 'Booda',               'en' => 'Booda'],
        'BRASSBUG'           => ['fr' => 'Brassbug',            'en' => 'Brassbug'],
        'DRAGON_SHADE'       => ['fr' => 'Dragon Shade',        'en' => 'Dragon Shade'],
        'HALUA'              => ['fr' => 'Halua',               'en' => 'Halua'],
        'MANASEED'           => ['fr' => 'Manaseed',            'en' => 'Manaseed'],
        'MANA_MOTH'          => ['fr' => 'Mana Moth',           'en' => 'Mana Moth'],
        'ORDIS_RECRUIT'      => ['fr' => 'Recrue Ordis',        'en' => 'Ordis Recruit'],
        'RUST'               => ['fr' => 'Rouille',             'en' => 'Rust'],
        'WOOLLYBACK'         => ['fr' => 'Woollyback',          'en' => 'Woollyback'],
    ];

    /**
     * Grammar/conjugation variants → base keyword in MAP.
     * Covers plural, past participle, infinitive, imperative, gender-agreement forms.
     */
    private const SUFFIX_MAP = [
        // FLEETING
        'FLEETING_CHAR'          => 'FLEETING',
        // BOOSTED
        'BOOSTED_CHA_P'          => 'BOOSTED',
        'BOOSTED_TKN_P'          => 'BOOSTED',
        // DEFENDER
        'DEFENDER_CHA_P'         => 'DEFENDER',
        'DEFENDER_TKN_P'         => 'DEFENDER',
        'DEFENDER_FS'            => 'DEFENDER',
        // ANCHORED
        'ANCHORED_CHA_P'         => 'ANCHORED',
        'ANCHORED_FS'            => 'ANCHORED',
        // ASLEEP
        'ASLEEP_CHA_P'           => 'ASLEEP',
        // ETERNAL
        'ETERNAL_FS'             => 'ETERNAL',
        // GIGANTIC
        'GIGANTIC_TKN_P'         => 'GIGANTIC',
        // SEASONED
        'SEASONED_CHA_P'         => 'SEASONED',
        // COMPLETED
        'COMPLETED_LOW'          => 'COMPLETED',
        // AUGMENT
        'AUGMENT_IMP'            => 'AUGMENT',
        // RESUPPLY
        'RESUPPLIES'             => 'RESUPPLY',
        'RESUPPLY_T'             => 'RESUPPLY',
        'RESUPPLY_LOW'           => 'RESUPPLY',
        'RESUPPLY_INF'           => 'RESUPPLY',
        // EXHAUSTED_RESUPPLY
        'EXHAUSTED_RESUPPLIES'   => 'EXHAUSTED_RESUPPLY',
        'EXHAUSTED_RESUPPLY_LOW' => 'EXHAUSTED_RESUPPLY',
        'EXHAUSTED_RESUPPLY_INF' => 'EXHAUSTED_RESUPPLY',
        // SABOTAGE
        'SABOTAGE_LOW'           => 'SABOTAGE',
        'SABOTAGE_INF'           => 'SABOTAGE',
        // TOUGH
        'TOUGH_FS_X'             => 'TOUGH',
        'TOUGH_X'                => 'TOUGH',
        'TOUGH_CHA_P_1'          => 'TOUGH',
        'TOUGH_CHA_P_2'          => 'TOUGH',
        'TOUGH_PER_P_1'          => 'TOUGH',
        // ASCEND conjugations
        'ASCENDED_P'             => 'ASCEND',
        'ASCENDED_S'             => 'ASCEND',
        'ASCENDED_Z'             => 'ASCEND',
        'ASCENDS'                => 'ASCEND',
        'ASCEND_INF'             => 'ASCEND',
        'DUE_TO_ASCENSION'       => 'ASCEND',
    ];

    public static function localize(?string $text, string $locale): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        $lang = match ($locale) {
            'fr', 'fr-fr' => 'fr',
            'de', 'de-de' => 'de',
            'it', 'it-it' => 'it',
            'es', 'es-es' => 'es',
            default       => 'en',
        };

        return preg_replace_callback(
            '/(?<!\$)\[([A-Z][A-Z0-9_]*)\]/',
            static function (array $m) use ($lang): string {
                $code = $m[1];

                // [TOUGH_2] / [SCOUT_3] — numeric parametric variants
                if (preg_match('/^(TOUGH|SCOUT)_(\d+)$/', $code, $pm)) {
                    $base  = self::MAP[$pm[1]] ?? null;
                    $value = $pm[2];
                    if ($base !== null) {
                        $name = $base[$lang] ?? $base['en'];
                        return "[$name $value]";
                    }
                }

                // Grammar/conjugation suffix variants
                $resolved = self::SUFFIX_MAP[$code] ?? null;
                if ($resolved !== null) {
                    $code = $resolved;
                }

                $trans = self::MAP[$code] ?? null;
                if ($trans === null) {
                    return $m[0]; // unknown — leave as-is
                }

                $name = $trans[$lang] ?? $trans['en'];
                return "[$name]";
            },
            $text
        );
    }
}
