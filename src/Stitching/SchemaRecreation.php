<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Error\Error;
use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQLTools\IsSpecifiedScalarType;

use function array_map;

class SchemaRecreation
{
    public static function recreateType(NamedType $type, callable $resolveType, bool $keepResolvers): NamedType
    {
        if ($type instanceof ObjectType) {
            $fields     = $type->getFields();
            $interfaces = $type->getInterfaces();

            return new ObjectType([
                'name' => $type->name,
                'description' => $type->description,
                'astNode' => $type->astNode,
                'isTypeOf' => $keepResolvers ? ($type->config['isTypeOf'] ?? null) : null,
                'fields' => static function () use ($fields, $resolveType, $keepResolvers): array {
                    return static::fieldMapToFieldConfigMap($fields, $resolveType, $keepResolvers);
                },
                'interfaces' => static function () use ($interfaces, $resolveType): array {
                    return array_map(static function (InterfaceType $iface) use ($resolveType) {
                        return $resolveType($iface);
                    }, $interfaces);
                },
            ]);
        }

        if ($type instanceof InterfaceType) {
            $fields = $type->getFields();

            return new InterfaceType([
                'name' => $type->name,
                'description' => $type->description,
                'astNode' => $type->astNode,
                'fields' => static function () use ($fields, $resolveType, $keepResolvers): array {
                    return static::fieldMapToFieldConfigMap($fields, $resolveType, $keepResolvers);
                },
                'resolveType' => $keepResolvers
                    ? $type->config['resolveType']
                    : static function ($parent, $context, ResolveInfo $info): Type {
                        return ResolveFromParentTypename::invoke($parent, $info->schema);
                    },
            ]);
        }

        if ($type instanceof UnionType) {
            return new UnionType([
                'name' => $type->name,
                'description' => $type->description,
                'astNode' => $type->astNode,
                'types' => static function () use ($type, $resolveType): array {
                    return array_map(static function (ObjectType $unionMember) use ($resolveType) {
                        return $resolveType($unionMember);
                    }, $type->getTypes());
                },
                'resolveType' => $keepResolvers
                    ? $type->config['resolveType']
                    : static function ($parent, $context, ResolveInfo $info): Type {
                        return ResolveFromParentTypename::invoke($parent, $info->schema);
                    },
            ]);
        }

        if ($type instanceof InputObjectType) {
            return new InputObjectType([
                'name' => $type->name,
                'description' => $type->description,
                'astNode' => $type->astNode,
                'fields' => static function () use ($type, $resolveType): array {
                    return static::inputFieldMapToFieldConfigMap($type->getFields(), $resolveType);
                },
            ]);
        }

        if ($type instanceof EnumType) {
            $values    = $type->getValues();
            $newValues = [];
            foreach ($values as $value) {
                $newValues[$value->name] = [
                    'value' => $value->value,
                    'deprecationReason' => $value->deprecationReason,
                    'description' => $value->description,
                    'astNode' => $value->astNode,
                ];
            }

            return new EnumType([
                'name' => $type->name,
                'description' => $type->description,
                'astNode' => $type->astNode,
                'values' => $newValues,
            ]);
        }

        if ($type instanceof ScalarType) {
            if ($keepResolvers || IsSpecifiedScalarType::invoke($type)) {
                return $type;
            }

            return new CustomScalarType([
                'name' => $type->name,
                'description' => $type->description,
                'astNode' => $type->astNode,
                'serialize' => static function ($value) {
                    return $value;
                },
                'parseValue' => static function ($value) {
                    return $value;
                },
                'parseLiteral' => static function (ValueNode $ast) {
                    return static::parseLiteral($ast);
                },
            ]);
        }

        throw new Error('Invalid type ' . $type::class);
    }

    /** @return mixed[]|float|string|null */
    protected static function parseLiteral(ValueNode $ast): array|float|string|null
    {
        switch (true) {
            case $ast instanceof StringValueNode:
            case $ast instanceof BooleanValueNode:
                return $ast->value;

            case $ast instanceof IntValueNode:
            case $ast instanceof FloatValueNode:
                return (float) $ast->value;

            case $ast instanceof ObjectValueNode:
                $value = [];
                foreach ($ast->fields as $field) {
                    $value[$field->name->value] = static::parseLiteral($field->value);
                }

                return $value;

            case $ast instanceof ListValueNode:
                return array_map(static function (ValueNode $ast) {
                    return static::parseLiteral($ast);
                }, $ast->values);

            default:
                return null;
        }
    }

    /**
     * @param FieldDefinition[] $fields
     *
     * @return mixed[]
     */
    public static function fieldMapToFieldConfigMap(array $fields, callable $resolveType, bool $keepResolvers): array
    {
        $result = [];
        foreach ($fields as $name => $field) {
            $type = $resolveType($field->getType());

            if ($type === null) {
                continue;
            }

            $result[$name] = static::fieldToFieldConfig(
                $field,
                $resolveType,
                $keepResolvers,
            );
        }

        return $result;
    }

    public static function createResolveType(callable $getType): callable
    {
        $resolveType = static function (Type $type) use ($getType, &$resolveType) {
            if ($type instanceof ListOfType) {
                $innerType = $resolveType($type->ofType);
                if ($innerType === null) {
                    return null;
                }

                return new ListOfType($innerType);
            }

            if ($type instanceof NonNull) {
                $innerType = $resolveType($type->getWrappedType());
                if ($innerType === null) {
                    return null;
                }

                return new NonNull($innerType);
            }

            if ($type instanceof NamedType) {
                return $getType($type->name, $type);
            }

            return $type;
        };

        return $resolveType;
    }

    /** @return mixed[] */
    public static function fieldToFieldConfig(
        FieldDefinition $field,
        callable $resolveType,
        bool $keepResolvers,
    ): array {
        return [
            'type' => $resolveType($field->getType()),
            'args' => static::argsToFieldConfigArgumentMap($field->args, $resolveType),
            'resolve' => $keepResolvers ? $field->resolveFn : [DefaultMergedResolver::class, 'invoke'],
            'subscribe' => $keepResolvers ? null : null,
            'description' => $field->description,
            'deprecationReason' => $field->deprecationReason,
            'astNode' => $field->astNode,
            'complexity' => $field->getComplexityFn(),
        ];
    }

    /**
     * @param FieldArgument[] $args
     *
     * @return mixed[]
     */
    public static function argsToFieldConfigArgumentMap(array $args, callable $resolveType): array
    {
        $result = [];
        foreach ($args as $arg) {
            $newArg = static::argumentToArgumentConfig($arg, $resolveType);
            if ($newArg === null) {
                continue;
            }

            $result[$newArg[0]] = $newArg[1];
        }

        return $result;
    }

    /** @return mixed[]|null */
    public static function argumentToArgumentConfig(FieldArgument $argument, callable $resolveType): array|null
    {
        $type = $resolveType($argument->getType());
        if ($type === null) {
            return null;
        }

        $config = [
            'type' => $type,
            'description' => $argument->description,
        ];

        if ($argument->defaultValueExists()) {
            $config['defaultValue'] = $argument->defaultValue;
        }

        return [$argument->name, $config];
    }

    /**
     * @param InputObjectField[] $fields
     *
     * @return mixed[]
     */
    public static function inputFieldMapToFieldConfigMap(array $fields, callable $resolveType): array
    {
        $result = [];
        foreach ($fields as $name => $field) {
            $type = $resolveType($field->getType());
            if ($type === null) {
                continue;
            }

            $result[$name] = static::inputFieldToFieldConfig($field, $resolveType);
        }

        return $result;
    }

    /** @return mixed[] */
    public static function inputFieldToFieldConfig(InputObjectField $field, callable $resolveType): array
    {
        $config = [
            'type' => $resolveType($field->getType()),
            'description' => $field->description,
            'astNode' => $field->astNode,
        ];

        if ($field->defaultValueExists()) {
            $config['defaultValue'] = $field->defaultValue;
        }

        return $config;
    }
}
