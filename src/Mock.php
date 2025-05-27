<?php

declare(strict_types=1);

namespace GraphQLTools;

use Closure;
use Exception;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQLTools\Generate\BuildSchemaFromTypeDefinitions;
use GraphQLTools\Generate\ForEachField;
use GraphQLTools\Mock\MockList;
use Throwable;

use function array_map;
use function array_merge;
use function assert;
use function count;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function mt_rand;
use function rand;
use function sprintf;

class Mock
{
    /**
     * @param Schema|string|string[]|DocumentNode $schema
     * @param mixed[]                             $mocks
     */
    public static function mockServer(Schema|string|array|DocumentNode $schema, array $mocks, bool $preserveResolvers = false): object
    {
        $mySchema = null;
        if (! $schema instanceof Schema) {
            $mySchema = BuildSchemaFromTypeDefinitions::invoke($schema);
        } else {
            $mySchema = $schema;
        }

        static::addMockFunctionsToSchema([
            'schema' => $mySchema,
            'mocks' => $mocks,
            'preserveResolvers' => $preserveResolvers,
        ]);

        return new class ($mySchema) {
            public function __construct(private Schema $schema)
            {
            }

            /** @param mixed[] $variables */
            public function query(string $query, array $variables = []): ExecutionResult
            {
                return GraphQL::executeQuery($this->schema, $query, [], [], $variables);
            }
        };
    }

    protected static object|null $defaultMocks = null;

    protected static function getDefaultMockMap(): object
    {
        if (static::$defaultMocks === null) {
            static::$defaultMocks = new class {
                /** @var Closure[]  */
                protected array $mocks = [];

                public function __construct()
                {
                    $this->mocks['Int'] = static function () {
                        return rand(-100, 100);
                    };

                    $this->mocks['Float'] = static function () {
                        return rand(-100, 100);
                    };

                    $this->mocks['String'] = static function () {
                        return 'Hello World';
                    };

                    $this->mocks['Boolean'] = static function () {
                        return rand(0, 10) > 5;
                    };

                    $this->mocks['ID'] = static function () {
                        return sprintf(
                            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0x0fff) | 0x4000,
                            mt_rand(0, 0x3fff) | 0x8000,
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                            mt_rand(0, 0xffff),
                        );
                    };
                }

                public function has(string $type): bool
                {
                    return isset($this->mocks[$type]);
                }

                public function __invoke(string $type, mixed ...$args): mixed
                {
                    $mock = $this->mocks[$type];

                    return $mock();
                }
            };
        }

        return static::$defaultMocks;
    }

    /** @param mixed[] $options */
    public static function addMockFunctionsToSchema(array $options): void
    {
        $schema            = $options['schema'] ?? null;
        $mocks             = $options['mocks'] ?? [];
        $preserveResolvers = $options['preserveResolvers'] ?? false;

        if (! $schema) {
            throw new Exception('Must provide schema to mock');
        }

        if (! ($schema instanceof Schema)) {
            throw new Exception('Value at "schema" must be of type Schema');
        }

        if (! is_array($mocks)) {
            throw new Exception('mocks must be an associative array');
        }

        foreach ($mocks as $mockTypeName => $mockFunction) {
            if (! is_callable($mockFunction)) {
                throw new Exception('mockFunctionMap[' . $mockTypeName . '] must be callable');
            }
        }

        $mockType = static function (
            Type $type,
            string|null $typeName = null,
            string|null $fieldName = null,
        ) use (
            &$mockType,
            $mocks,
            $schema,
        ): Closure {
            return static function (
                $root,
                $args,
                $context,
                ResolveInfo $info,
            ) use (
                $type,
                $fieldName,
                &$mockType,
                $mocks,
                $schema,
            ) {
                $fieldType      = Type::getNullableType($type);
                $namedFieldType = Type::getNamedType($fieldType);

                if (
                    $root
                    && (
                        (is_array($root) && isset($root[$fieldName]))
                        || ((is_object($root) && isset($root->{$fieldName})))
                    )
                ) {
                    $result = null;

                    $field = is_array($root) ? $root[$fieldName] : $root->{$fieldName};

                    if (is_callable($field)) {
                        $result = $field($root, $args, $context, $info);

                        if ($result instanceof MockList) {
                            $result = $result->mock(
                                $root,
                                $args,
                                $context,
                                $info,
                                $fieldType,
                                $mockType,
                            );
                        }
                    } else {
                        $result = $field;
                    }

                    if (isset($mocks[$namedFieldType->name])) {
                        $result = static::mergeMocks(
                            static function () use ($mocks, $namedFieldType, $root, $args, $context, $info) {
                                return $mocks[$namedFieldType->name]($root, $args, $context, $info);
                            },
                            $result,
                        );
                    }

                    return $result;
                }

                if ($fieldType instanceof ListOfType || $fieldType instanceof NonNull) {
                    return [
                        $mockType($fieldType->getWrappedType())($root, $args, $context, $info),
                        $mockType($fieldType->getWrappedType())($root, $args, $context, $info),
                    ];
                }

                if (
                    isset($mocks[$fieldType->name]) &&
                    ! ($fieldType instanceof UnionType || $fieldType instanceof InterfaceType)
                ) {
                    return $mocks[$fieldType->name]($root, $args, $context, $info);
                }

                if ($fieldType instanceof ObjectType) {
                    return [];
                }

                if ($fieldType instanceof UnionType || $fieldType instanceof InterfaceType) {
                    if (isset($mocks[$fieldType->name])) {
                        $interfaceMockObj = $mocks[$fieldType->name]($root, $args, $context, $info);
                        if (! $interfaceMockObj || ! isset($interfaceMockObj['__typename'])) {
                            throw new Exception('Please return a __typename in "' . $fieldType->name . '"');
                        }

                        $implementationType = $schema->getType($interfaceMockObj['__typename']);
                    } else {
                        $possibleTypes      = $schema->getPossibleTypes($fieldType);
                        $implementationType = static::getRandomElement($possibleTypes);
                    }

                    assert($implementationType instanceof ObjectType);

                    return array_merge(
                        ['__typename' => $implementationType->name],
                        $mockType($implementationType)($root, $args, $context, $info),
                    );
                }

                if ($fieldType instanceof EnumType) {
                    return static::getRandomElement($fieldType->getValues())->value;
                }

                if (static::getDefaultMockMap()->has($fieldType->name)) {
                    return static::getDefaultMockMap()($fieldType->name, $root, $args, $context, $info);
                }

                return new Exception('No mock defined for type "' . $fieldType->name . '"');
            };
        };

        ForEachField::invoke(
            $schema,
            static function (
                FieldDefinition $field,
                string $typeName,
                string $fieldName,
            ) use (
                $preserveResolvers,
                $schema,
                $mocks,
                $mockType,
            ): void {
                static::assignResolveType($field->getType(), $preserveResolvers);
                $mockResolver = null;

                $isOnQueryType    = $schema->getQueryType() && $schema->getQueryType()->name === $typeName;
                $isOnMutationType = $schema->getMutationType() && $schema->getMutationType()->name === $typeName;

                if ($isOnQueryType || $isOnMutationType) {
                    if (isset($mocks[$typeName])) {
                        $rootMock       = $mocks[$typeName];
                        $rootMockResult = $rootMock(null, [], [], []);

                        if (isset($rootMockResult[$fieldName]) && is_callable($rootMockResult[$fieldName])) {
                            $mockResolver = static function (
                                $root,
                                $args,
                                $context,
                                ResolveInfo $info,
                            ) use (
                                $fieldName,
                                $rootMock,
                                $mockType,
                                $field,
                                $typeName,
                            ) {
                                $updatedRoot             = $root ?? [];
                                $updatedRoot[$fieldName] = $rootMock(
                                    $root,
                                    $args,
                                    $context,
                                    $info,
                                )[$fieldName];

                                return $mockType(
                                    $field->getType(),
                                    $typeName,
                                    $fieldName,
                                )($updatedRoot, $args, $context, $info);
                            };
                        }
                    }
                }

                if (! $mockResolver) {
                    $mockResolver = $mockType($field->getType(), $typeName, $fieldName);
                }

                if (! $preserveResolvers || ! $field->resolveFn) {
                    $field->resolveFn = $mockResolver;
                } else {
                    $oldResolver      = $field->resolveFn;
                    $field->resolveFn = static function (
                        $rootObject,
                        $args,
                        $context,
                        $info,
                    ) use (
                        $mockResolver,
                        $oldResolver,
                    ) {
                        $resolvedValue = null;
                        try {
                            $resolvedValue = $oldResolver($rootObject, $args, $context, $info);
                            $mockedValue   = $mockResolver($rootObject, $args, $context, $info);
                        } catch (Throwable $e) {
                            if (! $resolvedValue) {
                                throw $e;
                            }

                            return $resolvedValue;
                        }

                        if (is_array($resolvedValue) && is_array($mockedValue)) {
                            return static::copyOwnProps($resolvedValue, $mockedValue);
                        }

                        return $resolvedValue ?? $mockedValue;
                    };
                }
            },
        );
    }

    /** @param mixed[] $ary */
    private static function getRandomElement(array $ary): mixed
    {
        $sample = rand(0, count($ary) - 1);

        return $ary[$sample];
    }

    /**
     * @param mixed[] $target
     * @param mixed[] $source
     *
     * @return mixed[]
     */
    private static function copyOwnProps(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (! isset($target[$key])) {
                $target[$key] = $value;
            } elseif (is_array($target[$key]) && is_array($value)) {
                $target[$key] = static::copyOwnProps($target[$key], $value);
            }
        }

        return $target;
    }

    protected static function mergeMocks(callable $genericMockFunctions, mixed $customMock): mixed
    {
        if (Utils::isNumericArray($customMock)) {
            return array_map(static function ($el) use ($genericMockFunctions) {
                return static::mergeMocks($genericMockFunctions, $el);
            }, $customMock);
        }

        if (is_array($customMock)) {
            return static::copyOwnProps($customMock, $genericMockFunctions());
        }

        return $customMock;
    }

    protected static function getResolveType(NamedType $namedFieldType): callable|null
    {
        if ($namedFieldType instanceof InterfaceType || $namedFieldType instanceof  UnionType) {
            return $namedFieldType->config['resolveType'] ?? null;
        }

        return null;
    }

    protected static function assignResolveType(Type $type, bool $preserveResolvers): void
    {
        $fieldType      = Type::getNullableType($type);
        $namedFieldType = Type::getNamedType($fieldType);

        $oldResolveType = static::getResolveType($namedFieldType);
        if ($preserveResolvers && $oldResolveType) {
            return;
        }

        if (! ($namedFieldType instanceof UnionType) && ! ($namedFieldType instanceof InterfaceType)) {
            return;
        }

        $namedFieldType->config['resolveType'] = static function ($data, $context, ResolveInfo $info) {
            return $info->schema->getType($data['__typename']);
        };
    }
}
