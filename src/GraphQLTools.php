<?php

declare(strict_types=1);

namespace GraphQLTools;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Schema;
use GraphQLTools\Generate\AddResolveFunctionsToSchema;
use GraphQLTools\Generate\AddSchemaLevelResolveFunction;
use GraphQLTools\Generate\BuildSchemaFromTypeDefinitions;
use GraphQLTools\Stitching\DelegateToSchema;
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
     * @param Schema|mixed[] $options
     * @param mixed[]|null   $legacyInputResolvers
     * @param mixed[]|null   $legacyInputValidationOptions
     */
    public static function addResolveFunctionsToSchema(
        $options,
        $legacyInputResolvers = null,
        ?array $legacyInputValidationOptions = null
    ) : Schema {
        return AddResolveFunctionsToSchema::invoke($options, $legacyInputResolvers, $legacyInputValidationOptions);
    }

    public static function addSchemaLevelResolveFunction(Schema $schema, callable $resolveFn) : void
    {
        AddSchemaLevelResolveFunction::invoke($schema, $resolveFn);
    }

    /**
     * @param mixed[] $options
     *
     * @return mixed
     */
    public static function delegateToSchema(array $options)
    {
        return DelegateToSchema::invoke($options);
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
