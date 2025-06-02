<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\SchemaGeneratorTest;

use GraphQL\GraphQL;
use GraphQLTools\GraphQLTools;
use PHPUnit\Framework\TestCase;
use Throwable;

class InterfacesTest extends TestCase
{
    protected string $testSchemaWithInterfaces;
    /** @var mixed[] */
    protected array $user;
    /** @var mixed[] */
    protected array $queryResolver;
    protected string $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSchemaWithInterfaces = '
            interface Node {
                id: ID!
            }
            type User implements Node {
                id: ID!
                name: String!
            }
            type Query {
                node: Node!
                user: User!
            }
            schema {
                query: Query
            }
        ';

        $this->user = [
            'id' => 1,
            'type' => 'User',
            'name' => 'Kim',
        ];

        $this->queryResolver = [
            'node' => function () {
                return $this->user;
            },
            'user' => function () {
                return $this->user;
            },
        ];

        $this->query = '
            query {
                node { id __typename }
                user { id name }
            }
        ';
    }

    /** @see it('throws if there is no interface resolveType resolver') */
    public function testThrowsIfThereIsNoInterfaceResolveTypeResolver(): void
    {
        $resolvers = [
            'Query' => $this->queryResolver,
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $this->testSchemaWithInterfaces,
                'resolvers' => $resolvers,
                'resolverValidationOptions' => ['requireResolversForResolveType' => true],
            ]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('Type "Node" is missing a "resolveType" resolver', $error->getMessage());
        }
    }

    /** @see it('does not throw if there is an interface resolveType resolver') */
    public function testDoesNotThrowIfThereIsAnInterfaceResolveTypeResolver(): void
    {
        $resolvers = [
            'Query' => $this->queryResolver,
            'Node' => [
                '__resolveType' => static function ($args) {
                    return $args['type'];
                },
            ],
        ];

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithInterfaces,
            'resolvers' => $resolvers,
            'resolverValidationOptions' => ['requireResolversForResolveType' => true],
        ]);

        $response = GraphQL::executeQuery($schema, $this->query);
        static::assertEmpty($response->errors);
    }

    /** @see it('does not warn if requireResolversForResolveType is disabled and there are missing resolvers') */
    public function testDoesNotWarnIfRequireResolversForResolveTypeIsDisabledAndThereAreMissingResolvers(): void
    {
        $resolvers = [
            'Query' => $this->queryResolver,
        ];

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithInterfaces,
            'resolvers' => $resolvers,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);
        static::assertTrue(true);
    }
}
