<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

class ResolveInfoHelper
{
    /** @param mixed[] $options */
    public static function createResolveInfo(array $options): ResolveInfo
    {
        return new ResolveInfo(
            FieldDefinition::create(['name' => $options['fieldName'] ?? '', 'type' => $options['returnType'] ?? Type::string()]),
            $options['fieldNodes'] ?? [],
            $options['parentType'] ?? new ObjectType(['name' => 'dummy']),
            $options['path'] ?? [],
            $options['schema'] ?? new Schema([]),
            $options['fragments'] ?? [],
            $options['rootValue'] ?? null,
            $options['operation'] ?? null,
            $options['variableValues'] ?? [],
        );
    }
}
