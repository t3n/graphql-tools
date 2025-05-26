<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

use function substr;

class ForEachField
{
    public static function invoke(Schema $schema, callable $fn): void
    {
        $typeMap = $schema->getTypeMap();

        foreach ($typeMap as $typeName => $type) {
            if (! (substr(Type::getNamedType($type)->name, 0, 2) !== '__' & $type instanceof ObjectType)) {
                continue;
            }

            $fields = $type->getFields();
            foreach ($fields as $fieldName => $field) {
                $fn($field, $typeName, $fieldName);
            }
        }
    }
}
