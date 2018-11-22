<?php

declare(strict_types=1);

namespace GraphQLTools;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Schema;
use GraphQLTools\Generate\AddResolveFunctionsToSchema;
use GraphQLTools\Generate\AddSchemaLevelResolveFunction;
use GraphQLTools\Generate\AssertResolveFunctionsPresent;
use GraphQLTools\Generate\AttachConnectorsToContext;
use GraphQLTools\Generate\AttachDirectiveResolvers;
use GraphQLTools\Generate\BuildSchemaFromTypeDefinitions;
use GraphQLTools\Generate\DecorateWithLogger;
use GraphQLTools\Generate\ForEachField;
use GraphQLTools\Generate\SchemaError;
use function array_filter;
use function array_reduce;
use function is_array;
use function is_callable;

class MakeExecutableSchema
{
    /**
     * @param mixed[]|Schema $options
     * @param mixed[]        $legacyInputResolvers
     * @param mixed[]|null   $legacyInputValidationOptions
     */
    public static function addResolveFunctionsToSchema(
        $options,
        ?array $legacyInputResolvers = null,
        ?array $legacyInputValidationOptions = null
    ) : Schema {
        return AddResolveFunctionsToSchema::invoke($options, $legacyInputResolvers, $legacyInputValidationOptions);
    }

    /**
     * @param mixed[] $options
     */
    public static function invoke(array $options) : Schema
    {
        $typeDefs                       = $options['typeDefs'] ?? null;
        $resolvers                      = $options['resolvers'] ?? [];
        $connectors                     = $options['connectors'] ?? null;
        $logger                         = $options['logger'] ?? null;
        $resolverValidationOptions      = $options['resolverValidationOptions'] ?? [];
        $directiveResolvers             = $options['directiveResolvers'] ?? null;
        $schemaDirectives               = $options['schemaDirectives'] ?? null;
        $parseOptions                   = $options['parseOptions'] ?? [];
        $inheritResolversFromInterfaces = $options['inheritResolversFromInterfaces'] ?? false;

        if (! is_array($resolverValidationOptions)) {
            throw new SchemaError('Expected `resolverValidationOptions` to be an object');
        }

        if ($typeDefs === null) {
            throw new SchemaError('Must provide typeDefs');
        }

        if ($resolvers === null) {
            throw new SchemaError('Must provide resolvers');
        }

        if (Utils::isNumericArray($resolvers)) {
            $resolverMap = array_filter($resolvers, static function (array $resolverObj) : bool {
                return ! Utils::isNumericArray($resolverObj);
            });

            $resolverMap = array_reduce($resolverMap, [MergeDeep::class, 'invoke'], []);
        } else {
            $resolverMap = $resolvers;
        }

        $schema = BuildSchemaFromTypeDefinitions::invoke($typeDefs, $parseOptions);

        $schema = static::addResolveFunctionsToSchema([
            'schema' => $schema,
            'resolvers' => $resolverMap,
            'resolverValidationOptions' => $resolverValidationOptions,
            'inheritResolversFromInterfaces' => $inheritResolversFromInterfaces,
        ]);

        AssertResolveFunctionsPresent::invoke($schema, $resolverValidationOptions);

        if ($logger) {
            static::addErrorLoggingToSchema($schema, $logger);
        }

        if (isset($resolvers['__schema']) && is_callable($resolvers['__schema'])) {
            AddSchemaLevelResolveFunction::invoke($schema, $resolvers['__schema']);
        }

        if ($connectors) {
            AttachConnectorsToContext::invoke($schema, $connectors);
        }

        if ($directiveResolvers) {
            AttachDirectiveResolvers::invoke($schema, $directiveResolvers);
        }

        if ($schemaDirectives) {
            SchemaDirectiveVisitor::visitSchemaDirectives($schema, $schemaDirectives);
        }

        return $schema;
    }

    public static function addErrorLoggingToSchema(Schema $schema, Logger $logger) : void
    {
        ForEachField::invoke(
            $schema,
            static function (FieldDefinition $field, string $typeName, string $fieldName) use ($logger) : void {
                $errorHint        = $typeName . '.' . $fieldName;
                $field->resolveFn = DecorateWithLogger::invoke($field->resolveFn, $logger, $errorHint);
            }
        );
    }
}
