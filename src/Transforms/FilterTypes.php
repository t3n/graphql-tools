<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Type\Schema;

class FilterTypes implements Transform
{
    /** @var callable  */
    private $filter;

    public function __construct(callable $filter)
    {
        $this->filter = $filter;
    }

    public function transformSchema(Schema $schema) : Schema
    {
        $filter = $this->filter;
        return VisitSchema::invoke($schema, [
            VisitSchemaKind::TYPE => static function ($type) use ($filter) {
                if ($filter($type)) {
                    return VisitSchema::skipNode();
                }

                return VisitSchema::removeNode();
            },
        ]);
    }
}
