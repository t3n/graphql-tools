<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Type\Schema;

class FilterRootFields implements Transform
{
    private TransformRootFields $transformer;

    public function __construct(callable $filter)
    {
        $this->transformer = new TransformRootFields(
            static function ($operation, $fieldName, $field) use ($filter) {
                if ($filter($operation, $fieldName, $field)) {
                    return null;
                }

                return false;
            },
        );
    }

    public function transformSchema(Schema $originalSchema): Schema
    {
        $transformer = $this->transformer;

        return $transformer->transformSchema($originalSchema);
    }
}
