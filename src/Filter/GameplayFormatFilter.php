<?php

namespace App\Filter;

use App\Entity\CardGroup;
use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters by gameplay format key(s) on CardGroup.gameplayFormat
 * (PostgreSQL TEXT[], GIN-indexed).
 *
 * Single:  ?gameplayFormat=STANDARD
 * Multi:   ?gameplayFormat[]=STANDARD&gameplayFormat[]=DRAFT&gameplayFormatMode=and|or  (default: or)
 *
 *   OR  → gameplay_format && ARRAY['A','B']   (any of)
 *   AND → gameplay_format @> ARRAY['A','B']   (all of)
 *
 * Works on both Card (filters through its cardGroup association) and CardGroup directly.
 */
final class GameplayFormatFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if (!$this->isPropertyEnabled($property, $resourceClass) || empty($value)) {
            return;
        }

        $formats = array_values(array_filter(
            array_map('strtoupper', is_array($value) ? $value : [$value])
        ));
        if (empty($formats)) {
            return;
        }

        $mode    = strtolower($context['filters']['gameplayFormatMode'] ?? 'or');
        $escaped = array_map(fn(string $f) => str_replace("'", "''", $f), $formats);
        $arr     = "ARRAY['" . implode("','", $escaped) . "']";
        $op      = $mode === 'and' ? '@>' : '&&';

        $conn = $this->managerRegistry->getManager()->getConnection();
        $ids  = $conn->fetchFirstColumn("SELECT id FROM card_group WHERE gameplay_format $op $arr") ?: [0];
        $idList = implode(',', array_map('intval', $ids));

        $root = $queryBuilder->getRootAliases()[0];
        if ($resourceClass === CardGroup::class) {
            $queryBuilder->andWhere("$root.id IN ($idList)");
        } else {
            $queryBuilder->andWhere("IDENTITY($root.cardGroup) IN ($idList)");
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'gameplayFormat' => [
                'property'      => 'gameplayFormat',
                'type'          => 'string',
                'required'      => false,
                'is_collection' => true,
                'description'   => 'Filter by gameplay format key(s). Use gameplayFormatMode=and|or (default or).',
            ],
            'gameplayFormatMode' => [
                'property'    => 'gameplayFormatMode',
                'type'        => 'string',
                'required'    => false,
                'description' => 'Gameplay format combination mode: "and" or "or" (default)',
            ],
        ];
    }
}
