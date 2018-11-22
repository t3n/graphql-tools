<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Language\AST\DefinitionNode;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\EnumTypeExtensionNode;
use GraphQL\Language\AST\InputObjectTypeExtensionNode;
use GraphQL\Language\AST\InterfaceTypeExtensionNode;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\ObjectTypeExtensionNode;
use GraphQL\Language\AST\UnionTypeExtensionNode;
use function array_filter;
use function array_values;
use function iterator_to_array;

class ExtractExtensionDefinitions
{
    public static function invoke(DocumentNode $ast) : DocumentNode
    {
        $definitions   = $ast->definitions instanceof NodeList
            ? iterator_to_array($ast->definitions->getIterator())
            : $ast->definitions;
        $extensionDefs = array_filter($definitions, static function (DefinitionNode $def) : bool {
            return $def instanceof ObjectTypeExtensionNode ||
                $def instanceof InterfaceTypeExtensionNode ||
                $def instanceof InputObjectTypeExtensionNode ||
                $def instanceof UnionTypeExtensionNode ||
                $def instanceof EnumTypeExtensionNode;
        });

        $extensionAst              = clone$ast;
        $extensionAst->definitions = array_values($extensionDefs);
        return $extensionAst;
    }
}
