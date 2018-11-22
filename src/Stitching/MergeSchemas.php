<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use Exception;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\SchemaExtender;
use GraphQLTools\Generate\AddResolveFunctionsToSchema;
use GraphQLTools\Generate\ExtractExtensionDefinitions;
use GraphQLTools\MergeDeep;
use GraphQLTools\SchemaDirectiveVisitor;
use GraphQLTools\Utils;
use function array_merge;
use function array_reduce;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function substr;

class MergeSchemas
{
    /**
     * @param mixed[] $options
     */
    public static function invoke(array $options) : Schema
    {
        $schemas                        = $options['schemas'];
        $onTypeConflict                 = $options['onTypeConflict'] ?? null;
        $resolvers                      = $options['resolvers'] ?? null;
        $schemaDirectives               = $options['schemaDirectives'] ?? null;
        $inheritResolversFromInterfaces = $options['inheritResolversFromInterfaces'] ?? null;
        $allSchemas                     = [];
        $typeCandidates                 = [];
        $types                          = [];
        $extensions                     = [];
        $fragments                      = [];

        $resolveType = SchemaRecreation::createResolveType(static function (string $name) use (&$types) {
            if (! isset($types[$name])) {
                throw new Exception('Can\'t find type ' . $name . '.');
            }

            return $types[$name];
        });

        foreach ($schemas as $schema) {
            if ($schema instanceof Schema) {
                $allSchemas[]     = $schema;
                $queryType        = $schema->getQueryType();
                $mutationType     = $schema->getMutationType();
                $subscriptionType = $schema->getSubscriptionType();

                if ($queryType) {
                    static::addTypeCandidate($typeCandidates, 'Query', [
                        'schema' => $schema,
                        'type' => $queryType,
                    ]);
                }
                if ($mutationType) {
                    static::addTypeCandidate($typeCandidates, 'Mutation', [
                        'schema' => $schema,
                        'type' => $mutationType,
                    ]);
                }
                if ($subscriptionType) {
                    static::addTypeCandidate($typeCandidates, 'Subscription', [
                        'schema' => $schema,
                        'type' => $subscriptionType,
                    ]);
                }

                $typeMap = $schema->getTypeMap();
                foreach ($typeMap as $typeName => $type) {
                    if (! ($type instanceof NamedType) ||
                        substr(Type::getNamedType($type)->name, 0, 2) === '__' ||
                        $type === $queryType ||
                        $type === $mutationType ||
                        $type === $subscriptionType
                    ) {
                        continue;
                    }

                    static::addTypeCandidate($typeCandidates, $type->name, [
                        'schema' => $schema,
                        'type' => $type,
                    ]);
                }
            } elseif (is_string($schema) || $schema instanceof DocumentNode) {
                $parsedSchemaDocument = is_string($schema) ? Parser::parse($schema) : $schema;
                foreach ($parsedSchemaDocument->definitions as $def) {
                    $type = TypeFromAST::invoke($def);
                    if (! $type) {
                        continue;
                    }

                    static::addTypeCandidate($typeCandidates, $type->name, ['type' => $type]);
                }

                $extensionsDocument = ExtractExtensionDefinitions::invoke($parsedSchemaDocument);
                if (count($extensionsDocument->definitions) > 0) {
                    $extensions[] = $extensionsDocument;
                }
            } elseif (is_array($schema)) {
                foreach ($schema as $type) {
                    static::addTypeCandidate($typeCandidates, $type->name, ['type' => $type]);
                }
            } else {
                throw new Exception('Invalid schema passed');
            }
        }

        $mergeInfo = static::createMergeInfo($allSchemas, $fragments);

        if (! $resolvers) {
            $resolvers = [];
        } elseif (Utils::isNumericArray($resolvers)) {
            $resolvers = array_reduce(
                $resolvers,
                static function (array $left, $right) {
                    return MergeDeep::invoke($left, $right);
                },
                []
            );
        }

        $generatedResolvers = [];

        foreach ($typeCandidates as $typeName => $candidates) {
            $resultType = static::defaultVisitType($typeName, $candidates);

            if ($resolveType === null) {
                $types[$typeName] = null;
            } else {
                $type          = null;
                $typeResolvers = null;

                if ($resultType instanceof NamedType) {
                    $type = $resultType;
                } elseif (isset($resultType['type'])) {
                    $type          = $resultType['type'];
                    $typeResolvers = $resultType['resolvers'];
                } else {
                    throw new Exception('Invalid visitType result for type ' . $typeName);
                }

                $types[$typeName] = SchemaRecreation::recreateType($type, $resolveType, false);
                if ($typeResolvers) {
                    $generatedResolvers[$typeName] = $typeResolvers;
                }
            }
        }

        $mergedSchema = new Schema([
            'query' => $types['Query'] ?? null,
            'mutation' => $types['Mutation'] ?? null,
            'subscription' => $types['Subscription'] ?? null,
            'types' => array_values($types),
        ]);

        foreach ($extensions as $extension) {
            $mergedSchema = SchemaExtender::extend($mergedSchema, $extension, ['commentDescriptions' => true]);
        }

        foreach ($resolvers as $typeName => $type) {
            if ($type instanceof ScalarType) {
                break;
            }

            foreach ($type as $fieldName => $field) {
                if (! is_array($field) || ! isset($field['fragment'])) {
                    continue;
                }

                $fragments[] = [
                    'field' => $fieldName,
                    'fragment' => $field['fragment'],
                ];
            }
        }

        $mergedSchema = AddResolveFunctionsToSchema::invoke([
            'schema' => $mergedSchema,
            'resolvers' => MergeDeep::invoke($generatedResolvers, $resolvers),
            'inheritResolversFromInterfaces' => $inheritResolversFromInterfaces,
        ]);

        static::forEachField($mergedSchema, static function ($field) use ($mergeInfo) : void {
            if (isset($field->resolveFn)) {
                $fieldResolver    = $field->resolveFn;
                $field->resolveFn = static function ($parent, $args, $context, $info) use ($mergeInfo, $fieldResolver) {
                    $newInfo            = $info;
                    $newInfo->mergeInfo = $mergeInfo;
                    return $fieldResolver($parent, $args, $context, $newInfo);
                };
            }
            // Future?
            if (! isset($field->subscribeFn)) {
                return;
            }

            $fieldResolver      = $field->subscribeFn;
            $field->subscribeFn = static function ($parent, $args, $context, $info) use ($mergeInfo, $fieldResolver) {
                $newInfo            = $info;
                $newInfo->mergeInfo = $mergeInfo;
                return $fieldResolver($parent, $args, $context, $newInfo);
            };
        });

        if ($schemaDirectives) {
            SchemaDirectiveVisitor::visitSchemaDirectives($mergedSchema, $schemaDirectives);
        }

        return $mergedSchema;
    }

    /**
     * @param Schema[] $allSchemas
     * @param mixed[]  $fragments
     */
    private static function createMergeInfo(array $allSchemas, array &$fragments) : object
    {
        return new class ($fragments) {
            /** @var mixed[] */
            public $fragments;

            /**
             * @param mixed[] $fragments
             */
            public function __construct(array &$fragments)
            {
                $this->fragments = &$fragments;
            }

            /**
             * @param mixed[] $options
             *
             * @return mixed
             */
            public function delegateToSchema(array $options)
            {
                return DelegateToSchema::invoke(array_merge(
                    $options,
                    ['transforms' => $options['transforms'] ?? null ]
                ));
            }
        };
    }

    protected static function createDelegatingResolver(?Schema $schema, string $operation, string $fieldName) : callable
    {
        return static function ($root, $args, $context, $info) use ($schema, $operation, $fieldName) {
            return $info->mergeInfo->delegateToSchema([
                'schema' => $schema,
                'operation' => $operation,
                'fieldName' => $fieldName,
                'args' => $args,
                'context' => $context,
                'info' => $info,
            ]);
        };
    }

    private static function forEachField(Schema $schema, callable $fn) : void
    {
        $typeMap = $schema->getTypeMap();
        foreach ($typeMap as $typeName => $type) {
            if (substr(Type::getNamedType($type)->name, 0, 2) === '__' || ! ($type instanceof ObjectType)) {
                continue;
            }

            $fields = $type->getFields();
            foreach ($fields as $fieldName => $field) {
                $fn($field, $typeName, $fieldName);
            }
        }
    }

    /**
     * @param mixed[] $typeCandidates
     * @param mixed[] $typeCandidate
     */
    private static function addTypeCandidate(array &$typeCandidates, string $name, array $typeCandidate) : void
    {
        if (! isset($typeCandidates[$name])) {
            $typeCandidates[$name] = [];
        }

        $typeCandidates[$name][] = $typeCandidate;
    }

    /**
     * @param mixed[] $candidates
     *
     * @return mixed
     */
    private static function defaultVisitType(string $name, array $candidates, ?callable $candidateSelector = null)
    {
        if (! $candidateSelector) {
            $candidateSelector = static function (array $cands) {
                return $cands[count($cands) - 1];
            };
        }

        $resolveType = SchemaRecreation::createResolveType(static function ($_, $type) {
            return $type;
        });

        if ($name === 'Query' || $name === 'Mutation' || $name === 'Subscription') {
            $fields        = [];
            $operationName = null;

            switch ($name) {
                case 'Query':
                    $operationName = 'query';
                    break;
                case 'Mutation':
                    $operationName = 'mutation';
                    break;
                case 'Subscription':
                    $operationName = 'subscription';
                    break;
                default:
                    break;
            }

            $resolvers   = [];
            $resolverKey = $operationName === 'subscription' ? 'subscribe' : 'resolve';

            foreach ($candidates as $candidate) {
                /** @var ObjectType $candidateType */
                $candidateType   = $candidate['type'];
                $schema          = $candidate['schema'] ?? null;
                $candidateFields = $candidateType->getFields();
                $fields          = array_merge($fields, $candidateFields);

                foreach ($candidateFields as $fieldName => $_) {
                    $resolvers[$fieldName] = [
                        $resolverKey => static::createDelegatingResolver($schema, $operationName, $fieldName),
                    ];
                }
            }

            $type = new ObjectType([
                'name' => $name,
                'fields' => SchemaRecreation::fieldMapToFieldConfigMap($fields, $resolveType, false),
            ]);

            return [
                'type' => $type,
                'resolvers' => $resolvers,
            ];
        }

        $candidate = $candidateSelector($candidates);
        return $candidate['type'];
    }
}
