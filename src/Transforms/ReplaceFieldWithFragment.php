<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Error\Error;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use GraphQL\Utils\TypeInfo;
use GraphQLTools\Utils;

use function array_keys;
use function array_merge;
use function array_reduce;
use function array_values;
use function assert;
use function count;
use function preg_match;
use function trim;

class ReplaceFieldWithFragment implements Transform
{
    /** @var mixed[] */
    private array $mapping;

    /** @param mixed[] $fragments */
    public function __construct(private Schema $targetSchema, array $fragments)
    {
        $this->mapping = [];
        foreach ($fragments as ['field' => $field, 'fragment' => $fragment]) {
            $parsedFragment                   = static::parseFragmentToInlineFragment($fragment);
            $actualTypeName                   = $parsedFragment->typeCondition->name->value;
            $this->mapping[$actualTypeName] ??= [];

            if (isset($this->mapping[$actualTypeName][$field])) {
                $this->mapping[$actualTypeName][$field][] = $parsedFragment;
            } else {
                $this->mapping[$actualTypeName][$field] = [$parsedFragment];
            }
        }
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest): array
    {
        $document = static::replaceFieldsWithFragments(
            $this->targetSchema,
            $originalRequest['document'],
            $this->mapping,
        );

        $originalRequest['document'] = $document;

        return $originalRequest;
    }

    /** @param mixed[] $mapping */
    private static function replaceFieldsWithFragments(
        Schema $targetSchema,
        DocumentNode $document,
        array $mapping,
    ): DocumentNode {
        $typeInfo = new TypeInfo($targetSchema);

        return Visitor::visit(
            $document,
            Visitor::visitWithTypeInfo(
                $typeInfo,
                [
                    NodeKind::SELECTION_SET => static function (SelectionSetNode $node) use ($typeInfo, $mapping) {
                        $parentType = $typeInfo->getParentType();
                        assert($parentType instanceof Type);

                        if ($parentType) {
                            $parentTypeName = $parentType->name;
                            $selections     = Utils::toArray($node->selections);

                            if (isset($mapping[$parentTypeName])) {
                                foreach ($node->selections as $selection) {
                                    if (! ($selection instanceof FieldNode)) {
                                        continue;
                                    }

                                    $name      = $selection->name->value;
                                    $fragments = $mapping[$parentTypeName][$name] ?? null;
                                    if (! $fragments || count($fragments) <= 0) {
                                        continue;
                                    }

                                    $fragment   = static::concatInlineFragments(
                                        $parentTypeName,
                                        $fragments,
                                    );
                                    $selections = array_merge($selections, [$fragment]);
                                }
                            }

                            if ($selections !== $node->selections) {
                                $node             = clone $node;
                                $node->selections = array_values($selections);

                                return $node;
                            }
                        }

                        return null;
                    },
                ],
            ),
        );
    }

    private static function parseFragmentToInlineFragment(string $definitions): InlineFragmentNode
    {
        if (preg_match('/^fragment/', trim($definitions)) !== 0) {
            $document = Parser::parse($definitions);
            foreach ($document->definitions as $definition) {
                if ($definition instanceof FragmentDefinitionNode) {
                    return new InlineFragmentNode([
                        'typeCondition' => $definition->typeCondition,
                        'selectionSet' => $definition->selectionSet,
                    ]);
                }
            }
        }

        $query = Parser::parse('{' . $definitions . '}')->definitions[0];
        assert($query instanceof OperationDefinitionNode);

        foreach ($query->selectionSet->selections as $selection) {
            if ($selection instanceof InlineFragmentNode) {
                return $selection;
            }
        }

        throw new Error('Could not parse fragment');
    }

    /** @param InlineFragmentNode[] $fragments */
    private static function concatInlineFragments(string $type, array $fragments): InlineFragmentNode
    {
        $fragmentSelections = array_reduce(
            $fragments,
            static function (array $selections, InlineFragmentNode $fragment) {
                return array_merge($selections, Utils::toArray($fragment->selectionSet->selections));
            },
            [],
        );

        $deduplicatedFragmentSelection = static::deduplicateSelection($fragmentSelections);

        return new InlineFragmentNode([
            'typeCondition' => new NamedTypeNode([
                'name' => new NameNode(['value' => $type]),
            ]),
            'selectionSet' => new SelectionSetNode(['selections' => $deduplicatedFragmentSelection]),
        ]);
    }

    /**
     * @param SelectionNode[] $nodes
     *
     * @return SelectionNode[]
     */
    private static function deduplicateSelection(array $nodes): array
    {
        $selectionMap = array_reduce(
            $nodes,
            static function (array $map, SelectionNode $node) {
                if ($node instanceof FieldNode) {
                    if ($node->alias) {
                        if (isset($map[$node->alias->value])) {
                            return $map;
                        }

                        $map[$node->alias->value] = $node;

                        return $map;
                    }

                    if (isset($map[$node->name->value])) {
                        return $map;
                    }

                    $map[$node->name->value] = $node;

                    return $map;
                }

                if ($node instanceof FragmentSpreadNode) {
                    if (isset($map[$node->name->value])) {
                        return $map;
                    }

                    $map[$node->name->value] = $node;

                    return $map;
                }

                if ($node instanceof InlineFragmentNode) {
                    if (! isset($map['__fragment'])) {
                        $map['__fragment'] = $node;

                        return $map;
                    }

                    $fragment = $map['__fragment'];
                    assert($fragment instanceof InlineFragmentNode);

                    $map['__fragment'] = static::concatInlineFragments(
                        $fragment->typeCondition->name->value,
                        [
                            $fragment,
                            $node,
                        ],
                    );
                }

                return $map;
            },
            [],
        );

        return array_reduce(
            array_keys($selectionMap),
            static function (array $selectionList, $node) use ($selectionMap) {
                return array_merge($selectionList, [$selectionMap[$node]]);
            },
            [],
        );
    }
}
