<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;

class ResolveInfoHelper
{
    /**
     * @param mixed[] $options
     */
    public static function createResolveInfo(array $options) : ResolveInfo
    {
        return new ResolveInfo(
            $options['fieldName'] ?? '',
            $options['fieldNodes'] ?? null,
            $options['returnType'] ?? null,
            $options['parentType'] ?? new ObjectType(['name' => 'dummy']),
            $options['path'] ?? null,
            $options['schema'] ?? new Schema([]),
            $options['fragments'] ?? null,
            $options['rootValue'] ?? null,
            $options['operation'] ?? null,
            $options['variableValues'] ?? null
        );
    }
}
