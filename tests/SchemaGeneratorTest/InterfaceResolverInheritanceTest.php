<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\SchemaGeneratorTest;

use GraphQL\GraphQL;
use GraphQLTools\GraphQLTools;
use PHPUnit\Framework\TestCase;

class InterfaceResolverInheritanceTest extends TestCase
{
    /**
     * @see it('copies resolvers from the interfaces')
     */
    public function testCopiesResolversFromTheInterfaces() : void
    {
        $testSchemaWithInterfaceResolvers = '
            interface Node {
                id: ID!
            }
            type User implements Node {
                id: ID!
                name: String!
            }
            type Query {
                user: User!
            }
            schema {
                query: Query
            }
        ';

        $user = [
            'id' => 1,
            'name' => 'Ada',
            'type' => 'User',
        ];

        $resolvers = [
            'Node' => [
                '__resolveType' => static function ($root) {
                    return $root['type'];
                },
                'id' => static function ($root) {
                    return 'Node:' . $root['id'];
                },
            ],
            'User' => [
                'name' => static function ($root) {
                    return 'User:' . $root['name'];
                },
            ],
            'Query' => [
                'user' => static function () use ($user) {
                    return $user;
                },
            ],
        ];

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $testSchemaWithInterfaceResolvers,
            'resolvers' => $resolvers,
            'inheritResolversFromInterfaces' => true,
            'resolverValidationOptions' => [
                'requireResolversForAllFields' => true,
                'requireResolversForResolveType' => true,
            ],
        ]);

        $query    = '{ user { id name } }';
        $response = GraphQL::executeQuery($schema, $query);

        static::assertEquals([
            'data' => [
                'user' => [
                    'id' => 'Node:1',
                    'name' => 'User:Ada',
                ],
            ],
        ], $response->toArray());
    }

    /**
     * @see it('respects interface order and existing resolvers')
     */
    public function testRespectsInterfaceOrderAndExistingResolvers() : void
    {
        $testSchemaWithInterfaceResolvers = '
            interface Node {
                id: ID!
            }
            interface Person {
                id: ID!
                name: String!
            }
            type Replicant implements Node, Person {
                id: ID!
                name: String!
            }
            type Cyborg implements Person, Node {
                id: ID!
                name: String!
            }
            type Query {
                cyborg: Cyborg!
                replicant: Replicant!
            }
            schema {
                query: Query
            }
        ';

        $cyborg    = ['id' => 1, 'name' => 'Alex Murphy', 'type' => 'Cyborg'];
        $replicant = ['id' => 2, 'name' => 'Rachael Tyrell', 'type' => 'Replicant'];
        $resolvers = [
            'Node' => [
                '__resolveType' => static function ($root) {
                    return $root['type'];
                },
                'id' => static function ($root) {
                    return 'Node:' . $root['id'];
                },
            ],
            'Person' => [
                '__resolveType' => static function ($root) {
                    return $root['type'];
                },
                'id' => static function ($root) {
                    return 'Person:' . $root['id'];
                },
                'name' => static function ($root) {
                    return 'Person:' . $root['name'];
                },
            ],
            'Query' => [
                'cyborg' => static function () use ($cyborg) {
                    return $cyborg;
                },
                'replicant' => static function () use ($replicant) {
                    return $replicant;
                },
            ],
        ];

        $schema = GraphQLTools::makeExecutableSchema([
            'parseOptions' => ['allowLegacySDLImplementsInterfaces' => true],
            'typeDefs' => $testSchemaWithInterfaceResolvers,
            'resolvers' => $resolvers,
            'inheritResolversFromInterfaces' => true,
            'resolverValidationOptions' => [
                'requireResolversForAllFields' => true,
                'requireResolversForResolveType' => true,
            ],
        ]);

        $query    = '{ cyborg { id name } replicant { id name }}';
        $response = GraphQL::executeQuery($schema, $query);

        static::assertEquals([
            'data' => [
                'cyborg' => [
                    'id' => 'Node:1',
                    'name' => 'Person:Alex Murphy',
                ],
                'replicant' => [
                    'id' => 'Person:2',
                    'name' => 'Person:Rachael Tyrell',
                ],
            ],
        ], $response->toArray());
    }
}
