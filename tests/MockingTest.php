<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use DateTime;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\ReactPromiseAdapter;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Mock;
use GraphQLTools\SimpleLogger;
use PHPUnit\Framework\TestCase;
use Throwable;
use function count;
use function explode;
use function in_array;
use function is_bool;
use function is_string;
use function React\Promise\resolve;
use function strtolower;

class MockingTest extends TestCase
{
    /** @var string */
    protected $shorthand;
    /** @var mixed[] */
    protected $resolveFunctions;

    public function setUp() : void
    {
        parent::setUp();
        $this->shorthand        = '
            scalar MissingMockType
            interface Flying {
                id:String!
                returnInt: Int
            }
            type Bird implements Flying {
                id:String!
                returnInt: Int
                returnString: String
                returnStringArgument(s: String): String
            }
            type Bee implements Flying {
                id:String!
                returnInt: Int
                returnEnum: SomeEnum
            }
            union BirdsAndBees = Bird | Bee
            enum SomeEnum {
                A
                B
                C
            }
            type RootQuery {
                returnInt: Int
                returnFloat: Float
                returnString: String
                returnBoolean: Boolean
                returnID: ID
                returnEnum: SomeEnum
                returnBirdsAndBees: [BirdsAndBees]
                returnFlying: [Flying]
                returnMockError: MissingMockType
                returnNullableString: String
                returnNonNullString: String!
                returnObject: Bird
                returnListOfInt: [Int]
                returnListOfIntArg(l: Int): [Int]
                returnListOfListOfInt: [[Int!]!]!
                returnListOfListOfIntArg(l: Int): [[Int]]
                returnListOfListOfObject: [[Bird!]]!
                returnStringArgument(s: String): String
                node(id:String!):Flying
                node2(id:String!):BirdsAndBees
            }
            type RootMutation{
                returnStringArgument(s: String): String
            }
            schema {
                query: RootQuery
                mutation: RootMutation
            }
        ';
        $this->resolveFunctions = [
            'BirdsAndBees' => [
                '__resolveType' => static function ($data, $context, ResolveInfo $info) : Type {
                    return $info->schema->getType($data['__typename']);
                },
            ],
            'Flying' => [
                '__resolveType' => static function ($data, $context, ResolveInfo $info) : Type {
                    return $info->schema->getType($data['__typename']);
                },
            ],
        ];
    }

    /**
     * @see it('throws an error if you forget to pass schema')
     */
    public function testThrowsAnErrorIfYouForgetToPassSchema() : void
    {
        try {
            Mock::addMockFunctionsToSchema([]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('Must provide schema to mock', $error->getMessage());
        }
    }

    /**
     * @see it('throws an error if the property "schema" on the first argument is not of type GraphQLSchema')
     */
    public function testThrowsAnErrorIfThePropertySchemaOnThFirstArgumentIsNotOfTypeSchema() : void
    {
        try {
            Mock::addMockFunctionsToSchema(['schema' => ['']]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('Value at "schema" must be of type Schema', $error->getMessage());
        }
    }

    /**
     * @see it('throws an error if second argument is not a Map')
     */
    public function testThrowsAnErrorIfSecondArgumentIsNotAMap() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        try {
            Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => 'x']);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('mocks must be an associative array', $error->getMessage());
        }
    }

    /**
     * @see it('throws an error if mockFunctionMap contains a non-function thingy')
     */
    public function testThrowsAnErrorIfMockFunctionMapContainsANonFunctionThingy() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = ['Int' => 55];
        try {
            Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('mockFunctionMap[Int] must be callable', $error->getMessage());
        }
    }

    /**
     * @see it('mocks the default types for you')
     */
    public function testMocksTheDefaultTypesForYou() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnInt
            returnFloat
            returnBoolean
            returnString
            returnID
        }';

        $res = GraphQL::executeQuery($jsSchema, $testQuery);

        static::assertTrue($res->data['returnInt'] > -1000 && $res->data['returnInt'] < 1000);
        static::assertTrue($res->data['returnFloat'] > -1000 && $res->data['returnFloat'] < 1000);
        static::assertTrue(is_bool($res->data['returnBoolean']));
        static::assertTrue(is_string($res->data['returnString']));
        static::assertTrue(is_string($res->data['returnID']));
    }

    /**
     * @see it('lets you use mockServer for convenience')
     */
    public function testLetsYouUseMockServerForConvenience() : void
    {
        $testQuery = '
            {
                returnInt
                returnFloat
                returnBoolean
                returnString
                returnID
                returnBirdsAndBees {
                    ... on Bird {
                        returnInt
                        returnString
                    }
                    ... on Bee {
                        returnInt
                        returnEnum
                    }
                }
            }
        ';

        $mockMap = [
            'Int' => static function () {
                return 12345;
            },
            'Bird' => static function () {
                return [
                    'returnInt' => static function () {
                        return 54321;
                    },
                ];
            },
            'Bee' => static function () {
                return [
                    'returnInt' => static function () {
                        return 54321;
                    },
                ];
            },
        ];

        $res = Mock::mockServer($this->shorthand, $mockMap)
            ->query($testQuery);

        static::assertEquals(12345, $res->data['returnInt']);
        static::assertTrue($res->data['returnFloat'] > -1000 && $res->data['returnFloat'] < 1000);
        static::assertTrue(is_bool($res->data['returnBoolean']));
        static::assertTrue(is_string($res->data['returnString']));
        static::assertTrue(is_string($res->data['returnID']));

        // tests that resolveType is correctly set for unions and interfaces
        // and that the correct mock function is used
        static::assertEquals(54321, $res->data['returnBirdsAndBees'][0]['returnInt']);
        static::assertEquals(54321, $res->data['returnBirdsAndBees'][1]['returnInt']);
    }

    /**
     * @see it('mockServer is able to preserveResolvers of a prebuilt schema')
     */
    public function testMockServerIsAbleToPreserveResolversOfAPrebuiltSchema() : void
    {
        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $resolvers = [
            'RootQuery' => [
                'returnString' => static function () {
                    return 'someString';
                },
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        $testQuery = '
        {
            returnInt
            returnString
            returnBirdsAndBees {
                ... on Bird {
                  returnInt
                }
                ... on Bee {
                  returnInt
                }
            }
        }';
        $mockMap   = [
            'Int' => static function () {
                return 12345;
            },
            'Bird' => static function () {
                return [
                    'returnInt' => static function () {
                        return 54321;
                    },
                ];
            },
            'Bee' => static function () {
                return [
                    'returnInt' => static function () {
                        return 54321;
                    },
                ];
            },
        ];
        $res       = Mock::mockServer($jsSchema, $mockMap, true)->query($testQuery);

        static::assertEquals(12345, $res->data['returnInt']);
        static::assertEquals('someString', $res->data['returnString']);

        // tests that resolveType is correctly set for unions and interfaces
        // and that the correct mock function is used
        static::assertEquals(54321, $res->data['returnBirdsAndBees'][0]['returnInt']);
        static::assertEquals(54321, $res->data['returnBirdsAndBees'][1]['returnInt']);
    }

    /**
     * @see it('lets you use mockServer with prebuilt schema')
     */
    public function testLetsYouUseMockServerWithPrebuiltSchema() : void
    {
        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $testQuery = '
        {
            returnInt
            returnFloat
            returnBoolean
            returnString
            returnID
            returnBirdsAndBees {
                ... on Bird {
                    returnInt
                    returnString
                }
                ... on Bee {
                    returnInt
                    returnEnum
                }
            }
        }';
        $mockMap   = [
            'Int' => static function () {
                return 12345;
            },
            'Bird' => static function () {
                return [
                    'returnInt' => static function () {
                        return 54321;
                    },
                ];
            },
            'Bee' => static function () {
                return [
                    'returnInt' => static function () {
                        return 54321;
                    },
                ];
            },
        ];

        $res = Mock::mockServer($jsSchema, $mockMap)->query($testQuery);

        static::assertEquals(12345, $res->data['returnInt']);
        static::assertTrue($res->data['returnFloat'] > -1000 && $res->data['returnFloat'] < 1000);
        static::assertTrue(is_bool($res->data['returnBoolean']));
        static::assertTrue(is_string($res->data['returnString']));
        static::assertTrue(is_string($res->data['returnID']));

        // tests that resolveType is correctly set for unions and interfaces
        // and that the correct mock function is used
        static::assertEquals(54321, $res->data['returnBirdsAndBees'][0]['returnInt']);
        static::assertEquals(54321, $res->data['returnBirdsAndBees'][1]['returnInt']);
    }

    /**
     * @see it('does not mask resolveType functions if you tell it not to')
     */
    public function testDoesNotMaskResolveTypeFunctionsIfYouTellItNotTo() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $spy      = 0;

        $resolvers = [
            'BirdsAndBees' => [
                '__resolveType' => static function ($data, $context, ResolveInfo $info) use (&$spy) {
                    ++$spy;
                    return $info->schema->getType($data['__typename']);
                },
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => [],
            'preserveResolvers' => true,
        ]);
        $testQuery = '
        {
            returnBirdsAndBees {
                ... on Bird {
                    returnInt
                    returnString
                }
                ... on Bee {
                    returnInt
                    returnEnum
                }
            }
        }';
        GraphQL::executeQuery($jsSchema, $testQuery);
        // the resolveType has been called twice
        static::assertEquals(2, $spy);
    }

    /**
     * @see it('can mock Enum')
     */
    public function testCanMockEnum() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{ returnEnum }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertTrue(in_array($res->data['returnEnum'], ['A', 'B', 'C']));
    }

    /**
     * @see it('can mock Unions')
     */
    public function testCanMockUnions() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        GraphQLTools::addResolveFunctionsToSchema($jsSchema, $this->resolveFunctions);
        $mockMap = [
            'Int' => static function () {
                return 10;
            },
            'String' => static function () {
                return 'aha';
            },
            'SomeEnum' => static function () {
                return 'A';
            },
            'RootQuery' => static function () {
                return [
                    'returnBirdsAndBees' => static function () {
                        return new Mock\MockList(40);
                    },
                ];
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '
        {
            returnBirdsAndBees {
                ... on Bird {
                    returnInt
                    returnString
                }
                ... on Bee {
                    returnInt
                    returnEnum
                }
            }
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);

        // XXX this test is expected to fail once every 2^40 times ;-)
        $foundBird = false;
        $foundBee  = false;

        foreach ($res->data['returnBirdsAndBees'] as $birdOrBee) {
            if (! $foundBird) {
                $foundBird = $birdOrBee === [
                    'returnInt' => 10,
                    'returnString' => 'aha',
                ];
            } elseif (! $foundBee) {
                $foundBee = $birdOrBee === [
                    'returnInt' => 10,
                    'returnEnum' => 'A',
                ];
            }
        }

        static::assertTrue($foundBird);
        static::assertTrue($foundBee);
    }

    /**
     * @see it('can mock Interfaces by default')
     */
    public function testCanMockInterfacesByDefault() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        GraphQLTools::addResolveFunctionsToSchema($jsSchema, $this->resolveFunctions);
        $mockMap = [
            'Int' => static function () {
                return 10;
            },
            'String' => static function () {
                return 'aha';
            },
            'SomeEnum' => static function () {
                return 'A';
            },
            'RootQuery' => static function () {
                return [
                    'returnFlying' => static function () {
                        return new Mock\MockList(40);
                    },
                ];
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '
        {
            returnFlying {
                ... on Bird {
                  returnInt
                  returnString
                }
                ... on Bee {
                  returnInt
                  returnEnum
                }
            }
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);

        $foundA = false;
        $foundB = false;
        foreach ($res->data['returnFlying'] as $data) {
            if (! $foundA) {
                $foundA = $data === [
                    'returnInt' => 10,
                    'returnString' => 'aha',
                ];
            }

            if ($foundB) {
                continue;
            }

            $foundB = $data === [
                'returnInt' => 10,
                'returnEnum' => 'A',
            ];
        }

        static::assertTrue($foundA);
        static::assertTrue($foundB);
    }

    /**
     * @see it('can support explicit Interface mock')
     */
    public function testCanSupportExplicitInterfaceMock() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        GraphQLTools::addResolveFunctionsToSchema($jsSchema, $this->resolveFunctions);
        $spy     = 0;
        $mockMap = [
            'Bird' => static function ($root, $args) {
                return [
                    'id' => $args['id'],
                    'returnInt' => 100,
                ];
            },
            'Bee' => static function ($root, $args) {
                return [
                    'id' => $args['id'],
                    'returnInt' => 200,
                ];
            },
            'Flying' => static function ($root, $args) use (&$spy) {
                $spy++;
                $id   = $args['id'];
                $type = explode(':', $id)[0];

                $__typename = null;
                foreach (['Bird', 'Bee'] as $r) {
                    if (strtolower($r) === $type) {
                        $__typename = $r;
                        break;
                    }
                }

                return ['__typename' => $__typename];
            },
        ];
        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);
        $testQuery = '
        {
            node(id:"bee:123456") {
                id,
                returnInt
            }
        }';

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals(1, $spy); // to make sure that Flying possible types are not randomly selected
        static::assertEquals([
            'id' => 'bee:123456',
            'returnInt' => 200,
        ], $res->data['node']);
    }

    /**
     * @see it('can support explicit UnionType mock')
     */
    public function testCanSupportExplicitUnionTypeMock() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        GraphQLTools::addResolveFunctionsToSchema($jsSchema, $this->resolveFunctions);
        $spy     = 0;
        $mockMap = [

            'Bird' => static function ($root, $args) {
                return [
                    'id' => $args['id'],
                    'returnInt' => 100,
                ];
            },
            'Bee' => static function ($root, $args) {
                return [
                    'id' => $args['id'],
                    'returnEnum' => 'A',
                ];
            },
            'BirdsAndBees' => static function ($root, $args) use (&$spy) {
                $spy++;
                $id         = $args['id'];
                $type       = explode(':', $id)[0];
                $__typename = null;
                foreach (['Bird', 'Bee'] as $r) {
                    if (strtolower($r) === $type) {
                        $__typename = $r;
                        break;
                    }
                }

                return ['__typename' => $__typename];
            },
        ];
        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);
        $testQuery = '{
            node2(id:"bee:123456"){
                ...on Bee{
                    id,
                    returnEnum
                }
            }
        }';

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals(1, $spy);
        static::assertEquals([
            'id' => 'bee:123456',
            'returnEnum' => 'A',
        ], $res->data['node2']);
    }

    /**
     * @see it('throws an error when __typename is not returned within an explicit interface mock')
     */
    public function testThrowsAnErrorWhenTypenameIsNotReturnedWithinAnExplicitInterfaceMock() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        GraphQLTools::addResolveFunctionsToSchema($jsSchema, $this->resolveFunctions);
        $mockMap = [

            'Bird' => static function ($root, $args) {
                return [
                    'id' => $args['id'],
                    'returnInt' => 100,
                ];
            },
            'Bee' => static function ($root, $args) {
                return [
                    'id' => $args['id'],
                    'returnInt' => 'A',
                ];
            },
            'Flying' => static function ($root, $args) : void {
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '
        {
            node(id:"bee:123456"){
                id,
                returnInt
            }
        }';
        $expected  = 'Please return a __typename in "Flying"';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->errors[0]->getPrevious()->getMessage());
    }

    /**
     * @see it('throws an error in resolve if mock type is not defined')
     */
    public function testThrowsAnErrorInResolveIfMockTypeIsNotDefined() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnMockError
        }';
        $expected  = 'No mock defined for type "MissingMockType"';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->errors[0]->getPrevious()->getMessage());
    }

    /**
     * @see it('throws an error in resolve if mock type is not defined and resolver failed')
     */
    public function testThrowsAnErrorInResolveIfMockTypeIsNotDefinedAndResolverFailed() : void
    {
        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $resolvers = [
            'MissingMockType' => [
                '__serialize' => static function ($val) {
                    return $val;
                },
                '__parseValue' => static function ($val) {
                    return $val;
                },
                '__parseLiteral' => static function ($val) {
                    return $val;
                },
            ],
            'RootQuery' => [
                'returnMockError' => static function () {
                    return null;
                },
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        $mockMap = [];
        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);
        $testQuery = '
        {
            returnMockError
        }';
        $expected  = 'No mock defined for type "MissingMockType"';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->errors[0]->getPrevious()->getMessage());
    }

    /**
     * @see it('can preserve scalar resolvers')
     */
    public function testCanPreserveScalarResolvers() : void
    {
        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $resolvers = [
            'MissingMockType' => [
                '__serialize' => static function ($val) {
                    return $val;
                },
                '__parseValue' => static function ($val) {
                    return $val;
                },
                '__parseLiteral' => static function ($val) {
                    return $val;
                },
            ],
            'RootQuery' => [
                'returnMockError' => static function () {
                    return '10-11-2012';
                },
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        $mockMap = [];
        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnMockError
        }';

        $expected = ['returnMockError' => '10-11-2012'];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
        static::assertEmpty($res->errors);
    }

    /**
     * @see it('can mock an Int')
     */
    public function testCanMockAnInt() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'Int' => static function () {
                return 55;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnInt
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals(55, $res->data['returnInt']);
    }

    /**
     * @see it('can mock a Float')
     */
    public function testCanMockAFloat() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'Float' => static function () {
                return 55.5;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnFloat
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals(55.5, $res->data['returnFloat']);
    }

    /**
     * @see it('can mock a Boolean')
     */
    public function testCanMockABoolean() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'Boolean' => static function () {
                return true;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnBoolean
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertTrue($res->data['returnBoolean']);
    }

    /**
     * @see it('can mock an ID')
     */
    public function testCanMockAnID() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'ID' => static function () {
                return 'ea5bdc19';
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnID
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals('ea5bdc19', $res->data['returnID']);
    }

    /**
     * @see it('nullable type is nullable'')
     */
    public function testNullableTypeIsNullable() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'String' => static function () {
                return null;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnNullableString
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertNull($res->data['returnNullableString']);
    }

    /**
     * @see it('can mock a nonNull type')
     */
    public function testCanMockANonNullType() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'String' => static function () {
                return 'nonnull';
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnNonNullString
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals('nonnull', $res->data['returnNonNullString']);
    }

    /**
     * @see it('nonNull type is not nullable')
     */
    public function testNonNullTypeIsNotNullable() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'String' => static function () {
                return null;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnNonNullString
        }';
        $res       = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertNull($res->data);
        static::assertCount(1, $res->errors);
    }

    /**
     * @see it('can mock object types')
     */
    public function testCanMockObjectTypes() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'String' => static function () {
                return 'abc';
            },
            'Int' => static function () {
                return 123;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnObject {
                returnInt
                returnString
            }
        }';
        $expected  = [
            'returnObject' => [
                'returnInt' => 123,
                'returnString' => 'abc',
            ],
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('can mock a list of ints')
     */
    public function testCanMockAListOfInts() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'Int' => static function () {
                return 123;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnListOfInt
        }';
        $expected  = [
            'returnListOfInt' => [123, 123],
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('can mock a list of lists of objects')
     */
    public function testCanMocAListOfObjects() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'String' => static function () {
                return 'a';
            },
            'Int' => static function () {
                return 1;
            },
        ];
        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnListOfListOfObject { returnInt, returnString }
        }';
        $expected  = [
            'returnListOfListOfObject' => [
                [
                    ['returnInt' => 1, 'returnString' => 'a'],
                    ['returnInt' => 1, 'returnString' => 'a'],
                ],
                [
                    ['returnInt' => 1, 'returnString' => 'a'],
                    ['returnInt' => 1, 'returnString' => 'a'],
                ],
            ],
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('does not mask resolve functions if you tell it not to')
     */
    public function testDoesNotMaskResolveFunctionsIfYouTellItNotTo() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnInt' => static function () {
                        // a) in resolvers, will not be used
                        return '42';
                    },
                    'returnFloat' => static function () {
                        // b) not in resolvers, will be used
                        return 1.3;
                    },
                    'returnString' => static function () {
                        // c) in resolvers, will not be used
                        return resolve('foo');
                    },
                ];
            },
        ];

        $resolvers = [
            'RootQuery' => [
                'returnInt' => static function () {
                    // see a)
                    return 5;
                },
                'returnString' => static function () {
                    // see c)
                    return resolve('bar');
                },
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnInt
            returnFloat
            returnString
        }';

        $expected = [
            'returnInt' => 5, // a) from resolvers, not masked by mock
            'returnFloat' => 1.3, // b) from mock
            'returnString' => 'bar', // c) from resolvers, not masked by mock (and promise)
        ];

        GraphQL::promiseToExecute(new ReactPromiseAdapter(), $jsSchema, $testQuery)
            ->then(static function (ExecutionResult $res) use ($expected) : void {
                static::assertEquals($expected, $res->data);
            });
    }

    /**
     * @see it('lets you mock non-leaf types conveniently')
     */
    public function testLetsYouMockNonLeafTypesConveniently() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'Bird' => static function () {
                return [
                    'returnInt' => 12,
                    'returnString' => 'woot!?',
                ];
            },
            'Int' => static function () {
                return 15;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
            returnObject{
                returnInt
                returnString
            }
            returnInt
        }';

        $expected = [
            'returnObject' => [
                'returnInt' => 12,
                'returnString' => 'woot!?',
            ],
            'returnInt' => 15,
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you mock and resolve non-leaf types concurrently')
     */
    public function testLetsYouMockAndResolveNonLeafTypesConcurrently() : void
    {
        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $resolvers = [
            'RootQuery' => [
                'returnListOfInt' => static function () {
                    return [1, 2, 3];
                },
                'returnObject' => static function () {
                    return ['returnInt' => 12]; // a) part of a Bird, should not be masked by mock
                },
                // no returnString returned
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        $mockMap = [
            'returnListOfInt' => static function () {
                return [5, 6, 7];
            },
            'Bird' => static function () {
                return [
                    'returnInt' => 3, // see a)
                    'returnString' => 'woot!?', // b) another part of a Bird
                ];
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnListOfInt
            returnObject {
                returnInt
                returnString
            }
        }';
        $expected  = [
            'returnListOfInt' => [1, 2, 3],
            'returnObject' => [
                'returnInt' => 12, // from the resolver, see a)
                'returnString' => 'woot!?', // from the mock, see b)
            ],
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you mock and resolve non-leaf types concurrently, support promises')
     */
    public function testLetsYouMockAndResolveNonLeafTypesConcurrentlySupportPromises() : void
    {
        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $resolvers = [
            'RootQuery' => [
                'returnObject' => static function () {
                    return resolve(['returnInt' => 12]); // a) part of a Bird, should not be masked by mock
                },
                // no returnString returned
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        $mockMap = [
            'Bird' => static function () {
                return [
                    'returnInt' => 3, // see a)
                    'returnString' => 'woot!?', // b) another part of a Bird
                ];
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnObject {
                returnInt
                returnString
            }
        }';
        $expected  = [
            'returnObject' => [
                'returnInt' => 12, // from the resolver, see a)
                'returnString' => 'woot!?', // from the mock, see b)
            ],
        ];

        GraphQL::promiseToExecute(new ReactPromiseAdapter(), $jsSchema, $testQuery)
            ->then(static function ($res) use ($expected) : void {
                static::assertEquals($expected, $res->data);
            });
    }

    /**
     * @see it('let you mock with preserving resolvers, also when using logger')
     */
    public function testLetYoMockWithPreservingResolversAlsoWhenUsingLogger() : void
    {
        $resolvers = [
            'RootQuery' => [
                'returnString' => static function () {
                    return 'woot!?'; // a) resolve of a string
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => [$this->shorthand],
            'resolvers' => $resolvers,
            'resolverValidationOptions' => [
                'requireResolversForArgs' => false,
                'requireResolversForNonScalar' => false,
                'requireResolversForAllFields' => false,
                'requireResolversForResolveType' => false,
            ],
            'logger' => new SimpleLogger(),
        ]);

        $mockMap = [
            'Int' => static function () {
                return 123; // b) mock of Int.
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnObject {
                returnInt
                returnString
            }
            returnString
        }';

        $expected = [
            'returnObject' => [
                'returnInt' => 123, // from the mock, see b)
                'returnString' => 'Hello World', // from mock default values.
            ],
            'returnString' => 'woot!?', // from the mock, see a)
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('let you mock with preserving resolvers, also when using connectors')
     */
    public function testLetYoMockWithPreservingResolversAlsoWhenUsingConnectors() : void
    {
        $resolvers = [
            'RootQuery' => [
                'returnString' => static function () {
                    return 'woot!?'; // a) resolve of a string
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => [$this->shorthand],
            'resolvers' => $resolvers,
            'resolverValidationOptions' => [
                'requireResolversForArgs' => false,
                'requireResolversForNonScalar' => false,
                'requireResolversForAllFields' => false,
                'requireResolversForResolveType' => false,
            ],
            'connectors' => [
                'testConnector' => static function () : array {
                    return [];
                },
            ],
        ]);

        $mockMap = [
            'Int' => static function () {
                return 123; // b) mock of Int.
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnObject {
                returnInt
                returnString
            }
            returnString
        }';

        $expected = [
            'returnObject' => [
                'returnInt' => 123, // from the mock, see b)
                'returnString' => 'Hello World', // from mock default values.
            ],
            'returnString' => 'woot!?', // from the mock, see a)
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery, null, (object) []);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('let you mock with preserving resolvers, also when using both connectors and logger')
     */
    public function testLetYoMockWithPreservingResolversAlsoWhenUsingBothConnectorsAndLogger() : void
    {
        $resolvers = [
            'RootQuery' => [
                'returnString' => static function () {
                    return 'woot!?'; // a) resolve of a string
                },
            ],
        ];

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => [$this->shorthand],
            'resolvers' => $resolvers,
            'resolverValidationOptions' => [
                'requireResolversForArgs' => false,
                'requireResolversForNonScalar' => false,
                'requireResolversForAllFields' => false,
                'requireResolversForResolveType' => false,
            ],
            'logger' => new SimpleLogger(),
            'connectors' => [
                'testConnector' => static function () : array {
                    return [];
                },
            ],
        ]);

        $mockMap = [
            'Int' => static function () {
                return 123; // b) mock of Int.
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnObject {
                returnInt
                returnString
            }
            returnString
        }';

        $expected = [
            'returnObject' => [
                'returnInt' => 123, // from the mock, see b)
                'returnString' => 'Hello World', // from mock default values.
            ],
            'returnString' => 'woot!?', // from the mock, see a)
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery, null, (object) []);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('let you resolve null with mocking and preserving resolvers')
     */
    public function testLetYouResolveNullWithMockingAndPreservingResolvers() : void
    {
        static::markTestSkipped('PHP cannot differentiate between undefined and null');

        $jsSchema  = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $resolvers = [
            'RootQuery' => [
                'returnString' => static function () {
                    return null; // a) resolve of a string
                },
            ],
        ];

        GraphQLTools::addResolveFunctionsToSchema(
            $jsSchema,
            $resolvers,
            ['requireResolversForResolveType' => false]
        );

        $mockMap = [
            'Int' => static function () {
                return 666; // b) mock of Int.
            },
        ];

        Mock::addMockFunctionsToSchema([
            'schema' => $jsSchema,
            'mocks' => $mockMap,
            'preserveResolvers' => true,
        ]);

        $testQuery = '{
            returnObject {
                returnInt
                returnString
            }
            returnString
        }';

        $expected = [
            'returnObject' => [
                'returnInt' => 666, // from the mock, see b)
                'returnString' => 'Hello World', // from mock default values.
            ],
            'returnString' => null, /// from the mock, see a)
        ];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you mock root query fields')
     */
    public function testLetsYouMockRootQueryFields() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnStringArgument' => static function ($o, $a) {
                        return $a['s'];
                    },
                ];
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = '{
            returnStringArgument(s: "adieu")
        }';

        $expected = ['returnStringArgument' => 'adieu'];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you mock root mutation fields')
     */
    public function testLetsYouMockRootMutationFields() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootMutation' => static function () {
                return [
                    'returnStringArgument' => static function ($o, $a) {
                        return $a['s'];
                    },
                ];
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = 'mutation {
            returnStringArgument(s: "adieu")
        }';

        $expected = ['returnStringArgument' => 'adieu'];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you mock a list of a certain length')
     */
    public function testLetsYouMockAListOfCertainLength() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnListOfInt' => static function () {
                        return new Mock\MockList(3);
                    },
                ];
            },
            'Int' => static function () {
                return 12;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = '{
            returnListOfInt
        }';

        $expected = ['returnListOfInt' => [12, 12, 12]];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you mock a list of a random length')
     */
    public function testLetsYouMockAListOfARandomLength() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnListOfInt' => static function () {
                        return new Mock\MockList([10, 20]);
                    },
                ];
            },
            'Int' => static function () {
                return 12;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = '{
            returnListOfInt
        }';

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertTrue(count($res->data['returnListOfInt']) >= 10 && count($res->data['returnListOfInt']) <= 20);
        static::assertEquals(12, $res->data['returnListOfInt'][0]);
    }

    /**
     * @see it('lets you mock a list of specific variable length')
     */
    public function testLetsYouMockAListOfSpecificVariableLength() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnListOfIntArg' => static function ($o, array $a) {
                        return new Mock\MockList($a['l']);
                    },
                ];
            },
            'Int' => static function () {
                return 12;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = '{
            l3: returnListOfIntArg(l: 3)
            l5: returnListOfIntArg(l: 5)
        }';

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertCount(3, $res->data['l3']);
        static::assertCount(5, $res->data['l5']);
    }

    /**
     * @see it('lets you provide a function for your MockList')
     */
    public function testLetsYouProvideAFunctionForYourMockList() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);

        $mockMap = [
            'RootQuery' => static function () {
                return [
                    'returnListOfInt' => static function () {
                        return new Mock\MockList(2, static function () {
                            return 33;
                        });
                    },
                ];
            },
            'Int' => static function () {
                return 12;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = '{
            returnListOfInt
        }';

        $expected = ['returnListOfInt' => [33, 33]];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you nest MockList in MockList')
     */
    public function testLetsYouNestMockListInMockList() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnListOfListOfInt' => static function () {
                        return new Mock\MockList(2, static function () {
                            return new Mock\MockList(3);
                        });
                    },
                ];
            },
            'Int' => static function () {
                return 12;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = '{
                returnListOfListOfInt
            }';

        $expected = ['returnListOfListOfInt' => [[12, 12, 12], [12, 12, 12]]];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('lets you use arguments in nested MockList')
     */
    public function testLetsYouUseArgumentsInNestedMockList() : void
    {
        $jsSchema = GraphQLTools::buildSchemaFromTypeDefinitions($this->shorthand);
        $mockMap  = [
            'RootQuery' => static function () {
                return [
                    'returnListOfListOfIntArg' => static function () {
                        return new Mock\MockList(2, static function ($o, $a) {
                            return new Mock\MockList($a['l']);
                        });
                    },
                ];
            },
            'Int' => static function () {
                return 12;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);

        $testQuery = '{
            returnListOfListOfIntArg(l: 1)
        }';

        $expected = ['returnListOfListOfIntArg' => [[12], [12]]];

        $res = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('works for a slightly more elaborate example')
     */
    public function testWorksForASlightlyMoreElaborateExample() : void
    {
        $short          = '
            type Thread {
                id: ID!
                name: String!
                posts(page: Int = 0, num: Int = 1): [Post]
            }
            type Post {
                id: ID!
                user: User!
                text: String!
            }
            type User {
                id: ID!
                name: String
            }
            type RootQuery {
                thread(id: ID): Thread
                threads(page: Int = 0, num: Int = 1): [Thread]
            }
            schema {
                query: RootQuery
            }
        ';
        $jsSchema       = GraphQLTools::buildSchemaFromTypeDefinitions($short);
        $ITEMS_PER_PAGE = 2;

        // This mock map demonstrates default merging on objects and nested lists.
        // thread on root query will have id a.id, and missing properties
        // come from the Thread mock type
        // TODO: this tests too many things at once, it should really be broken up
        // it was really useful to have this though, because it made me find many
        // unintuitive corner-cases
        $mockMap = [
            'RootQuery' => static function () use ($ITEMS_PER_PAGE) {
                return [
                    'thread' => static function ($o, array $a) {
                        return [
                            'id' => $a['id'],
                        ];
                    },
                    'threads' => static function ($o, array $a) use ($ITEMS_PER_PAGE) {
                        return new Mock\MockList($ITEMS_PER_PAGE * $a['num']);
                    },
                ];
            },
            'Thread' => static function () use ($ITEMS_PER_PAGE) {
                return [
                    'name' => 'Lorem Ipsum',
                    'posts' => static function ($o, array $a) use ($ITEMS_PER_PAGE) {
                        return new Mock\MockList(
                            $ITEMS_PER_PAGE * $a['num'],
                            static function ($oi, array $ai) {
                                return [
                                    'id' => $ai['num'],
                                ];
                            }
                        );
                    },
                ];
            },
            'Post' => static function () {
                return [
                    'id' => '41ae7bd',
                    'text' => 'superlongpost',
                ];
            },
            'Int' => static function () {
                return 123;
            },
        ];

        Mock::addMockFunctionsToSchema(['schema' => $jsSchema, 'mocks' => $mockMap]);
        $testQuery = 'query abc {
            thread(id: "67") {
                id
                name
                posts(num: 2) {
                    id
                    text
                }
            }
        }';

        $expected = [
            'thread' => [
                'id' => '67',
                'name' => 'Lorem Ipsum',
                'posts' => [
                    ['id' => '2', 'text' => 'superlongpost'],
                    ['id' => '2', 'text' => 'superlongpost'],
                    ['id' => '2', 'text' => 'superlongpost'],
                    ['id' => '2', 'text' => 'superlongpost'],
                ],
            ],
        ];
        $res      = GraphQL::executeQuery($jsSchema, $testQuery);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('works for resolvers returning javascript Dates')
     */
    public function testWorksForResovlersReturningDates() : void
    {
        $typeDefs = '
    	    scalar Date
            type DateObject {
                start: Date!
            }
            type Query {
      	        date1: DateObject
                date2: Date
                date3: Date
            }
        ';

        $resolvers = [
            'Query' => [
                'date1' => static function () {
                    return [
                        'start' => new DateTime('2018-01-03'),
                    ];
                },
                'date2' => static function () {
                    return new DateTime('2016-01-01');
                },
            ],
            'DateObject' => [
                'start' => static function ($obj) {
                    return $obj['start'];
                },
            ],
            'Date' => [
                '__serialize' => static function (DateTime $val) {
                    return $val->format(DateTime::ATOM);
                },
                '__parseValue' => static function (string $val) {
                    return DateTime::createFromFormat(DateTime::ATOM, $val);
                },
                '__parseLiteral' => static function (string $val) {
                    return DateTime::createFromFormat(DateTime::ATOM, $val);
                },
            ],
        ];

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefs,
            'resolvers' => $resolvers,
        ]);

        Mock::addMockFunctionsToSchema([
            'schema' => $schema,
            'mocks' => [
                'Date' => static function () {
                    return new DateTime('2016-05-04');
                },
            ],
            'preserveResolvers' => true,
        ]);

        $query = '
            {
                  date1 {
                        start
                  }
                  date2
                  date3
            }
        ';

        $expected = [
            'date1' => ['start' => '2018-01-03T00:00:00+00:00'],
            'date2' => '2016-01-01T00:00:00+00:00',
            'date3' => '2016-05-04T00:00:00+00:00',
        ];

        $res = GraphQL::executeQuery($schema, $query);
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('allows instanceof checks in __resolveType')
     */
    public function testAllowsInstanceofChecksInResolveType() : void
    {
        $account = new class
        {
            /** @var string */
            public $id;
            /** @var string */
            public $username;

            public function __construct()
            {
                $this->id       = '123nmasb';
                $this->username = 'foo@bar.com';
            }
        };

        $typeDefs = '
            interface Node {
                id: ID!
            }
            type Account implements Node {
                id: ID!
                username: String
            }
            type User implements Node {
                id: ID!
            }
            type Query {
                node: Node
            }
        ';

        $resolvers = [
            'Query' => [
                'node' => static function () use ($account) {
                    return $account;
                },
            ],
            'Node' => [
                '__resolveType' => static function ($obj) use ($account) {
                    if ($obj instanceof $account) {
                        return 'Account';
                    }

                    return null;
                },
            ],
        ];

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefs,
            'resolvers' => $resolvers,
        ]);

        Mock::addMockFunctionsToSchema([
            'schema' => $schema,
            'preserveResolvers' => true,
        ]);

        $query = '
        {
            node {
                ...on Account {
                    id
                    username
                }
            }
        }
        ';

        $expected = [
            'data' => [
                'node' => [
                    'id' => '123nmasb',
                    'username' => 'foo@bar.com',
                ],
            ],
        ];
        $res      = GraphQL::executeQuery($schema, $query);
        static::assertEquals($expected, $res->toArray());
    }
}
