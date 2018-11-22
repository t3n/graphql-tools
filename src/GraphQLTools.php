<?php

declare(strict_types=1);

namespace GraphQLTools;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use GraphQLTools\Generate\BuildSchemaFromTypeDefinitions;
use GraphQLTools\Stitching\MergeSchemas;
use GraphQLTools\Transforms\Transform;
use GraphQLTools\Transforms\TransformSchema;

class GraphQLTools
{
    /**
     * @param mixed[] $options
     */
    public static function makeExecutableSchema(array $options) : Schema
    {
        return MakeExecutableSchema::invoke($options);
    }

    /**
     * @param mixed[] $options
     */
    public static function mergeSchemas(array $options) : Schema
    {
        return MergeSchemas::invoke($options);
    }

    /**
     * @param Transform[] $transforms
     */
    public static function transformSchema(Schema $targetSchema, array $transforms) : Schema
    {
        return TransformSchema::invoke($targetSchema, $transforms);
    }

    /**
     * @param string|string[]|DocumentNode $typeDefinitions
     * @param mixed[]|null                 $parseOptions
     */
    public static function buildSchemaFromTypeDefinitions($typeDefinitions, ?array $parseOptions = null) : Schema
    {
        return BuildSchemaFromTypeDefinitions::invoke($typeDefinitions, $parseOptions);
    }
}
