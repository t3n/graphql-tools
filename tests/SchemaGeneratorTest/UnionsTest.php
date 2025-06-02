<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\SchemaGeneratorTest;

use GraphQL\GraphQL;
use GraphQLTools\GraphQLTools;
use PHPUnit\Framework\TestCase;
use Throwable;

class UnionsTest extends TestCase
{
    protected string $testSchemaWithUnions;
    /** @var mixed[] */
    protected array $post;
    /** @var mixed[] */
    protected array $page;
    /** @var mixed[] */
    protected array $queryResolver;
    protected string $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testSchemaWithUnions = '
            type Post {
                title: String!
            }
            type Page {
                title: String!
            }
            union Displayable = Page | Post
            type Query {
                page: Page!
                post: Post!
                displayable: [Displayable!]!
            }
            schema {
                query: Query
            }
        ';

        $this->post          = ['title' => 'I am a post', 'type' => 'Post'];
        $this->page          = ['title' => 'I am a page', 'type' => 'Page'];
        $this->queryResolver = [
            'page' => function () {
                return $this->page;
            },
            'post' => function () {
                return $this->post;
            },
            'displayable' => function () {
                return [$this->post, $this->page];
            },
        ];
        $this->query         = '
            query {
                post { title }
                page { title }
                displayable {
                    ... on Post { title }
                    ... on Page { title }
                }
            }
        ';
    }

    /** @see it('throws if there is no union resolveType resolver') */
    public function testThrowsIfThereIsNoUnionResolveTypeResolver(): void
    {
        $resolvers = [
            'Query' => $this->queryResolver,
        ];

        try {
            GraphQLTools::makeExecutableSchema([
                'typeDefs' => $this->testSchemaWithUnions,
                'resolvers' => $resolvers,
                'resolverValidationOptions' => ['requireResolversForResolveType' => true],
            ]);
            static::fail();
        } catch (Throwable $error) {
            static::assertEquals('Type "Displayable" is missing a "resolveType" resolver', $error->getMessage());
        }
    }

    /** @see it('does not throw if there is a resolveType resolver') */
    public function testDoesNotThrowIfThereIsAResolveTypeResolver(): void
    {
        $resolvers = [
            'Query' => $this->queryResolver,
            'Displayable' => [
                '__resolveType' => static function ($root) {
                    return $root['type'];
                },
            ],
        ];

        $schema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithUnions,
            'resolvers' => $resolvers,
            'resolverValidationOptions' => ['requireResolversForResolveType' => true],
        ]);

        $response = GraphQL::executeQuery($schema, $this->query);
        static::assertEmpty($response->errors);
    }

    /** @see it('does not warn if requireResolversForResolveType is disabled') */
    public function testDoesNotWarnIfRequireResolversForResolveTypeIsDisabled(): void
    {
        $resolvers = [
            'Query' => $this->queryResolver,
        ];

        GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->testSchemaWithUnions,
            'resolvers' => $resolvers,
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);

        static::assertTrue(true);
    }
}
