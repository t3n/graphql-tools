<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NullValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Type\Definition\CustomScalarType;

use function array_map;

class GraphQLTypeJSON
{
    /** @param mixed[] $variables */
    protected static function parseLiteral(Node $ast, array $variables): mixed
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
                    $value[$field->name->value] = static::parseLiteral($field->value, $variables);
                }

                return $value;

            case $ast instanceof ListValueNode:
                return array_map(static function ($n) use ($variables) {
                    return static::parseLiteral($n, $variables);
                }, $ast->values);

            case $ast instanceof NullValueNode:
                return null;

            case $ast instanceof VariableNode:
                $name = $ast->name->value;

                return $variables[$name] ?? null;

            default:
                return null;
        }
    }

    public static function build(): CustomScalarType
    {
        return new CustomScalarType([
            'name' => 'JSON',
            'description' => 'The `JSON` scalar type represents JSON values as specified by ' .
                             '[ECMA-404](http://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf).',
            'serialize' => static function ($value) {
                return $value;
            },
            'parseValue' => static function ($value) {
                return $value;
            },
            'parseLiteral' => [static::class, 'parseLiteral'],
        ]);
    }
}
