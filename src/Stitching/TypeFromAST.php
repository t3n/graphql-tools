<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Utils\AST;
use GraphQLTools\Utils;

use function array_map;

class TypeFromAST
{
    /** @var mixed[] */
    private static array $backcompatOptions = ['commentDescriptions' => true];

    public static function invoke(DefinitionNode $node): NamedType|null
    {
        switch (true) {
            case $node instanceof ObjectTypeDefinitionNode:
                return static::makeObjectType($node);

            case $node instanceof InterfaceTypeDefinitionNode:
                return static::makeInterfaceType($node);

            case $node instanceof EnumTypeDefinitionNode:
                return static::makeEnumType($node);

            case $node instanceof UnionTypeDefinitionNode:
                return static::makeUnionType($node);

            case $node instanceof ScalarTypeDefinitionNode:
                return static::makeScalarType($node);

            case $node instanceof InputObjectTypeDefinitionNode:
                return static::makeInputObjectType($node);

            default:
                return null;
        }
    }

    private static function makeObjectType(ObjectTypeDefinitionNode $node): ObjectType
    {
        return new ObjectType([
            'name' => $node->name->value,
            'fields' => static function () use ($node) {
                return static::makeFields(Utils::toArray($node->fields));
            },
            'interfaces' => static function () use ($node) {
                return array_map(static function (NamedTypeNode $iface) {
                    return static::createNamedStub($iface->name->value, 'interface');
                }, $node->interfaces);
            },
            'description' => Utils::getDescription($node, static::$backcompatOptions),
        ]);
    }

    private static function makeInterfaceType(InterfaceTypeDefinitionNode $node): InterfaceType
    {
        return new InterfaceType([
            'name' => $node->name->value,
            'fields' => static function () use ($node) {
                return static::makeFields(Utils::toArray($node->fields));
            },
            'description' => Utils::getDescription($node, static::$backcompatOptions),
            'resolveType' => static function ($parent, $context, $info): void {
                ResolveFromParentTypename::invoke($parent, $info->schema);
            },
        ]);
    }

    private static function makeEnumType(EnumTypeDefinitionNode $node): EnumType
    {
        $values = [];
        foreach ($node->values as $value) {
            $values[$value->name->value] = [
                'description' => Utils::getDescription($node, static::$backcompatOptions),
            ];
        }

        return new EnumType([
            'name' => $node->name->value,
            'values' => $values,
            'description' => Utils::getDescription($node, static::$backcompatOptions),
        ]);
    }

    private static function makeUnionType(UnionTypeDefinitionNode $node): UnionType
    {
        return new UnionType([
            'name' => $node->name->value,
            'types' => static function () use ($node) {
                return array_map(static function (NamedTypeNode $type) {
                    return static::resolveType($type, 'object');
                }, $node->types);
            },
            'description' => Utils::getDescription($node, static::$backcompatOptions),
            'resolveType' => static function ($parent, $context, $info) {
                return ResolveFromParentTypename::invoke($parent, $info->schema);
            },
        ]);
    }

    private static function makeScalarType(ScalarTypeDefinitionNode $node): ScalarType
    {
        return new CustomScalarType([
            'name' => $node->name->value,
            'description' => Utils::getDescription($node, static::$backcompatOptions),
            'serialize' => static function () {
                return null;
            },
            'parseValue' => static function () {
                return null;
            },
            'parseLiteral' => static function () {
                return null;
            },
        ]);
    }

    private static function makeInputObjectType(InputObjectTypeDefinitionNode $node): InputObjectType
    {
        return new InputObjectType([
            'name' => $node->name->value,
            'fields' => static function () use ($node) {
                return static::makeValues($node->fields);
            },
            'description' => Utils::getDescription($node, static::$backcompatOptions),
        ]);
    }

    /**
     * @param FieldDefinitionNode[] $nodes
     *
     * @return mixed[]
     */
    private static function makeFields(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $result[$node->name->value] = [
                'type' => static::resolveType($node->type, 'object'),
                'args' => static::makeValues(Utils::toArray($node->arguments)),
                'description' => Utils::getDescription($node, static::$backcompatOptions),
            ];
        }

        return $result;
    }

    /**
     * @param InputValueDefinitionNode[] $nodes
     *
     * @return mixed[]
     */
    private static function makeValues(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            $type                       = static::resolveType($node->type, 'input');
            $result[$node->name->value] = [
                'type' => $type,
                'defaultValue' => AST::valueFromAST($node->defaultValue, $type),
                'description' => Utils::getDescription($node, static::$backcompatOptions),
            ];
        }

        return $result;
    }

    private static function resolveType(TypeNode $node, string $type): Type
    {
        switch (true) {
            case $node instanceof ListTypeNode:
                return new ListOfType(static::resolveType($node->type, $type));

            case $node instanceof NonNullTypeNode:
                return new NonNull(static::resolveType($node->type, $type));

            default:
                return static::createNamedStub($node->name->value, $type);
        }
    }

    private static function createNamedStub(string $name, string $type): Type
    {
        $constructor = null;
        if ($type === 'object') {
            $constructor = ObjectType::class;
        } elseif ($type === 'interface') {
            $constructor = InterfaceType::class;
        } else {
            $constructor = InputObjectType::class;
        }

        return new $constructor([
            'name' => $name,
            'fields' => [
                '__fake' => [
                    'type' => Type::STRING,
                ],
            ],
        ]);
    }
}
