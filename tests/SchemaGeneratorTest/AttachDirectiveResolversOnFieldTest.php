<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\SchemaGeneratorTest;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Promise\Adapter\ReactPromiseAdapter;
use GraphQL\GraphQL;
use GraphQLTools\Generate\AttachDirectiveResolvers;
use GraphQLTools\GraphQLTools;
use PHPUnit\Framework\TestCase;
use Throwable;
use function is_string;
use function React\Promise\resolve;
use function strtolower;
use function strtoupper;

class AttachDirectiveResolversOnFieldTest extends TestCase
{
    /** @var ReactPromiseAdapter */
    protected $promiseAdapter;
    /** @var string */
    protected $testSchemaWithDirectives;
    /** @var object */
    protected $testObject;
    /** @var mixed[] */
    protected $testResolversDirectives;
    /** @var mixed[] */
    protected $directiveResolvers;

    public function setUp() : void
    {
        parent::setUp();

        $this->promiseAdapter = new ReactPromiseAdapter();

        $this->testSchemaWithDirectives = '
            directive @upper on FIELD_DEFINITION
            directive @lower on FIELD_DEFINITION
            directive @default(value: String!) on FIELD_DEFINITION
            directive @catchError on FIELD_DEFINITION
        
            type TestObject {
                hello: String @upper
            }
            
            type RootQuery {
                hello: String @upper
                withDefault: String @default(value: "some default_value")
                object: TestObject
                asyncResolver: String @upper
                multiDirectives: String @upper @lower
                throwError: String @catchError
            }
            schema {
                query: RootQuery
            }
        ';

        $this->testObject = (object) ['hello' => 'giau. tran minh'];

        $this->testResolversDirectives = [
            'RootQuery' => [
                'hello' => static function () {
                    return 'giau. tran minh';
                },
                'object' => function () {
                    return $this->testObject;
                },
                'asyncResolver' => static function () {
                    return resolve('giau. tran minh');
                },
                'multiDirectives' => static function () {
                    return 'Giau. Tran Minh';
                },
                'throwError' => static function () : void {
                    throw new Error('This error for testing');
                },
            ],
        ];

        $this->directiveResolvers = [
            'lower' => static function ($next, $src, $args, $context) {
                return resolve($next())->then(static function ($str) {
                    if (is_string($str)) {
                        return strtolower($str);
                    }

                    return $str;
                });
            },

            'upper' => static function ($next, $src, $args, $context) {
                return resolve($next())->then(static function ($str) {
                    if (is_string($str)) {
                        return strtoupper($str);
                    }

                    return $str;
                });
            },

            'default' => static function ($next, $src, $args, $context, $info) {
                return resolve($next())->then(static function ($res) use ($args) {
                    if ($res === null) {
                        return $args['value'];
                    }

                    return $res;
                });
            },

            'catchError' => static function ($next, $src, $args, $context) {
                try {
                    return resolve($next());
                } catch (Throwable $error) {
                    return $error->getMessage();
                }
            },
        ];
    }

    /**
     * @see it('throws error if directiveResolvers argument is an array')
     */
    public function testThrowsErrorIfDirectiveResolversArgumentIsAnArray() : void
    {
        static::markTestSkipped('Not failable in php');

        $jsSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => TestHelper::getTestSchema(),
            'resolvers' => TestHelper::getTestResolvers(),
        ]);

        try {
            AttachDirectiveResolvers::invoke($jsSchema, [1]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('Expected directiveResolvers to be of type object, got Array', $error->getMessage());
        }
    }

    /**
     * @see it('upper String from resolvers')
     */
    public function testUpperStringFromResolvers() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => $this->directiveResolvers,
        ]);

        $query = '{
            hello
        }';

        $expected = ['hello' => 'GIAU. TRAN MINH'];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });

        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('using default resolver for object property')
     */
    public function testUsingDefaultResolverForObjectProperty() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => $this->directiveResolvers,
        ]);

        $query = '{
            object {
                hello
            }
        }';

        $expected = [
            'object' => ['hello' => 'GIAU. TRAN MINH'],
        ];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });

        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('passes in directive arguments to the directive resolver')
     */
    public function testPassesInDirectiveArgumentsToTheDirectiveResolver() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => $this->directiveResolvers,
        ]);

        $query = '{
            withDefault
        }';

        $expected = ['withDefault' => 'some default_value'];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });

        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('No effect if missing directive resolvers')
     */
    public function testNoEffectIfMissingDirectiveResolvers() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => [],
            // Empty resolver
        ]);

        $query = '{
            hello
        }';

        $expected = ['hello' => 'giau. tran minh'];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('If resolver return Promise, keep using it')
     */
    public function testIfResolverReturnPromiseKeepUsingIt() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => $this->directiveResolvers,
        ]);

        $query = '{
            asyncResolver
        }';

        $expected = ['asyncResolver' => 'GIAU. TRAN MINH'];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('Multi directives apply with LTR order')
     */
    public function testMultiDirectivesApplyWithLTROrder() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => $this->directiveResolvers,
        ]);

        $query = '{
            multiDirectives
        }';

        $expected = ['multiDirectives' => 'giau. tran minh'];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });
        static::assertEquals($expected, $res->data);
    }

    /**
     * @see it('Allow to catch error from next resolver')
     */
    public function testAllowToCatchErrorFromNextResolver() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithDirectives,
            'resolvers' => $this->testResolversDirectives,
            'directiveResolvers' => $this->directiveResolvers,
        ]);

        $query = '{
            throwError
        }';

        $expected = ['throwError' => 'This error for testing'];

        /** @var ExecutionResult $res */
        $res = null;
        GraphQL::promiseToExecute($this->promiseAdapter, $schema, $query, [], [])
            ->then(static function (ExecutionResult $r) use (&$res) : void {
                $res = $r;
            });

        static::assertEquals($expected, $res->data);
    }
}
