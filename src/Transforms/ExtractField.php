<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Visitor;
use GraphQL\Language\VisitorOperation;
use function array_pop;
use function json_encode;

class ExtractField implements Transform
{
    /** @var string[]  */
    private $from;
    /** @var string[] */
    private $to;

    /**
     * @param string[][] $options
     */
    public function __construct(array $options)
    {
        $this->from = $options['from'];
        $this->to   = $options['to'];
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest) : array
    {
        $fromSelection = null;
        $ourPathFrom   = json_encode($this->from);
        $ourPathTo     = json_encode($this->to);
        $fieldPath     = [];

        Visitor::visit(
            $originalRequest['document'],
            [
                NodeKind::FIELD => [
                    'enter' => static function (FieldNode $node) use (&$fieldPath, $ourPathFrom, &$fromSelection) {
                        $fieldPath[] = $node->name->value;
                        if ($ourPathFrom === json_encode($fieldPath)) {
                            $fromSelection = $node->selectionSet;

                            $o          = new VisitorOperation();
                            $o->doBreak = true;
                            return $o;
                        }

                        return null;
                    },
                    'leave' => static function (FieldNode $node) use (&$fieldPath) : void {
                        array_pop($fieldPath);
                    },
                ],
            ]
        );

        $fieldPath   = [];
        $newDocument = Visitor::visit(
            $originalRequest['document'],
            [
                NodeKind::FIELD => [
                    'enter' => static function (FieldNode $node) use (&$fieldPath, $ourPathTo, $fromSelection) {
                        $fieldPath[] = $node->name->value;

                        if ($ourPathTo === json_encode($fieldPath) && isset($fromSelection)) {
                            $node               = clone$node;
                            $node->selectionSet = $fromSelection;
                            return $node;
                        }
                        return null;
                    },
                    'leave' => static function (FieldNode $node) use (&$fieldPath) : void {
                        array_pop($fieldPath);
                    },
                ],
            ]
        );

        $originalRequest['document'] = $newDocument;
        return $originalRequest;
    }
}
