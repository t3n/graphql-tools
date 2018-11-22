<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQLTools\Transforms\ConvertEnumValues;
use GraphQLTools\Transforms\Transforms;
use function array_keys;
use function gettype;
use function is_array;
use function is_callable;
use function is_object;
use function substr;

class AddResolveFunctionsToSchema
{
    /**
     * @param Schema|mixed[] $options
     * @param mixed[]|null   $legacyInputResolvers
     * @param mixed[]|null   $legacyInputValidationOptions
     *
     * @throws SchemaError
     */
    public static function invoke(
        $options,
        $legacyInputResolvers = null,
        ?array $legacyInputValidationOptions = null
    ) : Schema {
        if ($options instanceof Schema) {
            $options = [
                'schema' => $options,
                'resolvers' => $legacyInputResolvers,
                'resolverValidationOptions' => $legacyInputValidationOptions,
            ];
        }

        /** @var Schema $schema */
        $schema                         = $options['schema'];
        $inputResolvers                 = $options['resolvers'];
        $resolverValidationOptions      = $options['resolverValidationOptions'] ?? [];
        $inheritResolversFromInterfaces = $options['inheritResolversFromInterfaces'] ?? false;

        $allowResolversNotInSchema      = $resolverValidationOptions['allowResolversNotInSchema'] ?? false;
        $requireResolversForResolveType = $resolverValidationOptions['requireResolversForResolveType'] ?? null;

        $resolvers = $inheritResolversFromInterfaces
            ? ExtendResolversFromInterfaces::invoke($schema, $inputResolvers)
            : $inputResolvers;

        $enumValueMap = [];

        foreach ($resolvers as $typeName => $resolverValue) {
            if (! is_array($resolverValue) && ! is_object($resolverValue) && ! is_callable($resolverValue)) {
                throw new SchemaError(
                    '"' . $typeName . '" defined in resolvers, but has invalid value "' .
                    gettype($resolverValue) . '". A resolver\'s value must be of type object or function.'
                );
            }

            try {
                $type = $schema->getType($typeName);
            } catch (Error $error) {
                $type = null;
            }

            if ($type === null && $typeName !== '__schema') {
                if ($allowResolversNotInSchema) {
                    continue;
                }

                throw new SchemaError('"' . $typeName . '" defined in resolvers, but not in schema');
            }

            if ($resolverValue instanceof Type) {
                $resolverValue = $resolverValue->config;
            }

            if (! is_array($resolverValue)) {
                continue;
            }

            foreach (array_keys($resolverValue) as $fieldName) {
                if (substr($fieldName, 0, 2) === '__') {
                    $type->config[substr($fieldName, 2)] = $resolverValue[$fieldName];
                    continue;
                }

                if ($type instanceof ScalarType) {
                    switch ($fieldName) {
                        case 'serialize':
                        case 'parseValue':
                        case 'parseLiteral':
                            $type->config[$fieldName] = $resolverValue[$fieldName];
                            break;
                        default:
                            $type->$fieldName = $resolverValue[$fieldName];
                    }
                    continue;
                }

                if ($type instanceof EnumType) {
                    if ($type->getValue($fieldName) === null) {
                        if ($allowResolversNotInSchema) {
                            continue;
                        }

                        throw new SchemaError(
                            $typeName . '.' . $fieldName . ' was defined in resolvers, but enum is not in schema'
                        );
                    }

                    $enumValueMap[$type->name]             = $enumValueMap[$type->name] ?? [];
                    $enumValueMap[$type->name][$fieldName] = $resolverValue[$fieldName];
                    continue;
                }

                $fields = static::getFieldsForType($type);
                if ($fields === null) {
                    if ($allowResolversNotInSchema) {
                        continue;
                    }

                    throw new SchemaError($typeName . ' was defined in resolvers, but it\'s not an object');
                }

                if (! isset($fields[$fieldName])) {
                    if ($allowResolversNotInSchema) {
                        continue;
                    }

                    throw new SchemaError($typeName . '.' . $fieldName . ' defined in resolvers, but not in schema');
                }

                $field        = $fields[$fieldName];
                $fieldResolve = $resolverValue[$fieldName];
                if (is_callable($fieldResolve)) {
                    static::setFieldProperties($field, ['resolve' => $fieldResolve]);
                } else {
                    if (! is_array($fieldResolve)) {
                        throw new SchemaError(
                            'Resolver ' . $typeName . '.' . $fieldName . ' must be object or function'
                        );
                    }
                    static::setFieldProperties($field, $fieldResolve);
                }
            }
        }

        CheckForResolveTypeResolver::invoke($schema, $requireResolversForResolveType);

        return Transforms::applySchemaTransforms($schema, [
            new ConvertEnumValues($enumValueMap),
        ]);
    }

    /**
     * @return FieldDefinition[]|null
     */
    protected static function getFieldsForType(Type $type) : ?array
    {
        if ($type instanceof ObjectType ||
            $type instanceof InterfaceType
        ) {
            return $type->getFields();
        }

        return null;
    }

    /**
     * Adjusted to match webonyx/php-graphql
     *
     * @param mixed[] $propertiesObj
     */
    protected static function setFieldProperties(FieldDefinition $field, array $propertiesObj) : void
    {
        foreach ($propertiesObj as $propertyName => $property) {
            switch ($propertyName) {
                case 'resolve':
                    $field->resolveFn = $property;
                    break;
                default:
                    $field->$propertyName = $property;
                    break;
            }
        }
    }
}
