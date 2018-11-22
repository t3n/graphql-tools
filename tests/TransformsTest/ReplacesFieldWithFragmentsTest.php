<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Transforms\ReplaceFieldWithFragment;
use PHPUnit\Framework\TestCase;

/**
 * @see describe('replaces field with fragments')
 */
class ReplacesFieldWithFragmentsTest extends TestCase
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
                'name' => 'joh',
                'surname' => 'gats',
            ],
        ];

        $this->subSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type User {
                    id: ID!
                    name: String!
                    surname: String!
                }
                type Query {
                    userById(id: ID!): User
                }
            ',
            'resolvers' => [
                'Query' => [
                    'userById' => function ($parent, $args) {
                        return $this->data[$args['id']];
                    },
                ],
            ],
        ]);

        $this->schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => '
                type User {
                    id: ID!
                    name: String!
                    surname: String!
                    fullname: String!
                }
                type Query {
                    userById(id: ID!): User
                }
            ',
            'resolvers' => [
                'Query' => [
                    'userById' => function ($parent, $args, $context, $info) {
                        $id = $args['id'];
                        return GraphQLTools::delegateToSchema([
                            'schema' => $this->subSchema,
                            'operation' => 'query',
                            'fieldName' => 'userById',
                            'args' => ['id' => $id],
                            'context' => $context,
                            'info' => $info,
                            'transforms' => [new ReplaceFieldWithFragment(
                                $this->subSchema,
                                [
                                    [
                                        'field' => 'fullname',
                                        'fragment' => 'fragment UserName on User { name }',
                                    ],
                                    [
                                        'field' => 'fullname',
                                        'fragment' => 'fragment UserSurname on User { surname }',
                                    ],
                                ]
                            ),
                            ],
                        ]);
                    },
                ],
                'User' => [
                    'fullname' => static function ($parent, $args, $context, $info) {
                        return $parent['name'] . ' ' . $parent['surname'];
                    },
                ],
            ],
        ]);
    }

    /**
     * @see it('should work')
     */
    public function testShouldWork() : void
    {
        $result = GraphQL::executeQuery(
            $this->schema,
            '
                query {
                    userById(id: "u1") {
                        id
                        fullname
                    }
                }
            '
        );

        static::assertEquals(
            [
                'data' => [
                    'userById' => [
                        'id' => 'u1',
                        'fullname' => 'joh gats',
                    ],
                ],
            ],
            $result->toArray()
        );
    }
}
