<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQL\Utils\TypeInfo;
use GraphQLTools\Utils;

use function array_filter;
use function array_merge;
use function count;

class AddTypenameToAbstract implements Transform
{
    public function __construct(private Schema $targetSchema)
    {
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest): array
    {
        $document = $this->addTypenameToAbstract($this->targetSchema, $originalRequest['document']);

        return array_merge(
            $originalRequest,
            ['document' => $document],
        );
    }

    private function addTypenameToAbstract(Schema $targetSchema, DocumentNode $document): DocumentNode
    {
        $typeInfo = new TypeInfo($targetSchema);

        return Visitor::visit(
            $document,
            Visitor::visitWithTypeInfo(
                $typeInfo,
                [
                    NodeKind::SELECTION_SET => static function (SelectionSetNode $node) use ($typeInfo) {
                        $parentType    = $typeInfo->getParentType();
                        $selections    = Utils::toArray($node->selections);
                        $newSelections = $selections;

                        $typenameFound = count(
                            array_filter(
                                $newSelections,
                                static function (SelectionNode $selectionNode) {
                                    return $selectionNode instanceof FieldNode
                                        && $selectionNode->name->value === '__typename';
                                },
                            ),
                        ) > 0;
                        if (
                            $parentType
                            && ($parentType instanceof InterfaceType || $parentType instanceof UnionType)
                            && ! $typenameFound
                        ) {
                            $newSelections[] = new FieldNode([
                                'name' => new NameNode(['value' => '__typename']),
                            ]);
                        }

                        if ($newSelections !== $selections) {
                            $transformedNode             = clone$node;
                            $transformedNode->selections = new NodeList($newSelections);

                            return $transformedNode;
                        }

                        return null;
                    },
                ],
            ),
        );
    }
}
