<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\NameNode;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Transforms\ExtractField;
use GraphQLTools\Transforms\WrapQuery;
use PHPUnit\Framework\TestCase;
use function array_merge;

class TreeOperationsTest extends TestCase
{
    /** @var mixed[] */
    protected $data;
    /** @var Schema */
    protected $subSchema;
    /** @var Schema */
    protected $schema;

    public function setUp() : void
    {
        parent::setUp();
        $this->data = [
            'u1' => [
                'id' => 'u1',
                'username' => 'alice',
                'address' => [
                    'streetAddress' => 'Windy Shore 21 A 7',
                    'zip' => '12345',
                ],
            ],
            'u2' => [
                'id' => 'u2',
                'username' => 'bob',
                'address' => [
                    'streetAddress' => 'Snowy Mountain 5 B 77',
                    'zip' => '54321',
                ],
            ],
        ];

        $this->subSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type User {
                    id: ID!
                    username: String
                    address: Address
                }
                type Address {
                    streetAddress: String
                    zip: String
                }
                input UserInput {
                    id: ID!
                    username: String
                }
                input AddressInput {
                    id: ID!
                    streetAddress: String
                    zip: String
                }
                type Query {
                    userById(id: ID!): User
                }
                type Mutation {
                    setUser(input: UserInput!): User
                    setAddress(input: AddressInput!): Address
                }
            ',
            'resolvers' => [
                'Query' => [
                    'userById' => function ($parent, $args) {
                        return $this->data[$args['id']];
                    },
                ],
                'Mutation' => [
                    'setUser' => function ($parent, $args) {
                        $input = $args['input'];
                        if (isset($this->data[$input['id']])) {
                            return array_merge(
                                $this->data[$input['id']],
                                $input
                            );
                        }

                        return null;
                    },
                    'setAddress' => function ($parent, $args) {
                        $input = $args['input'];
                        if (isset($this->data[$input['id']])) {
                            return array_merge(
                                $this->data[$input['id']]['address'],
                                $input
                            );
                        }

                        return null;
                    },
                ],
            ],
        ]);

        $this->schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type User {
                    id: ID!
                    username: String
                    address: Address
                }
                type Address {
                    streetAddress: String
                    zip: String
                }
                input UserInput {
                    id: ID!
                    username: String
                    streetAddress: String
                    zip: String
                }
                type Query {
                    addressByUser(id: ID!): Address
                }
                type Mutation {
                    setUserAndAddress(input: UserInput!): User
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
                                        // we create a wrapping AST Field
                                        return new FieldNode([
                                            // that field is `address`
                                            'name' => new NameNode(['value' => 'address']),
                                            // Inside the field selection
                                            'selectionSet' => $subtree,
                                        ]);
                                    },
                                    // how to process the data result at path
                                    static function ($result) {
                                        return $result ? $result['address'] : null;
                                    }
                                ),
                            ],
                        ]);
                    },
                ],
                'Mutation' => [
                    'setUserAndAddress' => function ($parent, $args, $context, $info) {
                        $input         = $args['input'];
                        $addressResult = GraphQLTools::delegateToSchema([
                            'schema' => $this->subSchema,
                            'operation' => 'mutation',
                            'fieldName' => 'setAddress',
                            'args' => [
                                'input' => [
                                    'id' => $input['id'],
                                    'streetAddress' => $input['streetAddress'],
                                    'zip' => $input['zip'],
                                ],
                            ],
                            'context' => $context,
                            'info' => $info,
                            'transforms' => [
                                // ExtractField takes a path from which to extract the query
                                // for delegation and path to which to move it
                                new ExtractField([
                                    'from' => ['setAddress', 'address'],
                                    'to' => ['setAddress'],
                                ]),
                            ],
                        ]);

                        $userResult = GraphQLTools::delegateToSchema([
                            'schema' => $this->subSchema,
                            'operation' => 'mutation',
                            'fieldName' => 'setUser',
                            'args' => [
                                'input' => [
                                    'id' => $input['id'],
                                    'username' => $input['username'],
                                ],
                            ],
                            'context' => $context,
                            'info' => $info,
                        ]);

                        return array_merge(
                            $userResult,
                            ['address' => $addressResult]
                        );
                    },
                ],
            ],
        ]);
    }

    /**
     * @see it('wrapping delegation')
     */
    public function testWrappingDelegation() : void
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
            '
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
            $result->toArray()
        );
    }

    /**
     * @see it('extracting delegation')
     */
    public function testExtractingDelegation() : void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                mutation($input: UserInput!) {
                    setUserAndAddress(input: $input) {
                        username
                        address {
                            zip
                            streetAddress
                        }
                    }
                }
                # fragment UserFragment on User {
                #   address {
                #     zip
                #     ...AddressFragment
                #   }
                # }
                #
                # fragment AddressFragment on Address {
                #   streetAddress
                # }
            ',
            [],
            [],
            [
                'input' => [
                    'id' => 'u2',
                    'username' => 'new-username',
                    'streetAddress' => 'New Address 555',
                    'zip' => '22222',
                ],
            ]
        );

        static::assertEquals(
            [
                'data' => [
                    'setUserAndAddress' => [
                        'username' => 'new-username',
                        'address' => [
                            'streetAddress' => 'New Address 555',
                            'zip' => '22222',
                        ],
                    ],
                ],
            ],
            $result->toArray()
        );
    }
}
