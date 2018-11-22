<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Type\Schema;
use GraphQLTools\Stitching\SchemaRecreation;

class RenameRootFields implements Transform
{
    /** @var TransformRootFields */
    private $transformer;

    public function __construct(callable $renamer)
    {
        $resolveType = SchemaRecreation::createResolveType(
            static function ($name, $type) {
                return $type;
            }
        );

        $this->transformer = new TransformRootFields(
            static function ($operation, $fieldName, $field) use ($renamer, $resolveType) {
                return [
                    'name' => $renamer($operation, $fieldName, $field),
                    'field' => SchemaRecreation::fieldToFieldConfig($field, $resolveType, true),
                ];
            }
        );
    }

    public function transformSchema(Schema $originalSchema) : Schema
    {
        return $this->transformer->transformSchema($originalSchema);
    }
}
