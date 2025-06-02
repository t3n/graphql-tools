<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

class CircularSchemaB
{
    /** @return mixed[] */
    public static function build(): array
    {
        return [
            '
                type TypeB {
                    id: ID
                    a: TypeA
                }
            ',
            [CircularSchemaA::class, 'build'],
        ];
    }
}
