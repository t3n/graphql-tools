<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Visitor;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Schema;

use function is_array;

class RenameTypes implements Transform
{
    /** @var callable  */
    protected $renamer;
    /** @var mixed[] */
    protected array $reverseMap = [];
    protected bool $renameBuiltins;
    protected bool $renameScalars;

    /** @param mixed[] $options */
    public function __construct(callable $renamer, array $options = [])
    {
        $this->renamer        = $renamer;
        $this->renameBuiltins = $options['renameBuiltins'] ?? false;
        $this->renameScalars  = $options['renameScalars'] ?? true;
    }

    public function transformSchema(Schema $originalSchema): Schema
    {
        return VisitSchema::invoke($originalSchema, [
            VisitSchemaKind::TYPE => function (NamedType $type) {
                if ($type instanceof ScalarType && ! $this->renameBuiltins) {
                    return null;
                }

                if ($type instanceof CustomScalarType && ! $this->renameScalars) {
                    return null;
                }

                $renamer = $this->renamer;
                $newName = $renamer($type->name);
                if ($newName && $newName !== $type->name) {
                    $this->reverseMap[$newName] = $type->name;
                    $newType                    = clone $type;
                    $newType->name              = $newName;

                    return $newType;
                }

                return null;
            },
            VisitSchemaKind::ROOT_OBJECT => static function (NamedType $type) {
                return null;
            },
        ]);
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest): array
    {
        $newDocument = Visitor::visit($originalRequest['document'], [
            NodeKind::NAMED_TYPE => function (NamedTypeNode $node) {
                $name = $node->name->value;
                if (isset($this->reverseMap[$name])) {
                    $node              = clone $node;
                    $node->name        = clone $node->name;
                    $node->name->value = $this->reverseMap[$name];

                    return $node;
                }

                return null;
            },
        ]);

        return [
            'document' => $newDocument,
            'variables' => $originalRequest['variables'] ?? [],
        ];
    }

    public function transformResult(ExecutionResult $result): ExecutionResult
    {
        if ($result->data) {
            $data = $this->renameTypes($result->data, 'data');
            if ($data !== $result->data) {
                $result       = clone $result;
                $result->data = $data;

                return $result;
            }
        }

        return $result;
    }

    private function renameTypes(mixed $value, string|null $name): mixed
    {
        if ($name === '__typename') {
            $renamer = $this->renamer;

            return $renamer($value);
        }

        if ($value && is_array($value)) {
            $newValue       = [];
            $returnNewValue = false;

            foreach ($value as $key => $oldChild) {
                $newChild       = $this->renameTypes($oldChild, (string) $key);
                $newValue[$key] = $newChild;
                // phpcs:ignore
                if ($newChild !== $oldChild) {
                    $returnNewValue = true;
                }
            }

            if ($returnNewValue) {
                return $newValue;
            }
        }

        return $value;
    }
}
