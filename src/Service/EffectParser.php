<?php

namespace App\Service;

class EffectParser
{
    // Trigger types
    public const TRIGGER_H          = 'H';
    public const TRIGGER_J          = 'J';
    public const TRIGGER_R          = 'R';
    public const TRIGGER_T          = 'T';
    public const TRIGGER_TRIGGERED  = 'TRIGGERED';
    public const TRIGGER_CREPUSCULE = 'CREPUSCULE';
    public const TRIGGER_MIDI       = 'MIDI';
    public const TRIGGER_STATIC     = 'STATIC';
    public const TRIGGER_KEYWORD    = 'KEYWORD';
    public const TRIGGER_SORT       = 'SORT';

    // Keyword names
    public const KW_REPERAGE            = 'REPERAGE';
    public const KW_CORIACE             = 'CORIACE';
    public const KW_GIGANTESQUE         = 'GIGANTESQUE';
    public const KW_DEFENSEUR           = 'DEFENSEUR';
    public const KW_AGUERRI             = 'AGUERRI';
    public const KW_RAFRAICHISSEMENT    = 'RAFRAICHISSEMENT';
    public const KW_FUGACE              = 'FUGACE';
    public const KW_ETERNEL             = 'ETERNEL';
    public const KW_ANCRE               = 'ANCRE';
    public const KW_ENDORMI             = 'ENDORMI';
    public const KW_BOOSTE              = 'BOOSTE';
    // Action keywords
    public const KW_SABOTEZ             = 'SABOTEZ';
    public const KW_RAVITAILLEZ         = 'RAVITAILLEZ';
    public const KW_RAVITAILLEZ_EPUISE  = 'RAVITAILLEZ_EPUISE';
    public const KW_FONCER              = 'FONCER';
    public const KW_DON                 = 'DON';

    private const PURE_KEYWORD_PATTERNS = [
        '/^\#?\[FLEETING\]/u'              => self::KW_FUGACE,
        '/^\#?\[ETERNAL\]/u'               => self::KW_ETERNEL,
        '/^\#?\[GIGANTIC\]/u'              => self::KW_GIGANTESQUE,
        '/^\#?\[DEFENDER\]/u'              => self::KW_DEFENSEUR,
        '/^\#?\[COOLDOWN\]/u'              => self::KW_RAFRAICHISSEMENT,
        '/^\#?\[SCOUT_\d+\]/u'             => self::KW_REPERAGE,
        '/^\#?\[TOUGH(?:_\d+)?\]/u'        => self::KW_CORIACE,
        '/^\#?\[ANCHORED\]/u'              => self::KW_ANCRE,
        '/^\#?\[ASLEEP\]/u'                => self::KW_ENDORMI,
        '/^\#?\[BOOSTED\]/u'               => self::KW_BOOSTE,
        '/^\#?\[EXHAUSTED_RESUPPLY\]/u'    => null, // action, not a keyword
        '/^\#?\[RESUPPLY\]/u'              => null, // action, not a keyword
        '/^\#?\[SABOTAGE\]/u'              => null, // action, not a keyword
        '/^\#?\[RUSH\]/u'                  => null, // action, not a keyword
        '/^\#?\[GIFT\]/u'                  => null, // action, not a keyword
    ];

    /**
     * Extract the condition part from the French text, or null if none.
     *
     * Examples:
     *   "Lorsque je vais en Réserve — []effet"   → "je vais en Réserve"
     *   "{H} Si j'ai 1 boost : Je gagne..."      → "Si j'ai 1 boost"
     *   "[]Si vous contrôlez un jeton : effet"   → "Si vous contrôlez un jeton"
     *   "{H} Chaque joueur pioche une carte."    → null
     */
    public function parseCondition(string $text): ?string
    {
        $clean = ltrim($text, '#');

        // Strip trigger prefix
        $clean = preg_replace('/^\{[HJRTDI]\}\s*(\[\])?\s*/u', '', $clean);
        $clean = preg_replace('/^\[\]\[\]\s*/u', '', $clean);
        $clean = preg_replace('/^\[\]\s*/u', '', $clean);
        $clean = preg_replace('/^Lorsqu[\'e]\s*/ui', '', $clean);
        $clean = preg_replace('/^Quand\s+/ui', '', $clean);
        $clean = preg_replace('/^Au Crépuscule\s*[—-]\s*/ui', '', $clean);
        $clean = preg_replace('/^À Midi\s*[—-]\s*/ui', '', $clean);
        $clean = trim($clean);

        // Lorsque X — Y  →  X is the condition
        // The em dash may be preceded by a non-breaking space (\u00A0)
        if (preg_match('/^(.+?)\s*\x{2014}\s*/u', $clean, $m)) {
            return trim($m[1]);
        }

        // Si/S'/Sauf/À moins X : Y  →  X is the condition
        // The colon may also be preceded by a non-breaking space
        if (preg_match('/^(.+?)\s*:\s+/u', $clean, $m)) {
            $before = trim($m[1]);
            if (preg_match('/^(Si |S\'|Sauf |À moins|Unless|If )/u', $before)) {
                return $before;
            }
        }

        return null;
    }

    /**
     * Determine the trigger_type from the French text.
     */
    public function parseTriggerType(string $text): string
    {
        $clean = ltrim($text, '#');

        if (str_starts_with($clean, '{H}')) return self::TRIGGER_H;
        if (str_starts_with($clean, '{J}')) return self::TRIGGER_J;
        if (str_starts_with($clean, '{R}')) return self::TRIGGER_R;
        if (str_starts_with($clean, '{T}')) return self::TRIGGER_T;
        if (str_starts_with($clean, 'Au Crépuscule')) return self::TRIGGER_CREPUSCULE;
        if (str_starts_with($clean, 'À Midi')) return self::TRIGGER_MIDI;
        if (str_starts_with($clean, 'Lorsqu') || str_starts_with($clean, 'Quand ')) return self::TRIGGER_TRIGGERED;

        if (str_starts_with($clean, '[]')) return self::TRIGGER_STATIC;

        // Pure keyword effect: starts with [Keyword] or [[Keyword]]
        foreach (self::PURE_KEYWORD_PATTERNS as $pattern => $kw) {
            if (preg_match($pattern, $clean)) {
                return $kw !== null ? self::TRIGGER_KEYWORD : self::TRIGGER_SORT;
            }
        }

        return self::TRIGGER_SORT;
    }

    /**
     * Extract all keywords from the text.
     * Returns array of ['k' => 'KEYWORD_NAME', 'v' => int|null]
     * Texts use uppercase English codes ([SCOUT_1], [TOUGH_3], [FLEETING], etc.) in all locales.
     */
    public function parseKeywords(string $text): array
    {
        $keywords = [];
        $seen     = [];

        // [SCOUT_N] — value is N
        if (preg_match_all('/\[SCOUT_(\d+)\]/u', $text, $m)) {
            foreach ($m[1] as $val) {
                $key = self::KW_REPERAGE . ':' . $val;
                if (!isset($seen[$key])) {
                    $keywords[] = ['k' => self::KW_REPERAGE, 'v' => (int) $val];
                    $seen[$key] = true;
                }
            }
        }

        // [TOUGH_N] — value is N
        if (preg_match_all('/\[TOUGH_(\d+)\]/u', $text, $m)) {
            foreach ($m[1] as $val) {
                $key = self::KW_CORIACE . ':' . $val;
                if (!isset($seen[$key])) {
                    $keywords[] = ['k' => self::KW_CORIACE, 'v' => (int) $val];
                    $seen[$key] = true;
                }
            }
        }

        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[GIGANTIC\]/u', self::KW_GIGANTESQUE);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[DEFENDER\]/u', self::KW_DEFENSEUR);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[COOLDOWN\]/u', self::KW_RAFRAICHISSEMENT);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[FLEETING\]/u', self::KW_FUGACE);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[ETERNAL\]/u', self::KW_ETERNEL);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[ANCHORED\]/u', self::KW_ANCRE);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[ASLEEP\]/u', self::KW_ENDORMI);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[BOOSTED\]/u', self::KW_BOOSTE);
        // Action keywords — EXHAUSTED_RESUPPLY checked before RESUPPLY
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[EXHAUSTED_RESUPPLY\]/u', self::KW_RAVITAILLEZ_EPUISE);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[RESUPPLY\]/u', self::KW_RAVITAILLEZ);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[SABOTAGE\]/u', self::KW_SABOTEZ);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[RUSH\]/u', self::KW_FONCER);
        $this->extractSimpleKeyword($text, $keywords, $seen, '/\[GIFT\]/u', self::KW_DON);

        return $keywords;
    }

    private function extractSimpleKeyword(string $text, array &$keywords, array &$seen, string $pattern, string $name): void
    {
        if (!isset($seen[$name]) && preg_match($pattern, $text)) {
            $keywords[]   = ['k' => $name];
            $seen[$name]  = true;
        }
    }
}
