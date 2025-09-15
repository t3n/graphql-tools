<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Tests\TestingSchemas;
use GraphQLTools\Transforms\RenameTypes;
use PHPUnit\Framework\TestCase;

/** @see describe('namespace') */
class NamespaceTest extends TestCase
{
    protected Schema $schema;

    public function setUp(): void
    {
        parent::setUp();

        $transforms = [
            new RenameTypes(static function ($name) {
                return 'Property_' . $name;
            }),
        ];

        $this->schema = GraphQLTools::transformSchema(TestingSchemas::propertySchema(), $transforms);
    }

    /** @see it('should work') */
    public function testShouldWork(): void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                query($input: Property_InputWithDefault!) {
                    interfaceTest(kind: ONE) {
                        ... on Property_TestInterface {
                            testString
                        }
                    }
                    properties(limit: 1) {
                        __typename
                        id
                    }
                    propertyById(id: "p1") {
                        ... on Property_Property {
                            id
                        }
                    }
                    dateTimeTest
                    defaultInputTest(input: $input)
                }
            ',
            [],
            [],
            [
                'input' => ['test' => 'bar'],
            ],
        );

        static::assertEquals(
            [
                'data' => [
                    'dateTimeTest' => '1987-09-25T12:00:00',
                    'defaultInputTest' => 'bar',
                    'interfaceTest' => ['testString' => 'test'],
                    'properties' => [
                        [
                            '__typename' => 'Property_Property',
                            'id' => 'p1',
                        ],
                    ],
                    'propertyById' => ['id' => 'p1'],
                ],
            ],
            $result->toArray(),
        );
    }
}
