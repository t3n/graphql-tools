<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;

use function count;
use function gettype;
use function is_array;
use function is_string;
use function property_exists;

class BuildSchemaFromTypeDefinitions
{
    protected static function isDocumentNode(mixed $typeDefinitions): bool
    {
        return $typeDefinitions instanceof Node && property_exists($typeDefinitions, 'kind');
    }

    /**
     * @param string|string[]|DocumentNode $typeDefinitions
     * @param mixed[]|null                 $parseOptions
     */
    public static function invoke(mixed $typeDefinitions, array|null $parseOptions = null): Schema
    {
        $myDefinitions = $typeDefinitions;
        $astDocument   = null;

        if (static::isDocumentNode($typeDefinitions)) {
            $astDocument = $typeDefinitions;
        } elseif (! is_string($myDefinitions)) {
            if (! is_array($myDefinitions)) {
                $type = gettype($myDefinitions);

                throw new SchemaError('typeDefs must be a string, array or schema AST, got ' . $type);
            }

            $myDefinitions = ConcatenateTypeDefs::invoke($myDefinitions);
        }

        if (is_string($myDefinitions)) {
            $astDocument = Parser::parse($myDefinitions, $parseOptions ?: []);
        }

        $backcompatOptions = ['commentDescriptions' => true];
        $schema            = BuildSchema::buildAST($astDocument, null, $backcompatOptions);

        $extensionsAst = ExtractExtensionDefinitions::invoke($astDocument);
        if (count($extensionsAst->definitions) > 0) {
            $schema = SchemaExtender::extend($schema, $extensionsAst, $backcompatOptions);
        }

        return $schema;
    }
}
