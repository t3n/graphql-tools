<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use TypeError;
use function count;
use function is_callable;

class AssertResolveFunctionsPresent
{
    /**
     * @param mixed[] $resolverValidationOptions
     */
    public static function invoke(Schema $schema, array $resolverValidationOptions = []) : void
    {
        $requireResolversForArgs      = $resolverValidationOptions['requireResolversForArgs'] ?? false;
        $requireResolversForNonScalar = $resolverValidationOptions['requireResolversForNonScalar'] ?? false;
        $requireResolversForAllFields = $resolverValidationOptions['requireResolversForAllFields'] ?? false;

        if ($requireResolversForAllFields && ($requireResolversForArgs || $requireResolversForNonScalar)) {
            throw new TypeError(
                'requireResolversForAllFields takes precedence over the more specific assertions. ' .
                'Please configure either requireResolversForAllFields or requireResolversForArgs / ' .
                'requireResolversForNonScalar, but not a combination of them.'
            );
        }

        ForEachField::invoke(
            $schema,
            static function (
                FieldDefinition $field,
                string $typeName,
                string $fieldName
            ) use (
                $requireResolversForAllFields,
                $requireResolversForArgs,
                $requireResolversForNonScalar
            ) : void {
                if ($requireResolversForAllFields) {
                    static::expectResolveFunction($field, $typeName, $fieldName);
                }

                if ($requireResolversForArgs && count($field->args) > 0) {
                    static::expectResolveFunction($field, $typeName, $fieldName);
                }

                if (! $requireResolversForNonScalar || Type::getNamedType($field->getType()) instanceof ScalarType) {
                    return;
                }

                static::expectResolveFunction($field, $typeName, $fieldName);
            }
        );
    }

    /**
     * @throws SchemaError
     */
    protected static function expectResolveFunction(FieldDefinition $field, string $typeName, string $fieldName) : void
    {
        if (! $field->resolveFn) {
            throw new TypeError('Resolve function missing for "' . $typeName . '.' . $fieldName . '"');
        }

        if (! is_callable($field->resolveFn)) {
            throw new SchemaError('Resolver "' . $typeName . '.' . $fieldName . '" must be a function');
        }
    }
}
