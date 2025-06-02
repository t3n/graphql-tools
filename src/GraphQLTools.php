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
    /** @param mixed[] $options */
    public static function makeExecutableSchema(array $options): Schema
    {
        return MakeExecutableSchema::invoke($options);
    }

    /**
     * @param Schema|mixed[] $options
     * @param mixed[]|null   $legacyInputResolvers
     * @param mixed[]|null   $legacyInputValidationOptions
     */
    public static function addResolveFunctionsToSchema(
        Schema|array $options,
        array|null $legacyInputResolvers = null,
        array|null $legacyInputValidationOptions = null,
    ): Schema {
        return AddResolveFunctionsToSchema::invoke($options, $legacyInputResolvers, $legacyInputValidationOptions);
    }

    public static function addSchemaLevelResolveFunction(Schema $schema, callable $resolveFn): void
    {
        AddSchemaLevelResolveFunction::invoke($schema, $resolveFn);
    }

    /** @param mixed[] $options */
    public static function delegateToSchema(array $options): mixed
    {
        return DelegateToSchema::invoke($options);
    }

    /** @param mixed[] $options */
    public static function mergeSchemas(array $options): Schema
    {
        return MergeSchemas::invoke($options);
    }

    /** @param Transform[] $transforms */
    public static function transformSchema(Schema $targetSchema, array $transforms): Schema
    {
        return TransformSchema::invoke($targetSchema, $transforms);
    }

    /**
     * @param string|string[]|DocumentNode $typeDefinitions
     * @param mixed[]|null                 $parseOptions
     */
    public static function buildSchemaFromTypeDefinitions(string|array|DocumentNode $typeDefinitions, array|null $parseOptions = null): Schema
    {
        return BuildSchemaFromTypeDefinitions::invoke($typeDefinitions, $parseOptions);
    }
}
