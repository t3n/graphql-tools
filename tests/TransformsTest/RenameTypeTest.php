<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Tests\TestingSchemas;
use GraphQLTools\Transforms\RenameTypes;
use PHPUnit\Framework\TestCase;

/** @see describe('rename type') */
class RenameTypeTest extends TestCase
{
    protected Schema $schema;

    public function setUp(): void
    {
        parent::setUp();

        $transforms = [
            new RenameTypes(
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
                },
            ),
        ];

        $this->schema = GraphQLTools::transformSchema(TestingSchemas::propertySchema(), $transforms);
    }

    /** @see it('should work') */
    public function testShouldWork(): void
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
            ],
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
            $result->toArray(),
        );
    }
}
