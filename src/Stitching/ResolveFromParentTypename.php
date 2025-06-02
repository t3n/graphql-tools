<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

use function is_array;

class ResolveFromParentTypename
{
    /** @throws Error */
    public static function invoke(mixed $parent, Schema $schema): Type
    {
        $parentTypename = is_array($parent) ? ($parent['__typename'] ?? null) : null;
        if (! $parentTypename) {
            throw new Error('Did not fetch typename for object, unable to resolve interface.');
        }

        $resolvedType = $schema->getType($parentTypename);

        if (! ($resolvedType instanceof ObjectType)) {
            throw new Error('__typename did not match an object type: ' . $parentTypename);
        }

        return $resolvedType;
    }
}
