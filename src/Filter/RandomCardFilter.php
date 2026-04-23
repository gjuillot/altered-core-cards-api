<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

final class RandomCardFilter extends AbstractFilter
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
        if ('random' !== $property) {
            return;
        }

        if (!$value || $value === 'false') {
            return;
        }

        $queryBuilder->resetDQLPart('orderBy');
        $queryBuilder->orderBy('RANDOM()');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'random' => [
                'property' => 'random',
                'type' => 'string',
                'required' => false,
                'description' => 'Random shuffle order',
            ],
        ];
    }
}