<?php

declare(strict_types=1);

namespace GraphQLTools;

use GraphQL\Type\Definition\BooleanType;
use GraphQL\Type\Definition\FloatType;
use GraphQL\Type\Definition\IDType;
use GraphQL\Type\Definition\IntType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\StringType;
use GraphQL\Type\Definition\Type;

class IsSpecifiedScalarType
{
    /** @var string[] */
    public static $specifiedScalarTypes = [
        StringType::class,
        IntType::class,
        FloatType::class,
        BooleanType::class,
        IDType::class,
    ];

    /**
     * @param mixed $type
     */
    public static function invoke($type) : bool
    {
        if (! $type instanceof NamedType) {
            return false;
        }

        $name = $type->name ?? null;

        return $name === Type::STRING ||
          $name === Type::INT ||
          $name === Type::FLOAT ||
          $name === Type::BOOLEAN ||
          $name === Type::ID;
    }
}
