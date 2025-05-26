<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Tests\TestingSchemas;
use GraphQLTools\Transforms\FilterTypes;
use PHPUnit\Framework\TestCase;

use function in_array;

/** @see describe('filter type') */
class FilterTypeTest extends TestCase
{
    protected Schema $schema;

    public function setUp(): void
    {
        parent::setUp();

        $typeNames  = ['ID', 'String', 'DateTime', 'Query', 'Booking'];
        $transforms = [
            new FilterTypes(
                static function ($type) use ($typeNames) {
                    return in_array($type->name, $typeNames);
                },
            ),
        ];

        $this->schema = GraphQLTools::transformSchema(TestingSchemas::bookingSchema(), $transforms);
    }

    /** @see it('should work normally') */
    public function testShouldWorkNormally(): void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                query {
                    bookingById(id: "b1") {
                        id
                        propertyId
                        startTime
                        endTime
                    }
                }
            ',
        );

        static::assertEquals(
            [
                'data' => [
                    'bookingById' => [
                        'endTime' => '2016-06-03',
                        'id' => 'b1',
                        'propertyId' => 'p1',
                        'startTime' => '2016-05-04',
                    ],
                ],
            ],
            $result->toArray(),
        );
    }

    /** @see it('should error on removed types') */
    public function testShouldErrorOnRemovedTypes(): void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                query {
                    bookingById(id: "b1") {
                        id
                        propertyId
                        startTime
                        endTime
                        customer {
                            id
                        }
                    }
                }
            ',
        );

        static::assertNotEmpty($result->errors);
        static::assertCount(1, $result->errors);
        static::assertEquals(
            'Cannot query field "customer" on type "Booking".',
            $result->errors[0]->getMessage(),
        );
    }
}
