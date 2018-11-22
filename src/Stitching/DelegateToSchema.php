<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use Exception;
use GraphQL\GraphQL;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentDefinitionNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Language\AST\VariableDefinitionNode;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Validator\DocumentValidator;
use GraphQLTools\Transforms\AddArgumentsAsVariables;
use GraphQLTools\Transforms\AddTypenameToAbstract;
use GraphQLTools\Transforms\CheckResultAndHandleErrors;
use GraphQLTools\Transforms\ConvertEnumResponse;
use GraphQLTools\Transforms\ExpandAbstractTypes;
use GraphQLTools\Transforms\FilterToSchema;
use GraphQLTools\Transforms\ReplaceFieldWithFragment;
use GraphQLTools\Transforms\Transforms;
use GraphQLTools\Utils;
use function array_merge;
use function array_values;
use function count;

class DelegateToSchema
{
    /**
     * @param mixed[] $options
     *
     * @return mixed
     */
    public static function invoke(array $options)
    {
        /** @var ResolveInfo $info */
        $info      = $options['info'];
        $args      = $options['args'] ?? [];
        $operation = $options['operation'] ?? $info->operation->operation;

        $rawDocument = static::createDocument(
            $options['fieldName'],
            $operation,
            $info->fieldNodes,
            array_values($info->fragments),
            $info->operation->variableDefinitions,
            $info->operation->name
        );

        $rawRequest = [
            'document' => $rawDocument,
            'variables' => $info->variableValues,
        ];

        $transforms = array_merge(
            $options['transforms'] ?? [],
            [new ExpandAbstractTypes($info->schema, $options['schema'])]
        );

        if (isset($info->mergeInfo) && count($info->mergeInfo->fragments) > 0) {
            $transforms[] = new ReplaceFieldWithFragment($options['schema'], $info->mergeInfo->fragments);
        }

        $transforms = array_merge(
            $transforms,
            [
                new AddArgumentsAsVariables($options['schema'], $args),
                new FilterToSchema($options['schema']),
                new AddTypenameToAbstract($options['schema']),
                new CheckResultAndHandleErrors($info, $options['fieldName']),
            ]
        );

        if ($info->returnType instanceof EnumType) {
            $transforms = array_merge(
                $transforms,
                [new ConvertEnumResponse($info->returnType)]
            );
        }

        $processedRequest = Transforms::applyRequestTransforms($rawRequest, $transforms);

        if (! isset($options['skipValidation']) || ! $options['skipValidation']) {
            $errors = DocumentValidator::validate($options['schema'], $processedRequest['document']);
            if (count($errors) > 0) {
                throw $errors[0];
            }
        }

        if ($operation === 'query' || $operation === 'mutation') {
            return Transforms::applyResultTransform(
                GraphQL::executeQuery(
                    $options['schema'],
                    $processedRequest['document'],
                    $info->rootValue,
                    $options['context'] ?? null,
                    $processedRequest['variables']
                ),
                $transforms
            );
        }

        throw new Exception('Subscription missing');
        /*
        if ($operation !== 'subscription') {
            return;
        }
        */
    }

    /**
     * @param SelectionNode[]|NodeList          $originalSelections
     * @param FragmentDefinitionNode[]          $fragments
     * @param VariableDefinitionNode[]|NodeList $variables
     */
    private static function createDocument(
        string $targetField,
        string $targetOperation,
        $originalSelections,
        array $fragments,
        $variables,
        ?NameNode $operationName
    ) : DocumentNode {
        $selections = [];
        $args       = [];

        /** @var FieldNode $field */
        foreach ($originalSelections as $field) {
            $fieldSelection = $field->selectionSet ? $field->selectionSet->selections : [];
            $selections     = array_merge($selections, Utils::toArray($fieldSelection));
            $args           = array_merge($args, $field->arguments ? Utils::toArray($field->arguments) : []);
        }

        $selectionSet = null;
        if (count($selections) > 0) {
            $selectionSet = new SelectionSetNode(['selections' => $selections]);
        }

        $rootField = new FieldNode([
            'alias' => null,
            'arguments' => $args,
            'selectionSet' => $selectionSet,
            'name' => new NameNode(['value' => $targetField]),
        ]);

        $rootSelectionSet = new SelectionSetNode([
            'selections' => [$rootField],
        ]);

        $operationDefinition = new OperationDefinitionNode([
            'operation' => $targetOperation,
            'variableDefinitions' => $variables,
            'selectionSet' => $rootSelectionSet,
            'name' => $operationName,
        ]);

        return new DocumentNode([
            'definitions' => array_merge([$operationDefinition], $fragments),
        ]);
    }
}
