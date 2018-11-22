<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\VariableDefinitionNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;
use GraphQL\Type\Schema;
use GraphQLTools\Utils;
use function array_filter;
use function array_map;
use function array_merge;
use function array_pop;
use function array_search;
use function array_values;
use function count;
use function in_array;

class FilterToSchema implements Transform
{
    /** @var Schema */
    private $targetSchema;

    public function __construct(Schema $targetSchema)
    {
        $this->targetSchema = $targetSchema;
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest) : array
    {
        $document                    = static::filterDocumentToSchema(
            $this->targetSchema,
            $originalRequest['document']
        );
        $originalRequest['document'] = $document;
        return $originalRequest;
    }

    private static function filterDocumentToSchema(Schema $targetSchema, DocumentNode $document) : DocumentNode
    {
        /** @var OperationDefinitionNode[] $operations */
        $operations = array_filter(Utils::toArray($document->definitions), static function (DefinitionNode $def) {
            return $def instanceof OperationDefinitionNode;
        });
        $fragments  = array_filter(Utils::toArray($document->definitions), static function (DefinitionNode $def) {
            return $def instanceof FragmentDefinitionNode;
        });

        $usedFragments = [];
        $newOperations = [];
        $newFragments  = [];

        /** @var FragmentDefinitionNode[] $validFragments */
        $validFragments = array_filter(
            $fragments,
            static function (FragmentDefinitionNode $fragment) use ($targetSchema) {
                $typeName = $fragment->typeCondition->name->value;
                return $targetSchema->getType($typeName) !== null;
            }
        );

        $validFragmentsWithType = [];
        foreach ($validFragments as $fragment) {
            $typeName                                       = $fragment->typeCondition->name->value;
            $type                                           = $targetSchema->getType($typeName);
            $validFragmentsWithType[$fragment->name->value] = $type;
        }

        $fragmentSet = [];
        foreach ($operations as $operation) {
            $type = null;
            if ($operation->operation === 'subscription') {
                $type = $targetSchema->getSubscriptionType();
            } elseif ($operation->operation === 'mutation') {
                $type = $targetSchema->getMutationType();
            } else {
                $type = $targetSchema->getQueryType();
            }

            [
                'selectionSet' => $selectionSet,
                'usedFragments' => $operationUsedFragments,
                'usedVariables' => $operationUsedVariables,
            ] = static::filterSelectionSet(
                $targetSchema,
                $type,
                $validFragmentsWithType,
                $operation->selectionSet
            );

            $usedFragments = static::union([ $usedFragments, $operationUsedFragments ]);

            [
                'usedVariables' => $collectedUsedVariables,
                'newFragments' => $collectedNewFragments,
                'fragmentSet' => $collectedFragmentSet,
            ] = static::collectFragmentVariables(
                $targetSchema,
                $fragmentSet,
                $validFragments,
                $validFragmentsWithType,
                $usedFragments
            );

            $fullUsedVariables = static::union([$operationUsedVariables, $collectedUsedVariables]);
            $newFragments      = $collectedNewFragments;
            $fragmentSet       = $collectedFragmentSet;

            $variableDefinitions = array_filter(
                Utils::toArray($operation->variableDefinitions),
                static function (VariableDefinitionNode $variable) use ($fullUsedVariables) : bool {
                    return in_array($variable->variable->name->value, $fullUsedVariables);
                }
            );

            $newOperations[] = new OperationDefinitionNode([
                'operation' => $operation->operation,
                'name' => $operation->name,
                'directives' => $operation->directives,
                'variableDefinitions' => array_values($variableDefinitions),
                'selectionSet' => $selectionSet,
            ]);
        }

        return new DocumentNode([
            'definitions' => array_merge($newOperations, $newFragments),
        ]);
    }

    /**
     * @param bool[]                   $fragmentSet
     * @param FragmentDefinitionNode[] $validFragments
     * @param FragmentDefinitionNode[] $validFragmentsWithType
     * @param FragmentDefinitionNode[] $usedFragments
     *
     * @return mixed[]
     */
    private static function collectFragmentVariables(
        Schema $targetSchema,
        array $fragmentSet,
        array $validFragments,
        array $validFragmentsWithType,
        array $usedFragments
    ) : array {
        $usedVariables = [];
        $newFragments  = [];

        while (count($usedFragments) !== 0) {
            $nextFragmentName = array_pop($usedFragments);
            $fragments        = array_filter(
                $validFragments,
                static function (FragmentDefinitionNode $fr) use ($nextFragmentName) {
                    return $fr->name->value === $nextFragmentName;
                }
            );

            /** @var FragmentDefinitionNode $fragment */
            $fragment = array_values($fragments)[0] ?? null;

            if (! $fragment) {
                continue;
            }

            $name     = $nextFragmentName;
            $typeName = $fragment->typeCondition->name->value;
            $type     = $targetSchema->getType($typeName);

            [
                'selectionSet' => $selectionSet,
                'usedFragments' => $fragmentUsedFragments,
                'usedVariables'=> $fragmentUsedVariables,
            ] = static::filterSelectionSet(
                $targetSchema,
                $type,
                $validFragmentsWithType,
                $fragment->selectionSet
            );

            $usedFragments = static::union([$usedFragments, $fragmentUsedFragments]);
            $usedVariables = static::union([$usedVariables, $fragmentUsedVariables]);

            if (isset($fragmentSet[$name])) {
                continue;
            }

            $fragmentSet[$name] = true;
            $newFragments[]     = new FragmentDefinitionNode([
                'name' => new NameNode(['value' => $name]),
                'typeCondition' => $fragment->typeCondition,
                'selectionSet' => $selectionSet,
            ]);
        }

        return [
            'usedVariables' => $usedVariables,
            'newFragments' => $newFragments,
            'fragmentSet' => $fragmentSet,
        ];
    }

    /**
     * @param FragmentDefinitionNode[] $validFragments
     *
     * @return mixed[]
     */
    private static function filterSelectionSet(
        Schema $schema,
        Type $type,
        array $validFragments,
        SelectionSetNode $selectionSet
    ) : array {
        $usedFragments = [];
        $usedVariables = [];
        $typeStack     = [$type];

        $filteredSelectionSet = Visitor::visit(
            $selectionSet,
            [
                NodeKind::FIELD => [
                    'enter' => static function (FieldNode $node) use (&$typeStack) {
                        $parentType = static::resolveType($typeStack[count($typeStack) - 1]);

                        if ($parentType instanceof ObjectType || $parentType instanceof InterfaceType) {
                            $fields = $parentType->getFields();
                            $field  = $node->name->value === '__typename'
                                ? Introspection::typeNameMetaFieldDef()
                                : ($fields[$node->name->value] ?? null);

                            if (! $field) {
                                return Visitor::removeNode();
                            } else {
                                $typeStack[] = $field->getType();
                            }

                            $argNames = array_map(static function ($arg) {
                                return $arg->name;
                            }, $field->args ? Utils::toArray($field->args) : []);

                            if ($node->arguments) {
                                $args = array_filter(
                                    Utils::toArray($node->arguments),
                                    static function (ArgumentNode $arg) use ($argNames) {
                                        return in_array($arg->name->value, $argNames);
                                    }
                                );

                                if (count($args) !== count($node->arguments)) {
                                    $node            = clone$node;
                                    $node->arguments = $args;
                                    return $node;
                                }
                            }
                        } elseif ($parentType instanceof UnionType && $node->name->value === '__typename') {
                            $typeStack[] = Introspection::typeNameMetaFieldDef()->getType();
                        }

                        return null;
                    },
                    'leave' => static function (FieldNode $node) use (&$typeStack, &$usedVariables) {
                        $currentType  = array_pop($typeStack);
                        $resolvedType = static::resolveType($currentType);

                        if ($resolvedType instanceof ObjectType ||
                            $resolvedType instanceof InterfaceType
                        ) {
                            $selections = $node->selectionSet ? $node->selectionSet->selections : null;
                            if (! $selections || count($selections) === 0) {
                                Visitor::visit($node, [
                                    NodeKind::VARIABLE => static function (
                                        VariableNode $variableNode
                                    ) use (&$usedVariables) : void {
                                        $index = array_search($variableNode->name->value, $usedVariables);
                                        if ($index === false) {
                                            return;
                                        }

                                        unset($usedVariables[$index]);
                                        $usedVariables = array_values($usedVariables);
                                    },
                                ]);
                                return Visitor::removeNode();
                            }
                        }

                        return null;
                    },
                ],
                NodeKind::FRAGMENT_SPREAD => static function (
                    FragmentSpreadNode $node
                ) use (
                    &$typeStack,
                    $validFragments,
                    $schema,
                    &$usedFragments
                ) {
                    if (isset($validFragments[$node->name->value])) {
                        /** @var Type $parentType */
                        $parentType = static::resolveType($typeStack[count($typeStack) - 1]);
                        $innerType  = $validFragments[$node->name->value];

                        if (! Utils::implementsAbstractType($schema, $parentType, $innerType)) {
                            return Visitor::removeNode();
                        }

                        $usedFragments[] = $node->name->value;
                        return null;
                    }

                    return Visitor::removeNode();
                },
                NodeKind::INLINE_FRAGMENT => [
                    'enter' => static function (InlineFragmentNode $node) use ($schema, &$typeStack) : void {
                        if (! $node->typeCondition) {
                            return;
                        }

                        $innerType = $schema->getType($node->typeCondition->name->value);
                        /** @var Type $parentType */
                        $parentType = static::resolveType($typeStack[count($typeStack) - 1]);
                        if (Utils::implementsAbstractType($schema, $parentType, $innerType)) {
                            $typeStack[] = $innerType;
                        } else {
                            Visitor::removeNode();
                        }
                    },
                    'leave' => static function () use (&$typeStack) : void {
                        array_pop($typeStack);
                    },
                ],
                NodeKind::VARIABLE => static function (VariableNode $node) use (&$usedVariables) : void {
                    $usedVariables[] = $node->name->value;
                },
            ]
        );

        return [
            'selectionSet' => $filteredSelectionSet,
            'usedFragments' => $usedFragments,
            'usedVariables' => $usedVariables,
        ];
    }

    private static function resolveType(Type $type) : NamedType
    {
        if ($type instanceof NonNull) {
            return $type->getWrappedType(true);
        }

        if ($type instanceof ListOfType) {
            return $type->getWrappedType(true);
        }

        /** @var NamedType $type */
        return $type;
    }

    /**
     * @param string[][] $arrays
     *
     * @return string[]
     */
    private static function union(array $arrays) : array
    {
        $cache  = [];
        $result = [];
        foreach ($arrays as $array) {
            foreach ($array as $item) {
                if (isset($cache[$item])) {
                    continue;
                }

                $cache[$item] = true;
                $result[]     = $item;
            }
        }
        return $result;
    }
}
