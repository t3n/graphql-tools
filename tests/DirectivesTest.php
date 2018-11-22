<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use DateTime;
use Exception;
use GraphQL\Error\Error;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\ReactPromiseAdapter;
use GraphQL\GraphQL;
use GraphQL\Language\VisitorOperation;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\EnumValueDefinition;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\SchemaDirectiveVisitor;
use GraphQLTools\SchemaVisitor;
use GraphQLTools\Utils;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use stdClass;
use Throwable;
use function array_filter;
use function array_keys;
use function array_map;
use function array_search;
use function array_slice;
use function call_user_func_array;
use function count;
use function get_class;
use function is_string;
use function React\Promise\reject;
use function React\Promise\resolve;
use function sha1;
use function sort;
use function sprintf;
use function strlen;
use function strrev;
use function strtoupper;
use function substr;

class DirectivesTest extends TestCase
{
    /** @var string */
    protected $typeDefs = '
        directive @schemaDirective(role: String) on SCHEMA
        directive @queryTypeDirective on OBJECT
        directive @queryFieldDirective on FIELD_DEFINITION
        directive @enumTypeDirective on ENUM
        directive @enumValueDirective on ENUM_VALUE
        directive @dateDirective(tz: String) on SCALAR
        directive @interfaceDirective on INTERFACE
        directive @interfaceFieldDirective on FIELD_DEFINITION
        directive @inputTypeDirective on INPUT_OBJECT
        directive @inputFieldDirective on INPUT_FIELD_DEFINITION
        directive @mutationTypeDirective on OBJECT
        directive @mutationArgumentDirective on ARGUMENT_DEFINITION
        directive @mutationMethodDirective on FIELD_DEFINITION
        directive @objectTypeDirective on OBJECT
        directive @objectFieldDirective on FIELD_DEFINITION
        directive @unionDirective on UNION
        
        schema @schemaDirective(role: "admin") {
          query: Query
          mutation: Mutation
        }

        type Query @queryTypeDirective {
            people: [Person] @queryFieldDirective
        }
        
        enum Gender @enumTypeDirective {
            NONBINARY @enumValueDirective
            FEMALE
            MALE
        }
        
        scalar Date @dateDirective(tz: "utc")

        interface Named @interfaceDirective {
            name: String! @interfaceFieldDirective
        }
        
        input PersonInput @inputTypeDirective {
            name: String! @inputFieldDirective
            gender: Gender
        }
        
        type Mutation @mutationTypeDirective {
            addPerson(
                input: PersonInput @mutationArgumentDirective
            ): Person @mutationMethodDirective
        }
        
        type Person implements Named @objectTypeDirective {
            id: ID! @objectFieldDirective
            name: String!
        }
        
        union WhateverUnion @unionDirective = Person | Query | Mutation
    ';

    /**
     * @see it('are included in the schema AST')
     */
    public function testAreIncludedInTheSchemaAST() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        $getDirectiveNames = static function ($type) {
            return array_map(static function ($d) {
                return $d->name->value;
            }, Utils::toArray($type instanceof Schema ? $type->getAstNode()->directives : $type->astNode->directives));
        };

        $checkDirectives = static function (
            $type,
            $typeDirectiveNames,
            $fieldDirectiveMap = []
        ) use ($getDirectiveNames) : void {
            static::assertEquals(
                $typeDirectiveNames,
                $getDirectiveNames($type)
            );

            foreach (array_keys($fieldDirectiveMap) as $key) {
                static::assertEquals(
                    $fieldDirectiveMap[$key],
                    $getDirectiveNames($type->getFields()[$key])
                );
            }
        };

        static::assertEquals(
            ['schemaDirective'],
            $getDirectiveNames($schema)
        );

        $checkDirectives(
            $schema->getQueryType(),
            ['queryTypeDirective'],
            [
                'people' => ['queryFieldDirective'],
            ]
        );

        static::assertEquals(
            ['enumTypeDirective'],
            $getDirectiveNames($schema->getType('Gender'))
        );

        $nonBinary = $schema->getType('Gender')->getValues()[0];

        static::assertEquals(
            ['enumValueDirective'],
            $getDirectiveNames($nonBinary)
        );

        $checkDirectives(
            $schema->getType('Date'),
            ['dateDirective']
        );

        $checkDirectives(
            $schema->getType('Named'),
            ['interfaceDirective'],
            [
                'name' => ['interfaceFieldDirective'],
            ]
        );

        $checkDirectives(
            $schema->getType('PersonInput'),
            ['inputTypeDirective'],
            [
                'name' => ['inputFieldDirective'],
                'gender' => [],
            ]
        );

        $checkDirectives(
            $schema->getMutationType(),
            ['mutationTypeDirective'],
            [
                'addPerson' => ['mutationMethodDirective'],
            ]
        );

        static::assertEquals(
            ['mutationArgumentDirective'],
            $getDirectiveNames($schema->getMutationType()->getFields()['addPerson']->args[0])
        );

        $checkDirectives(
            $schema->getType('Person'),
            ['objectTypeDirective'],
            [
                'id' => ['objectFieldDirective'],
                'name' => [],
            ]
        );

        $checkDirectives(
            $schema->getType('WhateverUnion'),
            ['unionDirective']
        );
    }

    /**
     * @see it('can be implemented with SchemaDirectiveVisitor')
     */
    public function testCanBeImplementedWithSchemaDirectiveVisitor() : void
    {
        $visited    = [];
        $schema     = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);
        $visitCount = 0;

        SchemaDirectiveVisitor::visitSchemaDirectives(
            $schema,
            [
                'queryTypeDirective' => new class ($visited, $visitCount) extends SchemaDirectiveVisitor
                {
                    /** @var string */
                    public static $description = 'A @directive for query object types';
                    /** @var ObjectType[] */
                    protected $visited;
                    /** @var int */
                    protected $visitCount;

                    /**
                     * @param ObjectType[] $visited
                     */
                    public function __construct(array &$visited, int &$visitCount)
                    {
                        $this->visited    = &$visited;
                        $this->visitCount = &$visitCount;
                    }

                    public function visitObject(ObjectType $object) : void
                    {
                        $this->visited[] = $object;

                        $this->visitCount++;
                    }
                },
            ]
        );

        static::assertEquals(1, count($visited));
        static::assertEquals(1, $visitCount);
        foreach ($visited as $object) {
            static::assertEquals($schema->getType('Query'), $object);
        }
    }

    /**
     * @see it('can visit the schema itself')
     */
    public function testCanVisitTheSchemaItself() : void
    {
        $visited = [];
        $schema  = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        SchemaDirectiveVisitor::visitSchemaDirectives(
            $schema,
            [
                'schemaDirective' => new class ($visited) extends SchemaDirectiveVisitor
                {
                    /** @var Schema[] */
                    private $visited;

                    /**
                     * @param Schema[] $visited
                     */
                    public function __construct(array &$visited)
                    {
                        $this->visited = &$visited;
                    }

                    public function visitSchema(Schema $s) : void
                    {
                        $this->visited[] = $s;
                    }
                },
            ]
        );

        static::assertCount(1, $visited);
        static::assertEquals($schema, $visited[0]);
    }

    /**
     * @see it('can visit fields within object types')
     */
    public function testCanVisitFieldsWithinObjectTypes() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        $mutationObjectType = null;
        $mutationField      = null;
        $enumObjectType     = null;
        $inputObjectType    = null;

        SchemaDirectiveVisitor::visitSchemaDirectives(
            $schema,
            [
                'mutationTypeDirective' => new class ($mutationObjectType) extends SchemaDirectiveVisitor
                {
                    /** @var ObjectType */
                    private $mutationObjectType;

                    public function __construct(?ObjectType &$mutationObjectType)
                    {
                        $this->mutationObjectType = &$mutationObjectType;
                    }

                    public function visitObject(ObjectType $object) : void
                    {
                        $this->mutationObjectType = $object;
                        TestCase::assertEquals($object, $this->visitedType);
                        TestCase::assertEquals('Mutation', $object->name);
                    }
                },
                'mutationMethodDirective' => new class (
                    $mutationObjectType, $mutationField) extends SchemaDirectiveVisitor
                {
                    /** @var ObjectType */
                    private $mutationObjectType;
                    /** @var FieldDefinition */
                    private $mutationField;

                    public function __construct(?ObjectType &$mutationObjectType, ?FieldDefinition &$mutationField)
                    {
                        $this->mutationObjectType = &$mutationObjectType;
                        $this->mutationField      = &$mutationField;
                    }

                    /**
                     * @param mixed[] $details
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $details) : void
                    {
                        TestCase::assertEquals($field, $this->visitedType);
                        TestCase::assertEquals('addPerson', $field->name);
                        TestCase::assertEquals($this->mutationObjectType, $details['objectType']);
                        TestCase::assertEquals(1, count($field->args));

                        $this->mutationField = $field;
                    }
                },
                'mutationArgumentDirective' => new class (
                    $mutationObjectType,
                    $mutationField
                ) extends SchemaDirectiveVisitor
                {
                    /** @var ObjectType */
                    private $mutationObjectType;
                    /** @var FieldDefinition */
                    private $mutationField;

                    public function __construct(?ObjectType &$mutationObjectType, ?FieldDefinition &$mutationField)
                    {
                        $this->mutationObjectType = &$mutationObjectType;
                        $this->mutationField      = &$mutationField;
                    }

                    /**
                     * @param mixed[] $details
                     */
                    public function visitArgumentDefinition(FieldArgument $arg, array $details) : void
                    {
                        TestCase::assertEquals($arg, $this->visitedType);
                        TestCase::assertEquals('input', $arg->name);
                        TestCase::assertEquals($this->mutationField, $details['field']);
                        TestCase::assertEquals($this->mutationObjectType, $details['objectType']);
                        TestCase::assertEquals($arg, $details['field']->args[0]);
                    }
                },
                'enumTypeDirective' => new class ($enumObjectType) extends SchemaDirectiveVisitor
                {
                    /** @var EnumType */
                    private $enumObjectType;

                    public function __construct(?EnumType &$enumObjectType)
                    {
                        $this->enumObjectType = &$enumObjectType;
                    }

                    public function visitEnum(EnumType $enumType) : void
                    {
                        TestCase::assertEquals($enumType, $this->visitedType);
                        TestCase::assertEquals('Gender', $enumType->name);
                        $this->enumObjectType = $enumType;
                    }
                },
                'enumValueDirective' => new class ($enumObjectType) extends SchemaDirectiveVisitor
                {
                    /** @var EnumType */
                    private $enumObjectType;

                    public function __construct(?EnumType &$enumObjectType)
                    {
                        $this->enumObjectType = &$enumObjectType;
                    }

                    /**
                     * @param mixed[] $details
                     *
                     * @return mixed|void
                     */
                    public function visitEnumValue(EnumValueDefinition $value, array $details)
                    {
                        TestCase::assertEquals($value, $this->visitedType);
                        TestCase::assertEquals('NONBINARY', $value->name);
                        TestCase::assertEquals('NONBINARY', $value->value);
                        TestCase::assertEquals($this->enumObjectType, $details['enumType']);
                    }
                },
                'inputTypeDirective' => new class ($inputObjectType) extends SchemaDirectiveVisitor
                {
                    /** @var InputObjectType */
                    private $inputObjectType;

                    public function __construct(?InputObjectType &$inputObjectType)
                    {
                        $this->inputObjectType = &$inputObjectType;
                    }

                    public function visitInputObject(InputObjectType $object) : void
                    {
                        $this->inputObjectType = $object;
                        TestCase::assertEquals($object, $this->visitedType);
                        TestCase::assertEquals('PersonInput', $object->name);
                    }
                },
                'inputFieldDirective' => new class ($inputObjectType) extends SchemaDirectiveVisitor
                {
                    /** @var InputObjectType */
                    private $inputObjectType;

                    public function __construct(?InputObjectType &$inputObjectType)
                    {
                        $this->inputObjectType = &$inputObjectType;
                    }

                    /**
                     * @param mixed[] $details
                     */
                    public function visitInputFieldDefinition(InputObjectField $field, array $details) : void
                    {
                        TestCase::assertEquals($field, $this->visitedType);
                        TestCase::assertEquals('name', $field->name);
                        TestCase::assertEquals($this->inputObjectType, $details['objectType']);
                    }
                },
            ]
        );
    }

    /**
     * @see it('can check if a visitor method is implemented')
     */
    public function testCanCheckIfAVisitorMethodIsImplemented() : void
    {
        $visitor = new class extends SchemaVisitor
        {
            public function notVisitorMethod() : void
            {
            }

            public function visitObject(ObjectType $object) : ObjectType
            {
                return $object;
            }
        };

        static::assertFalse($visitor::implementsVisitorMethod('notVisitorMethod'));
        static::assertTrue($visitor::implementsVisitorMethod('visitObject'));
        static::assertFalse($visitor::implementsVisitorMethod('visitInputFieldDefinition'));
        static::assertFalse($visitor::implementsVisitorMethod('visitBogusType'));
    }

    /**
     * it('can use visitSchema for simple visitor patterns')
     */
    public function testCanUseVisitSchemaForSimpleVisitorPatterns() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        $simpleVisitor = new class ($schema) extends SchemaVisitor
        {
            /** @var int */
            public $visitCount = 0;
            /** @var string[] */
            public $names = [];

            public function __construct(Schema $s)
            {
                $this->schema = $s;
            }

            public function visit() : void
            {
                // More complicated visitor implementations might use the
                // visitorSelector function more selectively, but this SimpleVisitor
                // class always volunteers itself to visit any schema type.
                SchemaVisitor::doVisitSchema(
                    $this->schema,
                    function () {
                        return [$this];
                    }
                );
            }

            public function visitObject(ObjectType $object) : void
            {
                TestCase::assertEquals($object, $this->schema->getType($object->name));
                $this->names[] = $object->name;
            }
        };

        $simpleVisitor->visit();
        sort($simpleVisitor->names);

        static::assertEquals(
            [
                'Mutation',
                'Person',
                'Query',
            ],
            $simpleVisitor->names
        );
    }

    /**
     * @see it('can use SchemaDirectiveVisitor as a no-op visitor')
     */
    public function testCanUseSchemaDirectiveVisitorAsANoOpVisitor() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        $methodNamesEncountered = [];

        $enthusiasticVisitor = new class extends SchemaDirectiveVisitor
        {
            /** @var bool[] */
            public static $methodNamesEncountered;

            public static function implementsVisitorMethod(string $name) : bool
            {
                // Pretend this class implements all visitor methods. This is safe
                // because the SchemaVisitor base class provides empty stubs for all
                // the visitor methods that might be called.
                return static::$methodNamesEncountered[$name] = true;
            }
        };

        $enthusiasticVisitor::$methodNamesEncountered = &$methodNamesEncountered;

        $enthusiasticVisitor::visitSchemaDirectives(
            $schema,
            [
                'schemaDirective' => $enthusiasticVisitor,
                'queryTypeDirective' => $enthusiasticVisitor,
                'queryFieldDirective' => $enthusiasticVisitor,
                'enumTypeDirective' => $enthusiasticVisitor,
                'enumValueDirective' => $enthusiasticVisitor,
                'dateDirective' => $enthusiasticVisitor,
                'interfaceDirective' => $enthusiasticVisitor,
                'interfaceFieldDirective' => $enthusiasticVisitor,
                'inputTypeDirective' => $enthusiasticVisitor,
                'inputFieldDirective' => $enthusiasticVisitor,
                'mutationTypeDirective' => $enthusiasticVisitor,
                'mutationArgumentDirective' => $enthusiasticVisitor,
                'mutationMethodDirective' => $enthusiasticVisitor,
                'objectTypeDirective' => $enthusiasticVisitor,
                'objectFieldDirective' => $enthusiasticVisitor,
                'unionDirective' => $enthusiasticVisitor,
            ]
        );

        $reflection = new ReflectionClass($enthusiasticVisitor);

        $methodNames = array_map(static function (ReflectionMethod $method) {
            if ($method->isStatic()) {
                return '';
            }

            return $method->name;
        }, $reflection->getMethods());

        $methodNames = array_filter($methodNames, static function ($methodName) {
            return substr($methodName, 0, 5) === 'visit';
        });

        $methodNamesEncountered = array_keys($methodNamesEncountered);

        sort($methodNames);
        sort($methodNamesEncountered);

        static::assertEquals(
            $methodNames,
            $methodNamesEncountered
        );
    }

    /**
     * @see it('can handle declared arguments')
     */
    public function testCanHandleDeclaredArguments() : void
    {
        $schemaText = '
            directive @oyez(
                times: Int = 5,
                party: Party = IMPARTIAL,
            ) on OBJECT | FIELD_DEFINITION
            
            schema {
                query: Courtroom
            }
            
            type Courtroom @oyez {
                judge: String @oyez(times: 0)
                marshall: String @oyez
            }
            
            enum Party {
                DEFENSE
                PROSECUTION
                IMPARTIAL
            }
        ';

        $schema = GraphQLTools::makeExecutableSchema(['typeDefs' => $schemaText]);

        $context              = new stdClass();
        $context->objectCount = 0;
        $context->fieldCount  = 0;

        $oyezVisitor = new class extends SchemaDirectiveVisitor
        {
            /** @var Schema */
            public static $mySchema;

            public static function getDirectiveDeclaration(string $name, Schema $theSchema) : ?Directive
            {
                $schema = static::$mySchema;
                TestCase::assertEquals($schema, $theSchema);
                $prev = $schema->getDirective($name);

                foreach ($prev->args as $arg) {
                    if ($arg->name !== 'times') {
                        continue;
                    }

                    $arg->defaultValue = 3;
                }

                return $prev;
            }

            public function visitObject(ObjectType $object) : void
            {
                ++$this->context->objectCount;
                TestCase::assertEquals(3, $this->args['times']);
            }

            /**
             * @param mixed[] $details
             */
            public function visitFieldDefinition(FieldDefinition $field, array $details) : void
            {
                ++$this->context->fieldCount;
                if ($field->name === 'judge') {
                    TestCase::assertEquals(0, $this->args['times']);
                } else {
                    if ($field->name === 'marshall') {
                        TestCase::assertEquals(3, $this->args['times']);
                    }
                }
                TestCase::assertEquals('IMPARTIAL', $this->args['party']);
            }
        };

        $oyezVisitor::$mySchema = $schema;

        $visitors = SchemaDirectiveVisitor::visitSchemaDirectives(
            $schema,
            ['oyez' => $oyezVisitor],
            $context
        );

        static::assertEquals(1, $context->objectCount);
        static::assertEquals(2, $context->fieldCount);

        static::assertEquals(['oyez'], array_keys($visitors));
        static::assertEquals(
            ['Courtroom', 'judge', 'marshall'],
            array_map(static function ($v) {
                return $v->visitedType->name;
            }, $visitors['oyez'])
        );
    }

    /**
     * @see it('can be used to implement the @upper example')
     */
    public function testCanBeUsedToImplementTheUpperExample() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @upper on FIELD_DEFINITION
                type Query {
                    hello: String @upper
                }
            ',
            'schemaDirectives' => [
                'upper' => new class extends SchemaDirectiveVisitor
                {
                    /**
                     * @param mixed[] $detail
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $detail) : void
                    {
                        $resolve          = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];
                        $field->resolveFn = static function (...$args) use ($resolve) {
                            $result = call_user_func_array($resolve, $args);
                            if (is_string($result)) {
                                return strtoupper($result);
                            }
                            return $result;
                        };
                    }
                },
            ],
            'resolvers' => [
                'Query' => [
                    'hello' => static function () {
                        return 'hello world';
                    },
                ],
            ],
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            '
                query {
                    hello
                }
            '
        );

        static::assertEquals(['hello' => 'HELLO WORLD'], $result->data);
    }

    /**
     * @see it('can be used to implement the @date example')
     */
    public function testCanBeUsedToImplementTheDateExample() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @date(format: String) on FIELD_DEFINITION
                scalar Date
                type Query {
                    today: Date @date(format: "F d, Y")
                }
            ',
            'schemaDirectives' => [
                'date' => new class extends SchemaDirectiveVisitor
                {
                    /**
                     * @param mixed[] $detail
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $detail) : void
                    {
                        $resolve = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];
                        $format  = $this->args['format'];

                        Utils::forceSet($field, 'type', Type::string());

                        $field->resolveFn = static function (...$args) use ($resolve, $format) {
                            $date = $resolve(...$args);
                            return DateTime::createFromFormat(DateTime::ATOM, $date)->format($format);
                        };
                    }
                },
            ],
            'resolvers' => [
                'Query' => [
                    'today' => static function () {
                        return (new DateTime('@' . 1519688273))->format(DateTime::ATOM);
                    },
                ],
            ],
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            'query { today }'
        );

        static::assertEquals(
            ['today' => 'February 26, 2018'],
            $result->data
        );
    }

    /**
     * @see it('can be used to implement the @date by adding an argument')
     */
    public function testCanBeUsedToImplementTheDateByAddingAnArgument() : void
    {
        $formattableDateDirective = new class extends SchemaDirectiveVisitor
        {
            /**
             * @param mixed[] $detail
             */
            public function visitFieldDefinition(FieldDefinition $field, array $detail) : void
            {
                $resolve       = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];
                $defaultFormat = $this->args['defaultFormat'];

                $field->args[] = new FieldArgument([
                    'name' => 'format',
                    'type' => Type::string(),
                ]);

                Utils::forceSet($field, 'type', Type::string());
                $field->resolveFn = static function ($source, $args, $context, $info) use ($resolve, $defaultFormat) {
                    $format = $args['format'] ?? $defaultFormat;
                    $date   = $resolve($source, $args, $context, $info);
                    return $date->format($format);
                };
            }
        };

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @date(
                    defaultFormat: String = "F d, Y"
                ) on FIELD_DEFINITION
                scalar Date
                type Query {
                    today: Date @date
                }
            ',
            'schemaDirectives' => ['date' => $formattableDateDirective],
            'resolvers' => [
                'Query' => [
                    'today' => static function () {
                        $date = new DateTime();
                        $date->setTimestamp(1521131357);
                        return $date;
                    },
                ],
            ],
        ]);

        $resultNoArg = GraphQL::executeQuery($schema, 'query { today }');

        if ($resultNoArg->errors) {
            static::assertEmpty($resultNoArg->errors);
        }

        static::assertEquals(
            ['today' => 'March 15, 2018'],
            $resultNoArg->data
        );

        $resultWithArg = GraphQL::executeQuery($schema, 'query { today(format: "d M Y") }');

        if ($resultWithArg->errors) {
            static::assertEmpty($resultWithArg->errors);
        }

        static::assertEquals(
            ['today' => '15 Mar 2018'],
            $resultWithArg->data
        );
    }

    /**
     * @see it('can be used to implement the @intl example')
     */
    public function testCanBeUsedToImplementTheIntlExample() : void
    {
        $translate = static function ($text, $path, $locale) {
            static::assertEquals('hello', $text);
            static::assertEquals(['Query', 'greeting'], $path);
            static::assertEquals('fr', $locale);
            return 'bonjour';
        };

        $context = ['locale' => 'fr'];

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @intl on FIELD_DEFINITION
                type Query {
                    greeting: String @intl
                }
            ',
            'schemaDirectives' => [
                'intl' => new class ($context, $translate) extends SchemaDirectiveVisitor
                {
                    /** @var mixed[] */
                    private $ctx;
                    /** @var callable */
                    private $translate;

                    /**
                     * @param mixed[] $ctx
                     */
                    public function __construct(array $ctx, callable $translate)
                    {
                        $this->ctx       = $ctx;
                        $this->translate = $translate;
                    }

                    /**
                     * @param mixed[] $details
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $details) : void
                    {
                        $resolve          = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];
                        $field->resolveFn = function (...$args) use ($resolve, $details, $field) {
                            $defaultText = $resolve(...$args);
                            // In this example, path would be ["Query", "greeting"]:
                            $path = [$details['objectType']->name, $field->name];
                            TestCase::assertEquals($this->ctx, $args[2]);
                            $translate = $this->translate;
                            return $translate($defaultText, $path, $this->ctx['locale']);
                        };
                    }
                },
            ],
            'resolvers' => [
                'Query' => [
                    'greeting' => static function () {
                        return 'hello';
                    },
                ],
            ],
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            'query {greeting }',
            null,
            $context
        );

        static::assertEquals(
            ['greeting' => 'bonjour'],
            $result->data
        );
    }

    /**
     * @see it('can be used to implement the @auth example')
     */
    public function testCanBeUsedToImplementTHeAuthExample() : void
    {
        $roles = [
            'UNKNOWN',
            'USER',
            'REVIEWER',
            'ADMIN',
        ];

        $getUser = function ($token) use ($roles) {
            return new class ($token, $roles)
            {
                /** @var string */
                private $token;
                /** @var string[] */
                private $roles;

                /**
                 * @param string[] $roles
                 */
                public function __construct(string $token, array $roles)
                {
                    $this->token = $token;
                    $this->roles = $roles;
                }

                public function hasRole(string $role) : bool
                {
                    $tokenIndex = array_search($this->token, $this->roles);
                    $roleIndex  = array_search($role, $this->roles);

                    return $roleIndex >= 0 && $tokenIndex >= $roleIndex;
                }
            };
        };

        $authDirective = new class ($getUser) extends SchemaDirectiveVisitor
        {
            /** @var callable */
            private $getUser;

            public function __construct(callable $getUser)
            {
                $this->getUser = $getUser;
            }

            public function visitObject(ObjectType $type) : void
            {
                $this->ensureFieldsWrapped($type);
                $type->_requiredAuthRole = $this->args['requires'];
            }
            // Visitor methods for nested types like fields and arguments
            // also receive a details object that provides information about
            // the parent and grandparent types.
            /**
             * @param mixed[] $details
             */
            public function visitFieldDefinition(FieldDefinition $field, array $details) : void
            {
                $this->ensureFieldsWrapped($details['objectType']);
                $field->_requiredAuthRole = $this->args['requires'];
            }

            public function ensureFieldsWrapped(ObjectType $objectType) : void
            {
                // Mark the GraphQLObjectType object to avoid re-wrapping:
                if ($objectType->_authFieldsWrapped ?? false) {
                    return;
                }
                $objectType->_authFieldsWrapped = true;

                $fields = $objectType->getFields();

                foreach ($fields as $fieldName => $field) {
                    $resolve = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];

                    $field->resolveFn = function (...$args) use ($resolve, $field, $objectType) {
                        // Get the required Role from the field first, falling back
                        // to the objectType if no Role is required by the field:
                        $requiredRole = $field->_requiredAuthRole ?? $objectType->_requiredAuthRole ?? null;

                        if (! $requiredRole) {
                            return call_user_func_array($resolve, $args);
                        }

                        $context = $args[2];
                        $getUser = $this->getUser;
                        $user    = $getUser($context['headers']['authToken']);
                        if (! $user->hasRole($requiredRole)) {
                            throw new Error('not authorized');
                        }

                        return call_user_func_array($resolve, $args);
                    };
                }
            }
        };

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @auth(
                    requires: Role = ADMIN,
                ) on OBJECT | FIELD_DEFINITION
                
                enum Role {
                    ADMIN
                    REVIEWER
                    USER
                    UNKNOWN
                }
                
                type User @auth(requires: USER) {
                    name: String
                    banned: Boolean @auth(requires: ADMIN)
                    canPost: Boolean @auth(requires: REVIEWER)
                }
                
                type Query {
                    users: [User]
                }
            ',
            'schemaDirectives' => ['auth' => $authDirective],
            'resolvers' => [
                'Query' => [
                    'users' => static function () {
                        return [
                            [
                                'banned' => true,
                                'canPost' => false,
                                'name' => 'Ben',
                            ],
                        ];
                    },
                ],
            ],
        ]);

        $execWithRole = static function ($role) use ($schema) {
            return GraphQL::executeQuery(
                $schema,
                '
                query {
                    users {
                        name
                        banned
                        canPost
                    }
                }
            ',
                null,
                [
                    'headers' => ['authToken' => $role],
                ]
            );
        };

        $checkErrors = static function ($result, $expectedCount, ...$expectedNames) {
            $errors = $result->errors;
            $data   = $result->data;

            static::assertCount($expectedCount, $errors);

            foreach ($errors as $error) {
                static::assertEquals('not authorized', $error->getMessage());
            }

            $actualNames = array_map(static function ($error) {
                return array_slice($error->getPath(), -1)[0];
            }, $errors);

            sort($expectedNames);
            sort($actualNames);
            static::assertEquals(
                $expectedNames,
                $actualNames
            );

            return $data;
        };

        $checkErrors($execWithRole('UNKNOWN'), 3, 'banned', 'canPost', 'name');
        $checkErrors($execWithRole('USER'), 2, 'banned', 'canPost');
        $checkErrors($execWithRole('REVIEWER'), 1, 'banned');

        $res = $execWithRole('ADMIN');
        $checkErrors($res, 0);

        static::assertCount(1, $res->data);
        static::assertTrue($res->data['users'][0]['banned']);
        static::assertFalse($res->data['users'][0]['canPost']);
        static::assertEquals('Ben', $res->data['users'][0]['name']);
    }

    /**
     * this is heavily modified to work
     *
     * @see it('can be used to implement the @length example')
     */
    public function testCanBeUsedToImplementTheLengthExample() : void
    {
        $createLimitedLengthType = function ($type, $maxLength) {
            return new class ($type, $maxLength) extends CustomScalarType
            {
                public function __construct(ScalarType $type, int $maxLength)
                {
                    parent::__construct([
                        'name' => 'LengthAtMost' . $maxLength,
                        'serialize' => static function ($value) use ($type, $maxLength) {
                            $value = $type->serialize($value);
                            TestCase::assertTrue(is_string($value));

                            if (strlen($value) > $maxLength) {
                                return reject(
                                    new Error(sprintf('expected %s to be at most %s', strlen($value), $maxLength))
                                );
                            }

                            return resolve($value);
                        },
                        'parseValue' => static function ($value) use ($type) {
                            return $type->parseValue($value);
                        },
                        'parseLiteral' => static function ($ast) use ($type) {
                            return $type->parseLiteral($ast);
                        },
                    ]);
                }
            };
        };

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @length(max: Int) on FIELD_DEFINITION | INPUT_FIELD_DEFINITION
                type Query {
                    books: [Book]
                }
                type Book {
                    title: String @length(max: 10)
                }
                type Mutation {
                    createBook(book: BookInput): Book
                }
                input BookInput {
                    title: String! @length(max: 10)
                }
            ',
            'schemaDirectives' => [
                'length' => new class ($createLimitedLengthType) extends SchemaDirectiveVisitor
                {
                    /** @var callable */
                    private $createLimitedLengthType;

                    public function __construct(callable $createLimitedLengthType)
                    {
                        $this->createLimitedLengthType = $createLimitedLengthType;
                    }

                    /**
                     * @param mixed[] $details
                     */
                    public function visitInputFieldDefinition(InputObjectField $field, array $details) : void
                    {
                        $this->wrapType($field);
                    }

                    /**
                     * @param mixed[] $details
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $details) : void
                    {
                        $this->wrapType($field);
                    }

                    /**
                     * @param FieldDefinition|InputObjectField $field
                     */
                    private function wrapType($field) : void
                    {
                        $createLimitedLengthType = $this->createLimitedLengthType;
                        if ($field->getType() instanceof NonNull
                            && $field->getType()->getWrappedType() instanceof ScalarType) {
                            Utils::forceSet(
                                $field,
                                'type',
                                new NonNull(
                                    $createLimitedLengthType($field->getType()->getWrappedType(), $this->args['max'])
                                )
                            );
                        } else {
                            if (! ($field->getType() instanceof ScalarType)) {
                                throw new Error('Not a scalar type: ' . get_class($field->getType()));
                            }

                            Utils::forceSet(
                                $field,
                                'type',
                                $createLimitedLengthType($field->getType(), $this->args['max'])
                            );
                        }
                    }
                },
            ],
            'resolvers' => [
                'Query' => [
                    'books' => static function () {
                        return [
                            ['title' => 'abcdefghijklmnopqrstuvwxyz'],
                        ];
                    },
                ],
                'Mutation' => [
                    'createBook' => static function ($parent, $args) {
                        return $args['book'];
                    },
                ],
            ],
        ]);

        GraphQL::promiseToExecute(
            new ReactPromiseAdapter(),
            $schema,
            '
                query {
                    books {
                        title
                    }
                }
            '
        )->then(static function ($result) use ($schema) {
            $errors = $result->errors;

            static::assertCount(1, $errors);
            static::assertEquals('expected 26 to be at most 10', $errors[0]->getMessage());

            return GraphQL::promiseToExecute(
                new ReactPromiseAdapter(),
                $schema,
                '
                    mutation {
                        createBook(book: { title: "safe title" }) {
                            title
                        }
                    }
                '
            );
        })->then(static function ($result) : void {
            static::assertEmpty($result->errors);
            static::assertEquals(
                [
                    'createBook' => ['title' => 'safe title'],
                ],
                $result->data
            );
        });
    }

    /**
     * @see it('can be used to implement the @uniqueID example')
     */
    public function testCanBeUsedToImplementTheUniqueIDExample() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @uniqueID(name: String, from: [String]) on OBJECT
                type Query {
                    people: [Person]
                    locations: [Location]
                }
                type Person @uniqueID(name: "uid", from: ["personID"]) {
                    personID: Int
                    name: String
                }
                type Location @uniqueID(name: "uid", from: ["locationID"]) {
                    locationID: Int
                    address: String
                }
            ',
            'schemaDirectives' => [
                'uniqueID' => new class extends SchemaDirectiveVisitor
                {
                    public function visitObject(ObjectType $type) : void
                    {
                        ['name' => $name, 'from' => $from] = $this->args;
                        $fields                            = $type->getFields();
                        $fields[$name]                     = FieldDefinition::create([
                            'name' => $name,
                            'type' => Type::id(),
                            'description' => 'Unique ID',
                            'args' => [],
                            'resolve' => static function ($object) use ($type, $from) {
                                $hash = $type->name;
                                foreach ($from as $fieldName) {
                                    $hash .= $object[$fieldName];
                                }
                                return sha1($hash);
                            },
                        ]);
                        Utils::forceSet($type, 'fields', $fields);
                    }
                },
            ],
            'resolvers' => [
                'Query' => [
                    'people' => static function () {
                        return [
                            [
                                'personID' => 1,
                                'name' => 'Ben',
                            ],
                        ];
                    },
                    'locations' => static function () {
                        return [
                            [
                                'locationID' => 1,
                                'address' => '140 10th St',
                            ],
                        ];
                    },
                ],
            ],
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            '
                query {
                    people {
                        uid
                        personID
                        name
                    }
                    locations {
                        uid
                        locationID
                        address
                    }
                }
            '
        );

        $data = $result->data;

        static::assertEquals(
            [
                [
                    'uid' => '580a207c8e94f03b93a2b01217c3cc218490571a',
                    'personID' => 1,
                    'name' => 'Ben',
                ],
            ],
            $data['people']
        );

        static::assertEquals(
            [
                [
                    'uid' => 'c31b71e6e23a7ae527f94341da333590dd7cba96',
                    'locationID' => 1,
                    'address' => '140 10th St',
                ],
            ],
            $data['locations']
        );
    }

    /**
     * @see it('automatically updates references to changed types')
     */
    public function testAutomaticallyUpdatesReferencesToChangedTypes() : void
    {
        $HumanType = null;
        $schema    = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->typeDefs,
            'schemaDirectives' => [
                'objectTypeDirective' => new class ($HumanType) extends SchemaDirectiveVisitor
                {
                    /** @var ObjectType */
                    private $HumanType;

                    public function __construct(?ObjectType &$HumanType)
                    {
                        $this->HumanType = &$HumanType;
                    }

                    public function visitObject(ObjectType $object) : ObjectType
                    {
                        $this->HumanType       = clone $object;
                        $this->HumanType->name = 'Human';
                        return $this->HumanType;
                    }
                },
            ],
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        $Query      = $schema->getQueryType();
        $peopleType = $Query->getField('people')->getType();
        if (! ($peopleType instanceof ListOfType)) {
            throw new Exception('Query.people not a GraphQLList type');
        }

        static::assertEquals($HumanType, $peopleType->ofType);

        $Mutation            = $schema->getMutationType();
        $addPersonResultType = $Mutation->getField('addPerson')->getType();
        static::assertEquals($HumanType, $addPersonResultType);

        $WhateverUnion = $schema->getType('WhateverUnion');

        $found = false;
        foreach ($WhateverUnion->getTypes() as $type) {
            if ($type->name !== 'Human') {
                continue;
            }

            static::assertEquals($HumanType, $type);
            $found = true;
        }

        static::assertTrue($found);

        // Make sure that the Person type was actually removed.
        try {
            $schema->getType('Person');
            static::fail();
        } catch (Throwable $e) {
        }
    }

    /**
     * @see it('can remove enum values')
     */
    public function testCanRemoveEnumValues() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @remove(if: Boolean) on ENUM_VALUE
                type Query {
                    age(unit: AgeUnit): Int
                }
                enum AgeUnit {
                    DOG_YEARS
                    TURTLE_YEARS @remove(if: true)
                    PERSON_YEARS @remove(if: false)
                }
            ',
            'schemaDirectives' => [
                'remove' => new class extends SchemaDirectiveVisitor
                {
                    /**
                     * @param mixed[] $details
                     */
                    public function visitEnumValue(EnumValueDefinition $value, array $details) : ?VisitorOperation
                    {
                        if ($this->args['if']) {
                            return SchemaDirectiveVisitor::removeNode();
                        }

                        return null;
                    }
                },
            ],
        ]);

        $AgeUnit = $schema->getType('AgeUnit');

        static::assertEquals(
            ['DOG_YEARS', 'PERSON_YEARS'],
            array_map(
                static function ($value) {
                    return $value->name;
                },
                $AgeUnit->getValues()
            )
        );
    }

    /**
     * @see it('can swap names of GraphQLNamedType objects')
     */
    public function testCanSwapNamesOfGraphQLNamedTypeObjects() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @rename(to: String) on OBJECT
                type Query {
                    people: [Person]
                }
                type Person @rename(to: "Human") {
                    heightInInches: Int
                }
                scalar Date
                type Human @rename(to: "Person") {
                    born: Date
                }
            ',
            'schemaDirectives' => [
                'rename' => new class extends SchemaDirectiveVisitor
                {
                    public function visitObject(ObjectType $object) : void
                    {
                        $object->name = $this->args['to'];
                    }
                },
            ],
        ]);

        /** @var ObjectType $Human */
        $Human = $schema->getType('Human');
        static::assertEquals('Human', $Human->name);
        static::assertEquals(Type::int(), $Human->getField('heightInInches')->getType());

        /** @var ObjectType $Person */
        $Person = $schema->getType('Person');
        static::assertEquals('Person', $Person->name);
        static::assertEquals($schema->getType('Date'), $Person->getField('born')->getType());

        $Query = $schema->getQueryType();
        /** @var ListOfType $peopleType */
        $peopleType = $Query->getField('people')->getType();
        static::assertEquals($Human, $peopleType->ofType);
    }

    /**
     * @see it('does not enforce query directive locations (issue #680)')
     */
    public function testDoesNotEnforceQueryDirectiveLocations() : void
    {
        $visited = [];
        $schema  = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @hasScope(scope: [String]) on QUERY | FIELD | OBJECT
                type Query @hasScope {
                    oyez: String
                }
            ',
            'schemaDirectives' => [
                'hasScope' => new class ($visited) extends SchemaDirectiveVisitor
                {
                    /** @var ObjectType[] */
                    private $visited;

                    /**
                     * @param ObjectType[] $visited
                     */
                    public function __construct(array &$visited)
                    {
                        $this->visited = &$visited;
                    }

                    public function visitObject(ObjectType $object) : void
                    {
                        TestCase::assertEquals('Query', $object->name);
                        $this->visited[] = $object;
                    }
                },
            ],
        ]);

        static::assertCount(1, $visited);
        foreach ($visited as $object) {
            static::assertEquals($object, $schema->getType('Query'));
        }
    }

    /**
     * @see it('allows multiple directives when first replaces type (issue #851)')
     */
    public function testAllowsMultipleDirectivesWhenFirstReplacesType() : void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                directive @upper on FIELD_DEFINITION
                directive @reverse on FIELD_DEFINITION
                type Query {
                    hello: String @upper @reverse
                }
            ',
            'schemaDirectives' => [
                'upper' => new class extends SchemaDirectiveVisitor
                {
                    /**
                     * @param mixed[] $details
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $details) : FieldDefinition
                    {
                        $resolve = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];

                        $newField            = clone $field;
                        $newField->resolveFn = static function (...$args) use ($resolve) {
                            $result = call_user_func_array($resolve, $args);
                            if (is_string($result)) {
                                return strtoupper($result);
                            }
                            return $result;
                        };

                        return $newField;
                    }
                },
                'reverse' => new class extends SchemaDirectiveVisitor
                {
                    /**
                     * @param mixed[] $details
                     */
                    public function visitFieldDefinition(FieldDefinition $field, array $details) : void
                    {
                        $resolve          = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];
                        $field->resolveFn = static function (...$args) use ($resolve) {
                            $result = call_user_func_array($resolve, $args);
                            if (is_string($result)) {
                                return strrev($result);
                            }

                            return $result;
                        };
                    }
                },
            ],
            'resolvers' => [
                'Query' => [
                    'hello' => static function () {
                        return 'hello world';
                    },
                ],
            ],
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            '
                query {
                    hello
                }
            '
        );

        static::assertEquals(
            ['hello' => 'DLROW OLLEH'],
            $result->data
        );
    }
}
