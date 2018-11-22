<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

class CircularSchemaA
{
    /**
     * @return mixed[]
     */
    public static function build() : array
    {
        return [
            '
                type TypeA {
                    id: ID
                    b: TypeB
                }
            ',
            [CircularSchemaB::class, 'build'],
        ];
    }
}
