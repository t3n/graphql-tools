<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Error\Error;
use GraphQL\Language\AST\ArgumentNode;
use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\VariableDefinitionNode;
use GraphQL\Language\AST\VariableNode;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Schema;
use GraphQLTools\Utils;

use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function in_array;
use function sprintf;

class AddArgumentsAsVariables implements Transform
{
    /** @param mixed[] $args */
    public function __construct(private Schema $schema, private array $args)
    {
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest): array
    {
        ['document' => $document, 'newVariables' => $newVariables] = static::addVariablesToRootField(
            $this->schema,
            $originalRequest['document'],
            $this->args,
        );

        $variables = array_merge($originalRequest['variables'], $newVariables);

        return [
            'document' => $document,
            'variables' => $variables,
        ];
    }

    /**
     * @param mixed[] $args
     *
     * @return mixed[]
     */
    private static function addVariablesToRootField(Schema $targetSchema, DocumentNode $document, array $args): array
    {
        $operations = array_filter(Utils::toArray($document->definitions), static function (DefinitionNode $def) {
            return $def instanceof OperationDefinitionNode;
        });
        $fragments  = array_filter(Utils::toArray($document->definitions), static function (DefinitionNode $def) {
            return $def instanceof FragmentDefinitionNode;
        });

        $variablesNames = [];

        $newOperations = array_map(
            static function (OperationDefinitionNode $operation) use ($targetSchema, $args, &$variablesNames) {
                $existingVariables = array_map(static function (VariableDefinitionNode $variableDefinition) {
                    return $variableDefinition->variable->name->value;
                }, Utils::toArray($operation->variableDefinitions));

                $variableCounter = 0;
                $variables       = [];

                $generateVariableName = static function (string $argName) use (
                    &$variableCounter,
                    $existingVariables,
                ): string {
                    do {
                        $varName = sprintf('_v%s_%s', $variableCounter, $argName);
                        $variableCounter++;
                    } while (in_array($varName, $existingVariables));

                    return $varName;
                };

                if ($operation->operation === 'subscription') {
                    $type = $targetSchema->getSubscriptionType();
                } elseif ($operation->operation === 'mutation') {
                    $type = $targetSchema->getMutationType();
                } else {
                    $type = $targetSchema->getQueryType();
                }

                /** @var SelectionNode[] $newSelectionSet */
                $newSelectionSet = [];

                foreach ($operation->selectionSet->selections as $selection) {
                    if ($selection instanceof FieldNode) {
                        $newArgs = [];
                        foreach ($selection->arguments as $argument) {
                            $newArgs[$argument->name->value] = $argument;
                        }

                        $name  = $selection->name->value;
                        $field = $type->getField($name);
                        foreach ($field->args as $argument) {
                            if (! isset($args[$argument->name])) {
                                continue;
                            }

                            $variableName                    = $generateVariableName($argument->name);
                            $variablesNames[$argument->name] = $variableName;
                            $newArgs[$argument->name]        = new ArgumentNode([
                                'name' => new NameNode([
                                    'value' => $argument->name,
                                ]),
                                'value' => new VariableNode([
                                    'name' => new NameNode(['value' => $variableName]),
                                ]),
                            ]);
                            $existingVariables[]             = $variableName;
                            $variables[$variableName]        = new VariableDefinitionNode([
                                'variable' => new VariableNode([
                                    'name' => new NameNode(['value' => $variableName]),
                                ]),
                                'type' => static::typeToAst($argument->getType()),
                            ]);
                        }

                        $selection            = clone$selection;
                        $selection->arguments = new NodeList(array_values($newArgs));
                        $newSelectionSet[]    = $selection;
                    } else {
                        $newSelectionSet[] = $selection;
                    }
                }

                $operation                      = clone$operation;
                $operation->variableDefinitions = new NodeList(array_merge(
                    Utils::toArray($operation->variableDefinitions),
                    array_values($variables),
                ));
                $operation->selectionSet        = new SelectionSetNode(['selections' => new NodeList($newSelectionSet)]);

                return $operation;
            },
            $operations,
        );

        $newVariables = [];
        foreach (array_keys($variablesNames) as $name) {
            $newVariables[$variablesNames[$name]] = $args[$name];
        }

        $document              = clone$document;
        $document->definitions = new NodeList(array_merge($newOperations, $fragments));

        return [
            'document' => $document,
            'newVariables' => $newVariables,
        ];
    }

    private static function typeToAst(InputType $type): TypeNode
    {
        if ($type instanceof NonNull) {
            $innerType = static::typeToAst($type->getWrappedType());
            if ($innerType instanceof ListTypeNode || $innerType instanceof NamedTypeNode) {
                return new NonNullTypeNode(['type' => $innerType]);
            }

            throw new Error('Incorrect inner non-null type');
        }

        if ($type instanceof ListTypeNode) {
            return new ListTypeNode([
                'type' => static::typeToAst($type->type),
            ]);
        }

        return new NamedTypeNode([
            'name' => new NameNode([
                'value' => (string) $type,
            ]),
        ]);
    }
}
