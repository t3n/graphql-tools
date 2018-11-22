<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQLTools\Tests\TestingSchemas;
use GraphQLTools\Transforms\RenameTypes;
use GraphQLTools\Transforms\TransformSchema;
use PHPUnit\Framework\TestCase;

/**
 * @see describe('rename type')
 */
class RenameTypeTest extends TestCase
{
    /** @var Schema */
    protected $schema;

    public function setUp() : void
    {
        parent::setUp();

        $transforms = [new RenameTypes(
            static function ($name) {
                    return [
                        'Property' => 'House',
                        'Location' => 'Spots',
                        'TestInterface' => 'TestingInterface',
                        'DateTime' => 'Datum',
                        'InputWithDefault' => 'DefaultingInput',
                        'TestInterfaceKind' => 'TestingInterfaceKinds',
                        'TestImpl1' => 'TestImplementation1',
                    ][$name] ?? null;
            }
        ),
        ];

        $this->schema = TransformSchema::invoke(TestingSchemas::propertySchema(), $transforms);
    }

    /**
     * @see it('should work')
     */
    public function testShouldWork() : void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                query($input: DefaultingInput!) {
                    interfaceTest(kind: ONE) {
                        ... on TestingInterface {
                            testString
                        }
                    }
                    propertyById(id: "p1") {
                        ... on House {
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
            ]
        );

        static::assertEquals(
            [
                'data' => [
                    'dateTimeTest' => '1987-09-25T12:00:00',
                    'defaultInputTest' => 'bar',
                    'interfaceTest' => ['testString' => 'test'],
                    'propertyById' => ['id' => 'p1'],
                ],
            ],
            $result->toArray()
        );
    }
}
