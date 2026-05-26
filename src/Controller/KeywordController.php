<?php

namespace App\Controller;

use App\Service\EffectParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class KeywordController extends AbstractController
{
    private const KEYWORD_CATALOG = [
        // Passive keywords
        EffectParser::KW_REPERAGE           => ['fr' => 'Repérage',           'en' => 'Scout'],
        EffectParser::KW_CORIACE            => ['fr' => 'Coriace',            'en' => 'Tough'],
        EffectParser::KW_GIGANTESQUE        => ['fr' => 'Gigantesque',        'en' => 'Gigantic'],
        EffectParser::KW_DEFENSEUR          => ['fr' => 'Défenseur',          'en' => 'Defender'],
        EffectParser::KW_FUGACE             => ['fr' => 'Fugace',             'en' => 'Fleeting'],
        EffectParser::KW_ETERNEL            => ['fr' => 'Éternel',            'en' => 'Eternal'],
        EffectParser::KW_ANCRE              => ['fr' => 'Ancré',              'en' => 'Anchored'],
        EffectParser::KW_ENDORMI            => ['fr' => 'Endormi',            'en' => 'Asleep'],
        EffectParser::KW_BOOSTE             => ['fr' => 'Boosté',             'en' => 'Boosted'],
        EffectParser::KW_RAFRAICHISSEMENT   => ['fr' => 'Rafraîchissement',   'en' => 'Cooldown'],
        // Action keywords
        EffectParser::KW_SABOTEZ            => ['fr' => 'Sabotez',            'en' => 'Sabotage'],
        EffectParser::KW_RAVITAILLEZ        => ['fr' => 'Ravitaillez',        'en' => 'Resupply'],
        EffectParser::KW_RAVITAILLEZ_EPUISE => ['fr' => 'Ravitaillez Épuisé', 'en' => 'Exhausted Resupply'],
        EffectParser::KW_FONCER             => ['fr' => 'Foncer',             'en' => 'Rush'],
        EffectParser::KW_DON                => ['fr' => 'Don',                'en' => 'Gift'],
    ];

    #[Route('/api/keywords', name: 'api_keywords', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $result = [];

        foreach (self::KEYWORD_CATALOG as $code => $meta) {
            $result[] = [
                'code'          => $code,
                'filterExample' => "effectKeyword={$code}",
                'translations'  => [
                    'fr' => $meta['fr'],
                    'en' => $meta['en'],
                ],
            ];
        }

        return $this->json($result);
    }
}
