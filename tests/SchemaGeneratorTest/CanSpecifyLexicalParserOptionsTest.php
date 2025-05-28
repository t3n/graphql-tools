<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\SchemaGeneratorTest;

use GraphQLTools\GraphQLTools;
use PHPUnit\Framework\TestCase;

class CanSpecifyLexicalParserOptionsTest extends TestCase
{
    /** @see it("can specify 'noLocation' option") */
    public function testCanSpecifyNoLocationOption(): void
    {
        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type RootQuery {
                    test: String
                }
                schema {
                    query: RootQuery
                }
            ',
            'resolvers' => [],
            'parseOptions' => ['noLocation' => true],
        ]);

        static::assertNull($schema->astNode->loc);
    }

    /** @see it("can specify 'experimentalFragmentVariables' option") */
    public function testCanSpecifyExperimentalFragmentVariablesOption(): void
    {
        $this->markTestSkipped('Currently broken in webonyx/graphql-php');

        $typeDefs = '
            type Hello {
                world(phrase: String): String
            }
            fragment hello($phrase: String = "world") on Hello {
                world(phrase: $phrase)
            }
            type RootQuery {
                hello: Hello
            }
            schema {
                query: RootQuery
            }
        ';

        $resolvers = [
            'RootQuery' => [
                'hello' => static function () {
                    return [
                        'world' => static function ($phrase) {
                            return 'hello ' . $phrase;
                        },
                    ];
                },
            ],
        ];

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $typeDefs,
            'resolvers' => $resolvers,
            'parseOptions' => ['experimentalFragmentVariables' => true],
        ]);

        static::assertTrue(true);
    }
}
