<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use DateTime;
use Exception;
use GraphQL\Error\Error;
use GraphQL\GraphQL;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\ScalarType;
use GraphQLTools\Generate\AddSchemaLevelResolveFunction;
use GraphQLTools\Generate\AttachConnectorsToContext;
use GraphQLTools\Generate\ChainResolvers;
use GraphQLTools\Generate\ConcatenateTypeDefs;
use GraphQLTools\Generate\SchemaError;
use GraphQLTools\GraphQLTools;
use GraphQLTools\SimpleLogger;
use GraphQLTools\Tests\SchemaGeneratorTest\TestHelper;
use GraphQLTools\Transforms\VisitSchema;
use GraphQLTools\Transforms\VisitSchemaKind;
use PHPUnit\Framework\TestCase;
use SplObjectStorage;
use stdClass;
use Throwable;
use TypeError;
use const PHP_EOL;
use function array_map;
use function is_string;
use function preg_match;
use function strlen;
use function strpos;

class SchemaGeneratorTest extends TestCase
{
    /**
     * @see it('throws an error if no schema is provided')
     */
    public function testThrowsAnErrorIfNoSchemaIsProvided() : void
    {
        try {
            GraphQLTools::makeExecutableSchema([]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertInstanceOf(SchemaError::class, $exception);
        }
    }

    /**
     * @see it('throws an error if typeDefinitionNodes are not provided')
     */
    public function testThrowsAnErrorIfTypeDefinitionNodesAreNotProvided() : void
    {
        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => null,
                'resolvers' => [],
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('Must provide typeDefs', $exception->getMessage());
        }
    }

    /**
     * @see it('throws an error if no resolveFunctions are provided')
     */
    public function testThrowsAnErrorIfNoResolveFunctionsAreProvided() : void
    {
        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => 'blah',
                'resolvers' => [],
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertInstanceOf(Error::class, $exception);
        }
    }

    /**
     * @see it('throws an error if typeDefinitionNodes is neither string nor array nor schema AST')
     */
    public function testThrowsAnErrorIfTypeDefinitionNodesIsNeitherStringNorArrayNorSchemaAST() : void
    {
        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => new SplObjectStorage(),
                'resolvers' => [],
            ]);
        } catch (Throwable $exception) {
            static::assertEquals(
                'typeDefs must be a string, array or schema AST, got object',
                $exception->getMessage()
            );
        }
    }

    /**
     * @see it('throws an error if typeDefinitionNode array contains not only functions and strings')
     */
    public function testThrowsAnErrorIfTypeDefinitionNodeArrayContainsNotOnlyFunctionsAndStrings() : void
    {
        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => [17],
                'resolvers' => [],
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals(
                'typeDef array must contain only strings and functions, got integer',
                $exception->getMessage()
            );
        }
    }

    /**
     * @see it('throws an error if resolverValidationOptions is not an object')
     */
    public function testThrowsAnErrorIfResolverValidationOptionsIsNotAnObject() : void
    {
        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => 'blah',
                'resolvers' => [],
                'resolverValidationOptions' => 'string',
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('Expected `resolverValidationOptions` to be an object', $exception->getMessage());
        }
    }

    /**
     * @see it('can generate a schema')
     */
    public function testCanGenerateASchema() : void
    {
        $shorthand = '
            """
            A bird species
            """
            type BirdSpecies {
                name: String!,
                wingspan: Int
            }
            type RootQuery {
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
        ';

        $resolve = [
            'RootQuery' => [
                'species' => static function () : void {
                    return;
                },
            ],
        ];

        $introspectionQuery = '{
            species: __type(name: "BirdSpecies") {
                name,
                description,
                fields {
                    name
                    type {
                        name
                        kind
                        ofType {
                            name
                        }
                    }
                }
            }
            query: __type(name: "RootQuery") {
                name,
                description,
                fields {
                    name
                    type {
                        name
                        kind
                        ofType {
                            name
                        }
                    }
                    args {
                        name
                        type {
                            name
                            kind
                            ofType {
                                name
                            }
                        }
                    }
                }
            }
        }';

        $solution = [
            'data' => [
                'species' => [
                    'name' => 'BirdSpecies',
                    'description' => 'A bird species',
                    'fields' => [
                        [
                            'name' => 'name',
                            'type' => [
                                'kind' => 'NON_NULL',
                                'name' => null,
                                'ofType' => ['name' => 'String'],
                            ],
                        ],
                        [
                            'name' => 'wingspan',
                            'type' => [
                                'kind' => 'SCALAR',
                                'name' => 'Int',
                                'ofType' => null,
                            ],
                        ],
                    ],
                ],
                'query' => [
                    'name' => 'RootQuery',
                    'description' => '',
                    'fields' => [
                        [
                            'name' => 'species',
                            'type' => [
                                'kind' => 'LIST',
                                'name' => null,
                                'ofType' => ['name' => 'BirdSpecies'],
                            ],
                            'args' => [
                                [
                                    'name' => 'name',
                                    'type' => [
                                        'name' => null,
                                        'kind' => 'NON_NULL',
                                        'ofType' => ['name' => 'String'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolve,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $introspectionQuery);
        static::assertEquals($solution, $result->toArray());
    }

    /**
     * @see it('can generate a schema from an array of types')
     */
    public function testCanGenerateASchemaFromAnArrayOfTypes() : void
    {
        $typeDefAry = [
            '
                type Query {
                    foo: String
                }
            ',
            '
                schema {
                    query: Query
                }
            ',
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefAry,
            'resolvers' => [],
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
    }

    /**
     * @see it('can generate a schema from a parsed type definition')
     */
    public function testCanGenerateASchemaFromAParsedTypeDefinition() : void
    {
        $typeDefSchema = Parser::parse('
            type Query {
                foo: String
            }
            schema {
                query: Query
            }
        ');

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefSchema,
            'resolvers' => [],
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
    }

    /**
     * @see it('can generate a schema from an array of parsed and none parsed type definitions')
     */
    public function testCanGenerateASchemaFromAnArrayOfParsedAndNoneParsedTypeDefinitions() : void
    {
        $typeDefSchema = [
            Parser::parse('
                type Query {
                    foo: String
                }
            '),
            '
                schema {
                    query: Query
                }
            ',
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefSchema,
            'resolvers' => [],
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
    }

    /**
     * it('can generate a schema from an array of types with extensions')
     */
    public function testCanGenerateASchemaFromAnArrayOfTypesWithExtensions() : void
    {
        $typeDefAry = [
            '
                type Query {
                    foo: String
                }
            ',
            '
                schema {
                    query: Query
                }
            ',
            '
                extend type Query {
                    bar: String
                }
            ',
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema(['typeDefs' => $typeDefAry]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
        static::assertArrayHasKey('foo', $jsSchema->getQueryType()->getFields());
        static::assertArrayHasKey('bar', $jsSchema->getQueryType()->getFields());
    }

    /**
     * @see it('can concatenateTypeDefs created by a function inside a closure')
     */
    public function testCanConcatenateTypeDefsCreatedByAFunctionInsideAClosure() : void
    {
        $typeA = [
            'typeDefs' => static function () {
                return ['type TypeA {foo: String }'];
            },
        ];
        $typeB = [
            'typeDefs' => static function () {
                return ['type TypeB {foo: String }'];
            },
        ];
        $typeC = [
            'typeDefs' => static function () {
                return ['type TypeC {foo: String }'];
            },
        ];
        $typeD = [
            'typeDefs' => static function () {
                return ['type TypeD {foo: String }'];
            },
        ];

        $combineTypeDefs = static function (...$args) {
            return [
                'typeDefs' => static function () use ($args) {
                    return array_map(static function ($o) {
                        return $o['typeDefs'];
                    }, $args);
                },
            ];
        };

        $combinedAandB = $combineTypeDefs($typeA, $typeB);
        $combinedCandD = $combineTypeDefs($typeC, $typeD);

        $result = ConcatenateTypeDefs::invoke([
            $combinedAandB['typeDefs'],
            $combinedCandD['typeDefs'],
        ]);

        static::assertContains('type TypeA', $result);
        static::assertContains('type TypeB', $result);
        static::assertContains('type TypeC', $result);
        static::assertContains('type TypeD', $result);
    }

    /**
     * @see it('properly deduplicates the array of type DefinitionNodes')
     */
    public function testPropertyDeduplicatesTheArrayOfTheDefinitionNodes() : void
    {
        $typeDefAry = [
            '
                type Query {
                    foo: String
                }
            ',
            '
                schema {
                    query: Query
                }
            ',
            '
                schema {
                    query: Query
                }
            ',
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefAry,
            'resolvers' => [],
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
    }

    /**
     * @see it('works with imports, even circular ones')
     */
    public function testWorksWithImportsEventCircularOnes() : void
    {
        $typeDefAry = [
            '
                type Query {
                    foo: TypeA
                }
            ',
            '
                schema {
                    query: Query
                }
            ',
            [CircularSchemaA::class, 'build'],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefAry,
            'resolvers' => [
                'Query' => [
                    'foo' => static function () {
                        return null;
                    },
                ],
                'TypeA' => [
                    'b' => static function () {
                        return null;
                    },
                ],
                'TypeB' => [
                    'a' => static function () {
                        return null;
                    },
                ],
            ],
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
    }

    /**
     * @see it('can generate a schema with resolve functions')
     */
    public function testCanGenerateASchemaWithResolveFunctions() : void
    {
        $shorthand = '
            type BirdSpecies {
                name: String!
                wingspan: Int
            }
            type RootQuery {
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
        ';

        $resolveFunctions = [
            'RootQuery' => [
                'species' => static function ($root, $args) {
                    $name = $args['name'];

                    return [
                        [
                            'name' => 'Hello ' . $name . '!',
                            'wingspan' => 200,
                        ],
                    ];
                },
            ],
        ];

        $testQuery = '
            {
                species(name: "BigBird") {
                    name
                    wingspan
                }
            }
        ';

        $solution = [
            'data' => [
                'species' => [
                    [
                        'name' => 'Hello BigBird!',
                        'wingspan' => 200,
                    ],
                ],
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($solution, $result->toArray());
    }

    /**
     * @see it('can generate a schema with extensions that can use resolvers')
     */
    public function testCanGenerateASchemaWithExtensionsThatCanUseResolvers() : void
    {
        $shorthand = '
            type BirdSpecies {
                name: String!,
                wingspan: Int
            }
            type RootQuery {
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
            extend type BirdSpecies {
                height: Float
            }
        ';

        $resolveFunctions = [
            'RootQuery' => [
                'species' => static function ($root, $args) {
                    $name = $args['name'];

                    return [
                        [
                            'name' => 'Hello ' . $name . '!',
                            'wingspan' => 200,
                            'height' => 30.2,
                        ],
                    ];
                },
            ],
            'BirdSpecies' => [
                'name' => static function ($bird) {
                    return $bird['name'];
                },
                'wingspan' => static function ($bird) {
                    return $bird['wingspan'];
                },
                'height' => static function ($bird) {
                    return $bird['height'];
                },
            ],
        ];

        $testQuery = '
            {
                species(name: "BigBird") {
                    name
                    wingspan
                    height
                }
            }
        ';

        $solution = [
            'data' => [
                'species' => [
                    [
                        'name' => 'Hello BigBird!',
                        'wingspan' => 200,
                        'height' => 30.2,
                    ],
                ],
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);
        $result   = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($solution, $result->toArray());
    }

    /**
     * @see it('supports resolveType for unions')
     */
    public function testSupportsResolveTypeUnions() : void
    {
        $shorthand = '
            union Searchable = Person | Location
            type Person {
                name: String
                age: Int
            }
            type Location {
                name: String
                coordinates: String
            }
            type RootQuery {
                search(name: String): [Searchable]
            }
            schema {
                query: RootQuery
            }
        ';

        $resolveFunctions = [
            'RootQuery' => [
                'search' => [
                    'resolve' => static function ($root, $args) {
                        $name = $args['name'];
                        return [
                            [
                                'name' => 'Tom ' . $name,
                                'age' => 100,
                            ],
                            [
                                'name' => 'North Pole',
                                'coordinates' => '90, 0',
                            ],
                        ];
                    },
                ],
            ],
            'Searchable' => [
                '__resolveType' => static function ($data, $context, $info) {
                    if (isset($data['age'])) {
                        return $info->schema->getType('Person');
                    }
                    if (isset($data['coordinates'])) {
                        return $info->schema->getType('Location');
                    }
                    return null;
                },
            ],
        ];

        $testQuery = '{
            search(name: "a") {
                ... on Person {
                    name
                    age
                }
                ... on Location {
                    name
                    coordinates
                }
            }
        }';

        $solution = [
            'data' => [
                'search' => [
                    [
                        'name' => 'Tom a',
                        'age' => 100,
                    ],
                    [
                        'name' => 'North Pole',
                        'coordinates' => '90, 0',
                    ],
                ],
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($solution, $result->toArray());
    }

    /**
     * @see it('can generate a schema with an array of resolvers')
     */
    public function testCanGenerateASchemaWithAnArrayOfResolvers() : void
    {
        $shorthand = '
            type BirdSpecies {
                name: String!,
                wingspan: Int
            }
            type RootQuery {
                numberOfSpecies: Int
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
            extend type BirdSpecies {
                height: Float
            }
        ';

        $resolverFunctions = [
            'RootQuery' => [
                'species' => static function ($root, $args) {
                    $name = $args['name'];

                    return [
                        [
                            'name' => 'Hello ' . $name . '!',
                            'wingspan' => 200,
                            'height' => 30.2,
                        ],
                    ];
                },
            ],
        ];

        $otherResolverFunctions = [
            'BirdSpecies' => [
                'name' => static function ($bird) {
                    return $bird['name'];
                },
                'wingspan' => static function ($bird) {
                    return $bird['wingspan'];
                },
                'height' => static function ($bird) {
                    return $bird['height'];
                },
            ],
            'RootQuery' => [
                'numberOfSpecies' => static function () {
                    return 1;
                },
            ],
        ];

        $testQuery = '{
            numberOfSpecies
            species(name: "BigBird") {
                name
                wingspan
                height
            }
        }';

        $solution = [
            'data' => [
                'numberOfSpecies' => 1,
                'species' => [
                    [
                        'name' => 'Hello BigBird!',
                        'wingspan' => 200,
                        'height' => 30.2,
                    ],
                ],
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => [$resolverFunctions, $otherResolverFunctions],
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($solution, $result->toArray());
    }

    /**
     * @see it('supports passing a GraphQLScalarType in resolveFunctions')
     */
    public function testSupportsPassingAScalarTypeInResolverFunctions() : void
    {
        $shorthand = '
            scalar JSON
            
            type Foo {
                aField: JSON
            }
            
            type Query {
                foo: Foo
            }
        ';

        $resolveFunctions = [
            'JSON' => GraphQLTypeJSON::build(),
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
        static::assertInstanceOf(CustomScalarType::class, $jsSchema->getType('JSON'));
        static::assertTrue(is_string($jsSchema->getType('JSON')->description));
        static::assertTrue(strlen($jsSchema->getType('JSON')->description) > 0);
    }

    /**
     * @see it('retains scalars after walking/recreating the schema')
     */
    public function testRetainsScalarsAfterWalkingRecreatingTheSchema() : void
    {
        $shorthand = '
            scalar Test
            
            type Foo {
                testField: Test
            }
            
            type Query {
                test: Test
                testIn(input: Test): Test
            }
        ';

        $resolveFunctions = [
            'Test' => new CustomScalarType([
                'name' => 'Test',
                'description' => 'Test resolver',
                'serialize' => static function ($value) {
                    if (! is_string($value) || strpos($value, 'scalar:') === false) {
                        return 'scalar:' . $value;
                    }

                    return $value;
                },
                'parseValue' => static function ($value) {
                    return 'scalar:' . $value;
                },
                'parseLiteral' => static function ($ast) {
                    switch (true) {
                        case $ast instanceof StringValueNode:
                        case $ast instanceof IntValueNode:
                            return 'scalar:' . $ast->value;
                        default:
                            return null;
                    }
                },
            ]),
            'Query' => [
                'testIn' => static function ($_, $args) {
                    $input = $args['input'];
                    static::assertStringStartsWith('scalar:', $input);
                    return $input;
                },
                'test' => static function () {
                    return 42;
                },
            ],
        ];

        $walkedSchema = VisitSchema::invoke(GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]), [
            VisitSchemaKind::ENUM_TYPE => static function (EnumType $type) {
                return $type;
            },
        ]);

        static::assertInstanceOf(ScalarType::class, $walkedSchema->getType('Test'));
        static::assertEquals('Test resolver', $walkedSchema->getType('Test')->description);

        $testQuery = '{
            test
            testIn(input: 1)
        }';

        $result = GraphQL::executeQuery($walkedSchema, $testQuery);
        static::assertEquals(['test' => 'scalar:42', 'testIn' => 'scalar:1'], $result->toArray()['data']);
    }

    /**
     * @see it('should support custom scalar usage on client-side query execution')
     */
    public function testShouldSupportCustomScalarUsageOnClientSideQueryExecution() : void
    {
        $shorthand = '
            scalar CustomScalar
            
            type TestType {
                testField: String
            }
            
            type RootQuery {
                myQuery(t: CustomScalar): TestType
            }
            
            schema {
                query: RootQuery
            }
        ';

        $resolveFunctions = [
            'CustomScalar' => new CustomScalarType([
                'name' => 'CustomScalar',
                'serialize' => static function ($value) {
                    return $value;
                },
                'parseValue' => static function ($value) {
                    return $value;
                },
                'parseLiteral' => static function ($ast) {
                    if ($ast instanceof StringValueNode) {
                        return $ast->value;
                    }

                    return null;
                },
            ]),
        ];

        $testQuery = '
            query myQuery($t: CustomScalar) {
                myQuery(t: $t) {
                    testField
                }
            }
        ';

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertArrayNotHasKey('errors', $result->toArray());
    }

    /**
     * @see it('should work with an Odd custom scalar type')
     */
    public function testShouldWorkWithAnOddCustomScalarType() : void
    {
        $oddValue = static function ($value) {
            return $value % 2 === 1 ? $value : null;
        };

        $OddType = new CustomScalarType([
            'name' => 'Odd',
            'description' => 'odd custom scalar type',
            'parseValue' => $oddValue,
            'serialize' => $oddValue,
            'parseLiteral' => static function ($ast) use ($oddValue) {
                if ($ast instanceof IntValueNode) {
                    return $oddValue($ast->value);
                }
                return null;
            },
        ]);

        $typeDefs = '
            scalar Odd
            
            type Post {
                id: Int!
                title: String
                something: Odd
            }
            
            type Query {
                post: Post
            }
            
            schema {
                query: Query
            }
        ';

        $testValue = 3;

        $resolvers = [
            'Odd' => $OddType,
            'Query' => [
                'post' => static function () use ($testValue) {
                    return [
                        'id' => 1,
                        'title' => 'my first post',
                        'something' => $testValue,
                    ];
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefs,
            'resolvers' => $resolvers,
        ]);

        $testQuery = '{
            post {
                something
            }
        }';

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($testValue, $result->toArray()['data']['post']['something']);
        static::assertArrayNotHasKey('errors', $result->toArray());
    }

    /**
     * @see it('should work with a Date custom scalar type')
     */
    public function testShouldWorkWithADateCustomScalarType() : void
    {
        $DateType = new CustomScalarType([
            'name' => 'Date',
            'description' => 'Date custom scalar type',
            'parseValue' => static function ($value) {
                return new DateTime($value);
            },
            'serialize' => static function (DateTime $value) {
                return $value->format('d.m.Y H:i:s');
            },
            'parseLiteral' => static function ($ast) {
                if ($ast instanceof IntValueNode) {
                    return $ast->value;
                }
                return null;
            },
        ]);

        $typeDefs = '
            scalar Date
            
            type Post {
                id: Int!
                title: String
                something: Date
            }
            
            type Query {
                post: Post
            }
            
            schema {
                query: Query
            }
        ';

        $testDate = new DateTime('1.1.2016');

        $resolvers = [
            'Date' => $DateType,
            'Query' => [
                'post' => static function () use ($testDate) {
                    return [
                        'id' => 1,
                        'title' => 'My first post',
                        'something' => $testDate,
                    ];
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefs,
            'resolvers' => $resolvers,
        ]);

        $testQuery = '{
            post {
                something
            }
        }';

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($testDate->format('d.m.Y H:i:s'), $result->toArray()['data']['post']['something']);
        static::assertArrayNotHasKey('errors', $result->toArray());
    }

    /**
     * @see it('supports passing a GraphQLEnumType in resolveFunctions')
     */
    public function testSupportsPassingAGraphQLEnumTypeInResolveFunctions() : void
    {
        $shorthand = '
            enum Color {
                RED
            }
            enum NumericEnum {
                TEST
            }
            schema {
                query: Query
            }
            type Query {
                color: Color
                numericEnum: NumericEnum
            }
        ';

        $resolveFunctions = [
            'Color' => ['RED' => '#EA3232'],
            'NumericEnum' => ['TEST' => 1],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        static::assertEquals('Query', $jsSchema->getQueryType()->name);
        static::assertInstanceOf(EnumType::class, $jsSchema->getType('Color'));
        static::assertInstanceOf(EnumType::class, $jsSchema->getType('NumericEnum'));
    }

    /**
     * @see it('supports passing the value for a GraphQLEnumType in resolveFunctions')
     */
    public function testSupportsPassingTheValueFoAGraphQLEnumTypeInResolveFunctions() : void
    {
        $shorthand = '
            enum Color {
                RED
                BLUE
            }
            enum NumericEnum {
                TEST
            }
            schema {
                query: Query
            }
            type Query {
                redColor: Color
                blueColor: Color
                numericEnum: NumericEnum
            }
        ';

        $testQuery = '{
            redColor
            blueColor
            numericEnum
        }';

        $resolveFunctions = [
            'Color' => [
                'RED' => '#EA3232',
                'BLUE' => '#0000FF',
            ],
            'NumericEnum' => ['TEST' => 1],
            'Query' => [
                'redColor' => static function () {
                    return '#EA3232';
                },
                'blueColor' => static function () {
                    return '#0000FF';
                },
                'numericEnum' => static function () {
                    return 1;
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);

        static::assertEquals('RED', $result->data['redColor']);
        static::assertEquals('BLUE', $result->data['blueColor']);
        static::assertEquals('TEST', $result->data['numericEnum']);
        static::assertArrayNotHasKey('errors', $result->toArray());
    }

    /**
     * @see it('supports resolving the value for a GraphQLEnumType in input types')
     */
    public function testSupportsResolvingTheValueForAGraphQLEnumTypeInInputTypes() : void
    {
        $shorthand = '
            enum Color {
                RED
                BLUE    
            }
            enum NumericEnum {
                TEST
            }
            schema {
                query: Query
            }
            type Query {
                colorTest(color: Color): String
                numericTest(num: NumericEnum): Int
            }
        ';

        $testQuery = '{
            red: colorTest(color: RED)
            blue: colorTest(color: BLUE)
            num: numericTest(num: TEST)
        }';

        $resolveFunctions = [
            'Color' => [
                'RED' => '#EA3232',
                'BLUE' => '#0000FF',
            ],
            'NumericEnum' => ['TEST' => 1],
            'Query' => [
                'colorTest' => static function ($root, $args) {
                    return $args['color'];
                },
                'numericTest' => static function ($root, $args) {
                    return $args['num'];
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);

        static::assertEquals($resolveFunctions['Color']['RED'], $result->data['red']);
        static::assertEquals($resolveFunctions['Color']['BLUE'], $result->data['blue']);
        static::assertEquals($resolveFunctions['NumericEnum']['TEST'], $result->data['num']);
        static::assertArrayNotHasKey('errors', $result->toArray());
    }

    /**
     * @see it('can set description and deprecation reason')
     */
    public function testCanSetDescriptionAndDeprecationReason() : void
    {
        $shorthand = '
            type BirdSpecies {
                name: String!,
                wingspan: Int
            }
            type RootQuery {
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
        ';

        $resolveFunctions = [
            'RootQuery' => [
                'species' => [
                    'description' => 'A species',
                    'deprecationReason' => 'Just because',
                    'resolve' => static function ($root, $args) {
                        $name = $args['name'];

                        return [
                            [
                                'name' => 'Hello ' . $name . '!',
                                'wingspan' => 200,
                            ],
                        ];
                    },
                ],
            ],
        ];

        $testQuery = '{
            __type(name: "RootQuery") {
                name
                fields(includeDeprecated: true){
                    name
                    description
                    deprecationReason
                }
            }
        }';

        $solution = [
            'data' => [
                '__type' => [
                    'name' => 'RootQuery',
                    'fields' => [
                        [
                            'name' => 'species',
                            'description' => 'A species',
                            'deprecationReason' => 'Just because',
                        ],
                    ],
                ],
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolveFunctions,
        ]);

        $result = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($solution, $result->toArray());
    }

    /**
     * * @see it('shows a warning if a field has arguments but no resolve func')
     */
    public function testShowsAWarningIfAFieldHasArgumentsButNoResolveFunc() : void
    {
        $short = '
            type Query {
                bird(id: ID): String
            }
            schema {
                query: Query
            }
        ';

        $rf = ['Query' => []];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
                'resolverValidationOptions' => ['requireResolversForArgs' => true],
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('Resolve function missing for "Query.bird"', $exception->getMessage());
        }
    }

    /**
     * @see it('does not throw an error if `resolverValidationOptions.requireResolversForArgs` is false')
     */
    public function testDoesNotThrowAnErrorIfResolverValidationOptionsRequireResolversForArgsIsFalse() : void
    {
        $short = '
            type Query{
                bird(id: ID): String
            }
            schema {
                query: Query
            }
        ';

        $rf = ['Query' => []];

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $short,
            'resolvers' => $rf,
        ]);

        static::assertTrue(true);
    }

    /**
     * @see it('throws an error if a resolver is not a function')
     */
    public function testThrowsAnErrorIfAResolverIsNotAFunction() : void
    {
        $short = '
            type Query{
                bird(id: ID): String
            }
            schema {
                query: Query
            }
        ';

        $rf = ['Query' => ['bird' => 'NOT A FUNCTION']];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
            ]);
        } catch (Throwable $exception) {
            static::assertEquals('Resolver Query.bird must be object or function', $exception->getMessage());
        }
    }

    /**
     * @see it('shows a warning if a field is not scalar, but has no resolve func')
     */
    public function testShowsAWarningIfAFieldIsNotScalarButHasNoResolveFunc() : void
    {
        $short = '
            type Bird {
                id: ID
            }
            type Query {
                bird: Bird
            }
            schema {
                query: Query
            }
        ';

        $rf = [];

        $resolverValidationOptions = ['requireResolversForNonScalar' => true];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
                'resolverValidationOptions' => $resolverValidationOptions,
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('Resolve function missing for "Query.bird"', $exception->getMessage());
        }
    }

    /**
     * @see it('allows non-scalar field to use default resolve func if
     * `resolverValidationOptions.requireResolversForNonScalar` = false')
     */
    public function testAllowsNonScalarFieldToUseDefaultResolveFuncIfResolverValidationIsOff() : void
    {
        $short = '
            type Bird {
                id: ID
            }
            type Query {
                bird: Bird
            }
            schema {
                query: Query
            }
        ';

        $rf = [];

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $short,
            'resolvers' => $rf,
            'resolverValidationOptions' => ['requireResolversForNonScalar' => false],
        ]);

        $this->assertTrue(true);
    }

    /**
     * @see it('throws if resolver defined for non-object/interface type')
     */
    public function testThrowsIfResolverDefinedForNonObjectInterfaceType() : void
    {
        $short = '
            union Searchable = Person | Location
            type Person {
                name: String
                age: Int
            }
            type Location {
                name: String
                coordinates: String
            }
            type RootQuery {
                search(name: String): [Searchable]
            }
            schema {
                query: RootQuery
            }
        ';

        $rf = [
            'Searchable' => [
                'name' => static function () {
                    return 'Something';
                },
            ],
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
                'resolverValidationOptions' => ['requireResolversForResolveType' => false],
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals(
                'Searchable was defined in resolvers, but it\'s not an object',
                $exception->getMessage()
            );
        }

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $short,
            'resolvers' => $rf,
            'resolverValidationOptions' => [
                'allowResolversNotInSchema' => true,
                'requireResolversForResolveType' => false,
            ],
        ]);
    }

    /**
     * @see it('throws if resolver defined for non existent type')
     */
    public function testThrowsIfResolverDefinedForNonExistentType() : void
    {
        $short = '
            type Person {
                name: String
                age: Int
            }
            type RootQuery {
                search(name: String): [Person]
            }
            schema {
                query: RootQuery
            }
        ';

        $rf = [
            'Searchable' => [
                'name' => static function () {
                    return 'Something';
                },
            ],
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('"Searchable" defined in resolvers, but not in schema', $exception->getMessage());
        }

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $short,
            'resolvers' => $rf,
            'resolverValidationOptions' => ['allowResolversNotInSchema' => true],
        ]);
    }

    /**
     * @see it('throws if resolver value is invalid')
     */
    public function testThrowsIfResolverValueIsInvalid() : void
    {
        $short = '
            type Person {
                name: String
                age: Int
            }
            type RootQuery {
                search(name: String): [Person]
            }
            schema {
                query: RootQuery
            }
        ';

        $rf = ['Searchable' => null];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals(
                '"Searchable" defined in resolvers, but has invalid value "NULL".' .
                ' A resolver\'s value must be of type object or function.',
                $exception->getMessage()
            );
        }
    }

    /**
     * @see it('doesnt let you define resolver field not present in schema')
     */
    public function testDoesntLetYouDefineResolverFieldNotPresentInSchema() : void
    {
        $short = '
            type Person {
                name: String
                age: Int
            }
            type RootQuery {
                search(name: String): [Person]
            }
            schema {
                query: RootQuery
            }
        ';

        $rf = [
            'RootQuery' => [
                'name' => static function () {
                    return 'Something';
                },
            ],
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('RootQuery.name defined in resolvers, but not in schema', $exception->getMessage());
        }

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $short,
            'resolvers' => $rf,
            'resolverValidationOptions' => ['allowResolversNotInSchema' => true],
        ]);
    }

    /**
     * @see it('does not let you define resolver field for enum values not present in schema')
     */
    public function testDoesNotLetYouDefineResolverFieldForEnumValuesNotPresentInSchema() : void
    {
        $short = '
            enum Color {
                RED
            }
            enum NumericEnum {
                TEST
            }
            schema {
                query: Query
            }
            type Query {
                color: Color
                numericEnum: NumericEnum
            } 
        ';

        $rf = [
            'Color' => [
                'RED' => '#EA3232',
                'NO_RESOLVER' => '#EA3232',
            ],
            'NumericEnum' => ['TEST' => 1],
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $short,
                'resolvers' => $rf,
            ]);
        } catch (Throwable $exception) {
            static::assertEquals(
                'Color.NO_RESOLVER was defined in resolvers, but enum is not in schema',
                $exception->getMessage()
            );
        }

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $short,
            'resolvers' => $rf,
            'resolverValidationOptions' => ['allowResolversNotInSchema' => true],
        ]);
    }

    /**
     * @see it('throws if conflicting validation options are passed')
     */
    public function testThrowsIfConflictingValidationOptionsArePassed() : void
    {
        $typeDefs  = '
            type Bird {
                id: ID
            }
            type Query {
                bird: Bird
            }
            schema {
                query: Query
            }
        ';
        $resolvers = [];

        $assertOptionsError = static function ($resolverValidationOptions) use ($typeDefs, $resolvers) : void {
            try {
                GraphQLTools::makeExecutableSchema([
                    'typeDefs' => $typeDefs,
                    'resolvers' => $resolvers,
                    'resolverValidationOptions' => $resolverValidationOptions,
                ]);
                static::fail();
            } catch (Throwable $error) {
                static::assertInstanceOf(TypeError::class, $error);
            }
        };

        $assertOptionsError([
            'requireResolversForAllFields' => true,
            'requireResolversForNonScalar' => true,
            'requireResolversForArgs' => true,
        ]);

        $assertOptionsError([
            'requireResolversForAllFields' => true,
            'requireResolversForNonScalar' => true,
        ]);

        $assertOptionsError([
            'requireResolversForAllFields' => true,
            'requireResolversForArgs' => true,
        ]);
    }

    /**
     * @see it('throws for any missing field if `resolverValidationOptions.requireResolversForAllFields` = true')
     */
    public function testThrowsForAnyMissingFieldIfResolverValidationOptionsRequireResolversForAllFieldsTrue() : void
    {
        $typeDefs = '
            type Bird {
                id: ID
            }
            type Query {
                bird: Bird
            }
            schema {
                query: Query
            }
        ';

        $assertFieldError = static function ($errorMatcher, $resolvers) use ($typeDefs) : void {
            try {
                GraphQLTools::makeExecutableSchema([
                    'typeDefs' => $typeDefs,
                    'resolvers' => $resolvers,
                    'resolverValidationOptions' => ['requireResolversForAllFields' => true],
                ]);
                static::fail();
            } catch (Throwable $exception) {
                static::assertEquals('Resolve function missing for "' . $errorMatcher . '"', $exception->getMessage());
            }
        };

        // different form original test. webonyx/graphql-php does not respect order in typeDefs
        $assertFieldError('Query.bird', []);
        $assertFieldError('Query.bird', [
            'Bird' => [
                'id' => static function ($bird) {
                    return $bird['id'];
                },
            ],
        ]);
        $assertFieldError('Bird.id', [
            'Query' => [
                'bird' => static function () {
                    return ['id' => '123'];
                },
            ],
        ]);
    }

    /**
     * @see it('does not throw if all fields are satisfied
     * when `resolverValidationOptions.requireResolversForAllFields` = true')
     */
    public function testDoesNotThrowIfAllFieldsAreSatisfied() : void
    {
        $typeDefs = '
            type Bird {
                id: ID
            }
            type Query {
                bird: Bird
            }
            schema {
                query: Query
            }
        ';

        $resolvers = [
            'Bird' => [
                'id' => static function ($bird) {
                    return $bird['id'];
                },
            ],
            'Query' => [
                'bird' => static function () {
                    return ['id' => '123'];
                },
            ],
        ];

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefs,
            'resolvers' => $resolvers,
            'resolverValidationOptions' => ['requireResolversForAllFields' => true],
        ]);

        static::assertTrue(true);
    }

    /**
     * @see it('throws an error if a resolve field cannot be used')
     */
    public function testThrowsAnErrorIfAResolveFieldCannotBeUsed() : void
    {
        $shorthand = '
            type BirdSpecies {
                name: String!,
                wingspan: Int
            }
            type RootQuery {
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
        ';

        $resolveFunctions = [
            'RootQuery' => [
                'speciez' => static function ($root, $args) {
                    $name = $args['name'];
                    return [
                        [
                            'name' => 'Hello ' . $name . '!',
                            'wingspan' => 200,
                        ],
                    ];
                },
            ],
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $shorthand,
                'resolvers' => $resolveFunctions,
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('RootQuery.speciez defined in resolvers, but not in schema', $exception->getMessage());
        }
    }

    /**
     * @see it('throws an error if a resolve type is not in schema')
     */
    public function testThrowsAnErrorIfAResolveTypeIsNotInSchema() : void
    {
        $shorthand = '
            type BirdSpecies {
                name: String!,
                wingspan: Int
            }
            type RootQuery {
                species(name: String!): [BirdSpecies]
            }
            schema {
                query: RootQuery
            }
        ';

        $resolveFunctions = [
            'BootQuery' => [
                'species' => static function ($root, $args) {
                    $name = $args['name'];

                    return [
                        [
                            'name' => 'Hello ' . $name . '!',
                            'wingspan' => 200,
                        ],
                    ];
                },
            ],
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $shorthand,
                'resolvers' => $resolveFunctions,
            ]);
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('"BootQuery" defined in resolvers, but not in schema', $exception->getMessage());
        }
    }

    /**
     * @see it('logs an error if a resolve function fails')
     */
    public function testLogsAnErrorIfAResolveFunctionFails() : void
    {
        $shorthand = '
            type RootQuery {
                species(name: String): String
            }
            schema {
                query: RootQuery
            }
        ';

        $resolve = [
            'RootQuery' => [
                'species' => static function () : void {
                    throw new Exception('oops!');
                },
            ],
        ];

        $logger    = new SimpleLogger();
        $jsSchema  = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolve,
            'logger' => $logger,
        ]);
        $testQuery = '{ species }';
        $expected  = 'Error in resolver RootQuery.species' . PHP_EOL . 'oops!';
        GraphQL::executeQuery($jsSchema, $testQuery);

        static::assertCount(1, $logger->errors);
        static::assertEquals($expected, $logger->errors[0]->getMessage());
    }

    /**
     * @see it('will throw errors on undefined if you tell it to')
     */
    public function testWillThrowErrorsOnUndefinedIfYouTellItTo() : void
    {
        static::markTestSkipped('There is no undefined in PHP');

        $shorthand = '
            type RootQuery {
                species(name: String): String
                stuff: String
            }
            schema {
                query: RootQuery
            }
        ';

        $resolve = [
            'RootQuery' => [
                'species' => static function () : void {
                },
                'stuff' => static function () {
                    return 'stuff';
                },
            ],
        ];

        $logger          = new SimpleLogger();
        $jsSchema        = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $shorthand,
            'resolvers' => $resolve,
            'logger' => $logger,
            'allowUndefinedInResolve' => false,
        ]);
        $testQuery       = '{ species, stuff }';
        $expectedErr     = '/Resolve function for "RootQuery.species" returned undefined/';
        $expectedResData = ['species' => null, 'stuff' => 'stuff'];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertCount(1, $logger->errors);
        static::assertTrue(preg_match($expectedErr, $logger->errors[0]->getMessage()));
        static::assertEquals($expectedResData, $res->data);
    }

    /**
     * @see describe('Attaching connectors to schema')
     * @see describe('Schema level resolve function')
     * @see it('actually runs')
     */
    public function testActuallyRuns() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        $rootResolver = static function () {
            return ['species' => 'ROOT'];
        };

        AddSchemaLevelResolveFunction::invoke($jsSchema, $rootResolver);

        $query = '{
            species(name: "strix")
        }';

        $res = GraphQL::executeQuery($jsSchema, $query);
        static::assertEquals('ROOTstrix', $res->data['species']);
    }

    /**
     * @see it('can wrap fields that do not have a resolver defined')
     */
    public function testCanWrapFieldsThatDoNotHaveAResolverDefined() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        $rootResolver = static function () {
            return ['stuff' => 'stuff'];
        };

        AddSchemaLevelResolveFunction::invoke($jsSchema, $rootResolver);
        $query = '{
            stuff
        }';

        $res = GraphQL::executeQuery($jsSchema, $query);
        static::assertEquals('stuff', $res->data['stuff']);
    }

    /**
     * @see it('runs only once per query')
     */
    public function testRunsOnlyOncePerQuery() : void
    {
        $simpleResolvers = [
            'RootQuery' => [
                'usecontext' => static function ($r, $a, $ctx) {
                    return $ctx->usecontext;
                },
                'useTestConnector' => static function ($r, $a, $ctx) {
                    return $ctx->connectors->TestConnector->get();
                },
                'useContextConnector' => static function ($r, $a, $ctx) {
                    return $ctx->connectors->ContextConnector->get();
                },
                'species' => static function ($root, $args) {
                    $name = $args['name'];
                    return $root['species'] . $name;
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => $simpleResolvers,
        ]);

        $count        = 0;
        $rootResolver = static function () use (&$count) {
            if ($count === 0) {
                $count += 1;
                return ['stuff' => 'stuff', 'species' => 'some '];
            }
            if ($count === 1) {
                $count += 1;
                return ['stuff' => 'stuff2', 'species' => 'species2 '];
            }

            return ['stuff' => 'EEE', 'species' => 'EEE'];
        };

        AddSchemaLevelResolveFunction::invoke($jsSchema, $rootResolver);
        $query = '{
            species(name: "strix")
            stuff
        }';

        $expected  = [
            'species' => 'some strix',
            'stuff' => 'stuff',
        ];
        $expected2 = [
            'species' => 'species2 strix',
            'stuff' => 'stuff2',
        ];

        $res = GraphQL::executeQuery($jsSchema, $query);
        static::assertEquals($expected, $res->data);
        $res2 = GraphQL::executeQuery($jsSchema, $query);
        static::assertEquals($expected2, $res2->data);
    }

    /**
     * @see it('can attach things to context')
     */
    public function testCanAttachThingsToContext() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        $rootResolver = static function ($o, $a, &$ctx) : void {
            $ctx->usecontext = 'ABC';
        };

        AddSchemaLevelResolveFunction::invoke($jsSchema, $rootResolver);
        $query    = '{
            usecontext
        }';
        $expected = ['usecontext' => 'ABC'];

        $res = GraphQL::executeQuery($jsSchema, $query, [], new stdClass());
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('can attach with existing static connectors')
     */
    public function testCanAttachWithExistingStaticConnectors() : void
    {
        $resolvers = [
            'RootQuery' => [
                'testString' => static function ($root, $args, $ctx) {
                    return $ctx->connectors->staticString;
                },
            ],
        ];

        $typeDef = '
            type RootQuery {
                testString: String
            }
            schema {
                query: RootQuery
            }
        ';

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDef,
            'resolvers' => $resolvers,
            'connectors' => TestHelper::getTestConnectors(),
        ]);

        $query = '{
            testString
        }';

        $expected = ['testString' => 'Hi You!'];

        $res = GraphQL::executeQuery($jsSchema, $query, [], (object) [
            'connectors' => ['staticString' => 'Hi You!'],
        ]);

        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('actually attaches the connectors')
     */
    public static function testActuallyAttachesTheConnectors() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        AttachConnectorsToContext::invoke($jsSchema, TestHelper::getTestConnectors());
        $query = '{
            useTestConnector
        }';

        $expected = ['useTestConnector' => 'works'];

        $res = GraphQL::executeQuery($jsSchema, $query, [], (object) []);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('actually passes the context to the connector constructor')
     */
    public function testActuallyPassesTheContextToTheConnectorConstructor() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        AttachConnectorsToContext::invoke($jsSchema, TestHelper::getTestConnectors());
        $query = '{
            useContextConnector
        }';

        $expected = ['useContextConnector' => 'YOYO'];

        $res = GraphQL::executeQuery($jsSchema, $query, [], (object) ['str' => 'YOYO']);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('throws error if trying to attach connectors twice')
     */
    public function testThrowsErrorIfTryingToAttachConnectorsTwice() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        AttachConnectorsToContext::invoke($jsSchema, TestHelper::getTestConnectors());
        try {
            AttachConnectorsToContext::invoke($jsSchema, TestHelper::getTestConnectors());
            static::fail();
        } catch (Throwable $exception) {
            static::assertEquals('Connectors already attached to context, cannot attach more than once', $exception->getMessage());
        }
    }

    /**
     * @see it('throws error during execution if context is not an object')
     */
    public function testThrowsErrorDuringExecutionIfContextIsNotAnObject() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);
        AttachConnectorsToContext::invoke($jsSchema, ['someConnector' => []]);
        $query = '{
            useTestConnector
        }';
        $res   = GraphQL::executeQuery($jsSchema, $query, [], 'notObject');
        static::assertEquals('Cannot attach connector because context is not an object: string', $res->errors[0]->getMessage());
    }

    /**
     * @see it('throws error if trying to attach non-functional connectors')
     */
    public function testThrowsErrorIfTryingToAttachNonFunctionalConnectors() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);
        AttachConnectorsToContext::invoke($jsSchema, ['testString' => 'a']);
        $query = '{
            species(name: "strix")
            stuff
            useTestConnector
        }';

        $res = GraphQL::executeQuery($jsSchema, $query, null, (object) []);
        static::assertEquals('Connector must be a function or an class', $res->errors[0]->getMessage());
    }

    /**
     * @see it('does not interfere with schema level resolve function')
     */
    public function testDoesNotInterfereWithSchemaLevelResolveFunction() : void
    {
        $jsSchema     = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);
        $rootResolver = static function () {
            return [
                'stuff' => 'stuff',
                'species' => 'ROOT',
            ];
        };

        AddSchemaLevelResolveFunction::invoke($jsSchema, $rootResolver);
        AttachConnectorsToContext::invoke($jsSchema, TestHelper::getTestConnectors());

        $query = '
            {
                species(name: "strix")
                stuff
                useTestConnector
            }
        ';

        $expected = [
            'species' => 'ROOTstrix',
            'stuff' => 'stuff',
            'useTestConnector' => 'works',
        ];

        $res = GraphQL::executeQuery($jsSchema, $query, [], (object) []);
        static::assertEquals($expected, $res->data);
    }

    public function testThrowsErrorIfConnectorsArgumentIsAnNumericArray() : void
    {
        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        try {
            AttachConnectorsToContext::invoke($jsSchema, [TestHelper::getTestConnector()]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('Expected an associative array, got numeric array', $error->getMessage());
        }
    }

    /**
     * @see it('outputs a working GraphQL schema')
     */
    public function testOutputsAWorkingGraphQLSchema() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
            'connectors' => TestHelper::getTestConnectors(),
        ]);

        $query = '{
            species(name: "uhu")
            stuff
            usecontext
            useTestConnector
        }';

        $expected = [
            'species' => 'ROOTuhu',
            'stuff' => 'stuff',
            'useTestConnector' => 'works',
            'usecontext' => 'ABC',
        ];

        $res = GraphQL::executeQuery($schema, $query, [], (object) ['usecontext' => 'ABC']);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('can chain two resolvers')
     */
    public function testCanChainTwoResolvers() : void
    {
        $r1 = static function ($root) {
            return $root + 1;
        };

        $r2 = static function ($root, $args) {
            return $root + $args['addend'];
        };

        $rChained = ChainResolvers::invoke([$r1, $r2]);
        self::assertEquals(3, $rChained(0, ['addend' => 2], null, null));
    }

    /**
     * @see it('uses default resolver when a resolver is undefined')
     */
    public function testUsesDefaultResolverWhenAResolverIsUndefined() : void
    {
        $r1 = static function ($root, $args) {
            return [
                'person' => [
                    'name' => $args['name'],
                ],
            ];
        };

        $r3 = static function ($root) {
            return $root['name'];
        };

        $rChained = ChainResolvers::invoke([$r1, null, $r3]);

        static::assertEquals(
            'tony',
            $rChained(0, ['name' => 'tony'], null, new ResolveInfo(['fieldName' => 'person']))
        );
    }
}
