<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Error\Error;
use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\AbstractType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\TypeInfo;
use GraphQLTools\Utils;

use function array_filter;
use function array_map;
use function array_merge;
use function count;
use function in_array;
use function sprintf;

class ExpandAbstractTypes implements Transform
{
    /** @var array|string[][]  */
    private array $mapping;
    /** @var array|string[]  */
    private array $reverseMapping;

    public function __construct(Schema $transformedSchema, private Schema $targetSchema)
    {
        $this->mapping        = static::extractPossibleTypes($transformedSchema, $targetSchema);
        $this->reverseMapping = static::flipMapping($this->mapping);
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest): array
    {
        $document = static::expandAbstractTypes(
            $this->targetSchema,
            $this->mapping,
            $this->reverseMapping,
            $originalRequest['document'],
        );

        $originalRequest['document'] = $document;

        return $originalRequest;
    }

    /** @return string[][] */
    private static function extractPossibleTypes(Schema $transformedSchema, Schema $targetSchema): array
    {
        $typeMap = $transformedSchema->getTypeMap();
        $mapping = [];
        foreach ($typeMap as $typeName => $type) {
            if (! ($type instanceof AbstractType)) {
                continue;
            }

            try {
                $targetType = $targetSchema->getType($typeName);
            } catch (Error) {
                $targetType = null;
            }

            if ($targetType instanceof AbstractType) {
                continue;
            }

            $implementations    = $targetType ? $transformedSchema->getPossibleTypes($type) : [];
            $mapping[$typeName] = array_map(
                static function (ObjectType $impl): string {
                    return $impl->name;
                },
                array_filter(
                    $implementations,
                    static function (ObjectType $impl) use ($targetSchema): bool {
                        return $targetSchema->getType($impl) !== null;
                    },
                ),
            );
        }

        return $mapping;
    }

    /**
     * @param string[][] $mapping
     *
     * @return string[]
     */
    private static function flipMapping(array $mapping): array
    {
        $result = [];
        foreach ($mapping as $typeName => $toTypeNames) {
            foreach ($toTypeNames as $toTypeName) {
                if (! isset($result[$toTypeName])) {
                    $result[$toTypeName] = [];
                }

                $result[$toTypeName][] = $typeName;
            }
        }

        return $result;
    }

    /**
     * @param mixed[] $mapping
     * @param mixed[] $reverseMapping
     */
    private static function expandAbstractTypes(
        Schema $targetSchema,
        array $mapping,
        array $reverseMapping,
        DocumentNode $document,
    ): DocumentNode {
        $operations = array_filter($document->definitions, static function (DefinitionNode $def) {
            return $def instanceof OperationDefinitionNode;
        });
        /** @var FragmentDefinitionNode[] $fragments */
        $fragments = array_filter($document->definitions, static function (DefinitionNode $def) {
            return $def instanceof FragmentDefinitionNode;
        });

        $existingFragmentNames = array_map(static function (FragmentDefinitionNode $fragment) {
            return $fragment->name->value;
        }, $fragments);
        $fragmentCounter       = 0;
        $generateFragmentName  = static function (string $typeName) use (&$existingFragmentNames, &$fragmentCounter) {
            $fragmentName = null;
            do {
                $fragmentName = sprintf('_%s_Fragment%s', $typeName, $fragmentCounter);
                $fragmentCounter++;
            } while (in_array($fragmentName, $existingFragmentNames));

            return $fragmentName;
        };

        $newFragments         = [];
        $fragmentReplacements = [];

        foreach ($fragments as $fragment) {
            $newFragments[] = $fragment;
            $possibleTypes  = $mapping[$fragment->typeCondition->name->value] ?? null;
            if (! $possibleTypes) {
                continue;
            }

            $fragmentReplacements[$fragment->name->value] = [];
            foreach ($possibleTypes as $possibleTypeName) {
                $name                    = $generateFragmentName($possibleTypeName);
                $existingFragmentNames[] = $name;
                $newFragment             = new FragmentDefinitionNode([
                    'name' => new NameNode(['value' => $name]),
                    'typeCondition' => new NamedTypeNode([
                        'name' => new NameNode(['value' => $possibleTypeName]),
                    ]),
                    'selectionSet' => $fragment->selectionSet,
                ]);
                $newFragments[]          = $newFragment;

                $fragmentReplacements[$fragment->name->value][] = [
                    'fragmentName' => $name,
                    'typeName' => $possibleTypeName,
                ];
            }
        }

        $newDocument              = clone$document;
        $newDocument->definitions = array_merge($operations, $newFragments);

        $typeInfo = new TypeInfo($targetSchema);

        return Visitor::visit(
            $newDocument,
            Visitor::visitWithTypeInfo(
                $typeInfo,
                [
                    NodeKind::SELECTION_SET => static function (SelectionSetNode $node) use (
                        $typeInfo,
                        $mapping,
                        $targetSchema,
                        $fragmentReplacements,
                        $reverseMapping,
                    ): SelectionSetNode|null {
                        $newSelections = $node->selections;
                        $parentType    = Type::getNamedType($typeInfo->getParentType());

                        foreach ($node->selections as $selection) {
                            if ($selection instanceof InlineFragmentNode) {
                                $possibleTypes = $mapping[$selection->typeCondition->name->value] ?? null;
                                if ($possibleTypes) {
                                    foreach ($possibleTypes as $possibleType) {
                                        if (
                                            ! Utils::implementsAbstractType(
                                                $targetSchema,
                                                $parentType,
                                                $targetSchema->getType($possibleType),
                                            )
                                        ) {
                                            continue;
                                        }

                                        $newSelections[] = new InlineFragmentNode([
                                            'typeCondition' => new NamedTypeNode([
                                                'name' => new NameNode(['value' => $possibleType]),
                                            ]),
                                            'selectionSet' => $selection->selectionSet,
                                        ]);
                                    }
                                }
                            } elseif ($selection instanceof FragmentSpreadNode) {
                                $fragmentName = $selection->name->value;
                                $replacements = $fragmentReplacements[$fragmentName] ?? null;
                                if ($replacements) {
                                    foreach ($replacements as $replacement) {
                                        $typeName = $replacement['typeName'];
                                        if (
                                            ! Utils::implementsAbstractType(
                                                $targetSchema,
                                                $parentType,
                                                $targetSchema->getType($typeName),
                                            )
                                        ) {
                                            continue;
                                        }

                                        $newSelections[] = new FragmentSpreadNode([
                                            'name' => new NameNode([
                                                'value' => $replacement['fragmentName'],
                                            ]),
                                        ]);
                                    }
                                }
                            }
                        }

                        if ($parentType && isset($reverseMapping[$parentType->name])) {
                            $newSelections[] = new FieldNode([
                                'name' => new NameNode(['value' => '__typename']),
                            ]);
                        }

                        if (count($newSelections) !== count($node->selections)) {
                            $node             = clone$node;
                            $node->selections = $newSelections;

                            return $node;
                        }

                        return null;
                    },

                ],
            ),
        );
    }
}
