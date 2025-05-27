<?php

declare(strict_types=1);

namespace GraphQLTools;

use GraphQL\Language\AST\Node;
use GraphQL\Language\Token;
use GraphQL\Type\Definition\BooleanType;
use GraphQL\Type\Definition\CompositeType;
use GraphQL\Type\Definition\FloatType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\StringType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\BlockString;
use GraphQL\Utils\TypeComparators;
use IteratorAggregate;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

use function array_filter;
use function array_keys;
use function array_reverse;
use function count;
use function implode;
use function is_array;
use function iterator_to_array;

class Utils
{
    /** @var string[] */
    public static array $specifiedScalarTypes = [
        StringType::class,
        IntType::class,
        FloatType::class,
        BooleanType::class,
        IDType::class,
    ];

    public static function isSpecifiedScalarType(Type $type): bool
    {
        return $type instanceof NamedType &&
            (
                $type->name === Type::STRING ||
                $type->name === Type::INT ||
                $type->name === Type::FLOAT ||
                $type->name === Type::BOOLEAN ||
                $type->name === Type::ID
            );
    }

    public static function implementsAbstractType(Schema $schema, Type $typeA, Type $typeB): bool
    {
        if ($typeA === $typeB) {
            return true;
        }

        if ($typeA instanceof CompositeType && $typeB instanceof CompositeType) {
            return TypeComparators::doTypesOverlap($schema, $typeA, $typeB);
        }

        return false;
    }

    private static function getLeadingCommentBlock(Node $node): string|null
    {
        $loc = $node->loc;
        if (! $loc || ! $loc->startToken) {
            return null;
        }

        $comments = [];
        $token    = $loc->startToken->prev;
        while (
            $token &&
            $token->kind === Token::COMMENT &&
            $token->next && $token->prev &&
            $token->line + 1 === $token->next->line &&
            $token->line !== $token->prev->line
        ) {
            $value      = $token->value;
            $comments[] = $value;
            $token      = $token->prev;
        }

        return implode("\n", array_reverse($comments));
    }

    /** @param mixed[] $options */
    public static function getDescription(Node $node, array $options = []): string|null
    {
        if (isset($node->description)) {
            return $node->description->value;
        }

        if (isset($options['commentDescriptions']) && $options['commentDescriptions']) {
            $rawValue = static::getLeadingCommentBlock($node);
            if ($rawValue !== null) {
                return BlockString::value("\n" . $rawValue);
            }
        }

        return null;
    }

    public static function isNumericArray(mixed $array): bool
    {
        return is_array($array) && (count(array_filter(array_keys($array), 'is_string')) === 0);
    }

    public static function forceSet(object $subject, string $propertyName, mixed $value): void
    {
        $reflection = self::getReflectionProperty($subject, $propertyName);

        if ($reflection === null) {
            throw new RuntimeException("Property '{$propertyName}' does not exist.");
        }

        $reflection->setAccessible(true);
        $reflection->setValue($subject, $value);
    }

    public static function forceGet(object $subject, string $propertyName): mixed
    {
        $reflection = self::getReflectionProperty($subject, $propertyName);

        if ($reflection === null) {
            throw new RuntimeException("Property '{$propertyName}' does not exist.");
        }

        $reflection->setAccessible(true);

        return $reflection->getValue($subject);
    }

    private static function getReflectionProperty(object $subject, string $propertyName): ReflectionProperty|null
    {
        $class = new ReflectionClass($subject);

        do {
            if ($class->hasProperty($propertyName)) {
                return $class->getProperty($propertyName);
            }

            $class = $class->getParentClass();
        } while ($class);

        return null;
    }

    /** @return mixed[] */
    public static function toArray(mixed $arrayLike): array
    {
        if ($arrayLike instanceof IteratorAggregate) {
            return iterator_to_array($arrayLike->getIterator());
        }

        return $arrayLike;
    }
}
