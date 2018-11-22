<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Language\Parser;
use GraphQLTools\Generate\ExtractExtensionDefinitions;
use PHPUnit\Framework\TestCase;

class ExtensionExtractionTest extends TestCase
{
    /**
     * @see it('extracts extended inputs')
     */
    public function testExtractsExtendedInputs() : void
    {
        $typeDefs = '
            input Input {
                foo: String
            }

            extend input Input {
                bar: String
            }
        ';

        $astDocument  = Parser::parse($typeDefs);
        $extensionAst = ExtractExtensionDefinitions::invoke($astDocument);

        static::assertCount(1, $extensionAst->definitions);
        static::assertEquals('InputObjectTypeExtension', $extensionAst->definitions[0]->kind);
    }

    /**
     * @see it('extracts extended unions')
     */
    public function testExtractsExtendedUnions() : void
    {
        $typeDefs = '
            type Person {
                name: String!
            }
            type Location {
                name: String!
            }
            union Searchable = Person | Location
            type Post {
                name: String!
            }
            extend union Searchable = Post
        ';

        $astDocument  = Parser::parse($typeDefs);
        $extensionAst = ExtractExtensionDefinitions::invoke($astDocument);

        static::assertCount(1, $extensionAst->definitions);
        static::assertEquals('UnionTypeExtension', $extensionAst->definitions[0]->kind);
    }

    /**
     * @see it('extracts extended enums')
     */
    public function testExtractsExtendedEnums() : void
    {
        $typeDefs = '
            enum Color {
                RED
                GREEN
            }
            extend enum Color {
                BLUE
            }
        ';

        $astDocument  = Parser::parse($typeDefs);
        $extensionAst = ExtractExtensionDefinitions::invoke($astDocument);

        static::assertCount(1, $extensionAst->definitions);
        static::assertEquals('EnumTypeExtension', $extensionAst->definitions[0]->kind);
    }
}
