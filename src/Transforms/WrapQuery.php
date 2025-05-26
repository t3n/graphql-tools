<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\Visitor;

use function array_pop;
use function array_shift;
use function count;
use function is_array;
use function json_encode;

class WrapQuery implements Transform
{
    /** @var callable  */
    private $wrapper;
    /** @var callable  */
    private $extractor;

    /** @param string[] $path */
    public function __construct(private array $path, callable $wrapper, callable $extractor)
    {
        $this->wrapper   = $wrapper;
        $this->extractor = $extractor;
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest): array
    {
        $document    = $originalRequest['document'];
        $fieldPath   = [];
        $ourPath     = json_encode($this->path);
        $newDocument = Visitor::visit(
            $document,
            [
                NodeKind::FIELD => [
                    'enter' => function (FieldNode $node) use ($ourPath, &$fieldPath) {
                        $fieldPath[] = $node->name->value;
                        if ($ourPath === json_encode($fieldPath)) {
                            $wrapper    = $this->wrapper;
                            $wrapResult = $wrapper($node->selectionSet);

                            // Selection can be either a single selection or a selection set.
                            // If it's just one selection, let's wrap it in a selection set.
                            // Otherwise, keep it as is.
                            $selectionSet = $wrapResult instanceof SelectionSetNode
                                || (is_array($wrapResult) && $wrapResult['kind'] === NodeKind::SELECTION_SET)
                                ? $wrapResult
                                : new SelectionSetNode([
                                    'selections' => [$wrapResult],
                                ]);

                            $node               = clone$node;
                            $node->selectionSet = $selectionSet;

                            return $node;
                        }

                        return null;
                    },
                    'leave' => static function (FieldNode $node) use (&$fieldPath): void {
                        array_pop($fieldPath);
                    },
                ],
            ],
        );

        $originalRequest['document'] = $newDocument;

        return $originalRequest;
    }

    public function transformResult(ExecutionResult $originalResult): ExecutionResult
    {
        $data = $originalResult->data;
        if ($data) {
            $path = $this->path;
            while (count($path) > 1) {
                $next = array_shift($path);
                if (! isset($data[$next])) {
                    continue;
                }

                $data = $data[$next];
            }

            $extractor      = $this->extractor;
            $data[$path[0]] = $extractor($data[$path[0]]);
        }

        $result       = clone$originalResult;
        $result->data = $data;

        return $result;
    }
}
