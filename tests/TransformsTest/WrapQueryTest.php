<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Language\AST\SelectionSetNode;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Transforms\WrapQuery;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strtoupper;
use function substr;

class WrapQueryTest extends TestCase
{
    /** @var mixed[] */
    protected array $data;
    protected Schema $subSchema;
    protected Schema $schema;

    public function setUp(): void
    {
        parent::setUp();

        $this->data      = [
            'u1' => [
                'id' => 'user1',
                'addressStreetAddress' => 'Windy Shore 21 A 7',
                'addressZip' => '12345',
            ],
        ];
        $this->subSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type User {
                    id: ID!
                    addressStreetAddress: String
                    addressZip: String
                }
                type Query {
                    userById(id: ID!): User
                }
            ',
            'resolvers' => [
                'Query' => [
                    'userById' => function ($parent, $args) {
                        $id = $args['id'];

                        return $this->data[$id];
                    },
                ],
            ],
        ]);

        $this->schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type User {
                    id: ID!
                    address: Address
                }
                type Address {
                    streetAddress: String
                    zip: String
                }
                type Query {
                    addressByUser(id: ID!): Address
                }
            ',
            'resolvers' => [
                'Query' => [
                    'addressByUser' => function ($parent, $args, $context, $info) {
                        $id = $args['id'];

                        return GraphQLTools::delegateToSchema([
                            'schema' => $this->subSchema,
                            'operation' => 'query',
                            'fieldName' => 'userById',
                            'args' => ['id' => $id],
                            'context' => $context,
                            'info' => $info,
                            'transforms' => [
                                // Wrap document takes a subtree as an AST node
                                new WrapQuery(
                                    // path at which to apply wrapping and extracting
                                    ['userById'],
                                    static function ($subtree) {
                                        return new SelectionSetNode([
                                            'selections' => array_map(
                                                static function ($selection) {
                                                    // just append fragments, not interesting for this
                                                    // test
                                                    if (
                                                        $selection instanceof InlineFragmentNode
                                                        || $selection instanceof FragmentSpreadNode
                                                    ) {
                                                        return $selection;
                                                    }

                                                    // prepend `address` to name and camelCase
                                                    $oldFieldName = $selection->name->value;

                                                    return new FieldNode([
                                                        'name' => new NameNode([
                                                            'value' =>
                                                                'address'
                                                                . strtoupper($oldFieldName[0])
                                                                . substr($oldFieldName, 1),
                                                        ]),
                                                    ]);
                                                },
                                                $subtree->selections,
                                            ),
                                        ]);
                                    },
                                    static function ($result) {
                                        return [
                                            'streetAddress' => $result['addressStreetAddress'],
                                            'zip' => $result['addressZip'],
                                        ];
                                    },
                                ),
                            ],
                        ]);
                    },
                ],
            ],
        ]);
    }

    /** @see it('wrapping delegation, returning selectionSet') */
    public function testWrappingDelegationReturningSelectionSet(): void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                query {
                    addressByUser(id: "u1") {
                    streetAddress
                    zip
                }
            }
        ',
        );

        static::assertEquals(
            [
                'data' => [
                    'addressByUser' => [
                        'streetAddress' => 'Windy Shore 21 A 7',
                        'zip' => '12345',
                    ],
                ],
            ],
            $result->toArray(),
        );
    }
}
