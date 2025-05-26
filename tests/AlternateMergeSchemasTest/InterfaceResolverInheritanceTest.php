<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\AlternateMergeSchemasTest;

use GraphQL\GraphQL;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Tests\TestingSchemas;
use PHPUnit\Framework\TestCase;

class InterfaceResolverInheritanceTest extends TestCase
{
    private string $testSchemaWithInterfaceResolvers;
    /** @var mixed[] */
    private array $user;
    /** @var mixed[] */
    private array $resolvers;

    public function setUp(): void
    {
        parent::setUp();

        $this->testSchemaWithInterfaceResolvers = '
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

        $this->user      = ['_id' => 1, 'name' => 'Ada', 'type' => 'User'];
        $this->resolvers = [
            'Node' => [
                '__resolveType' => static function ($args) {
                    return $args['type'];
                },
                'id' => static function ($args) {
                    $id = $args['_id'];

                    return 'Node:' . $id;
                },
            ],
            'User' => [
                'name' => static function ($root) {
                    $name = $root['name'];

                    return 'User:' . $name;
                },
            ],
            'Query' => [
                'user' => function () {
                    return $this->user;
                },
            ],
        ];
    }

    /** @see it('copies resolvers from interface') */
    public function testCopiesResolversFromInterface(): void
    {
        $mergedSchema = GraphQLTools::mergeSchemas([
            'schemas' => [
                // pull in an executable schema just so mergeSchema doesn't complain
                // about not finding default types (e.g. ID)
                TestingSchemas::propertySchema(),
                $this->testSchemaWithInterfaceResolvers,
            ],
            'resolvers' => $this->resolvers,
            'inheritResolversFromInterfaces' => true,
        ]);

        $query    = '{ user { id name } }';
        $response = GraphQL::executeQuery($mergedSchema, $query);

        static::assertEquals(
            [
                'data' => [
                    'user' => [
                        'id' => 'Node:1',
                        'name' => 'User:Ada',
                    ],
                ],
            ],
            $response->toArray(),
        );
    }

    /** @see it('does not copy resolvers from interface when flag is false') */
    public function testDoesNotCopyResolversFromInterfaceWhenFlagIsFalse(): void
    {
        $mergedSchema = GraphQLTools::mergeSchemas([
            'schemas' => [
                // pull in an executable schema just so mergeSchema doesn't complain
                // about not finding default types (e.g. ID)
                TestingSchemas::propertySchema(),
                $this->testSchemaWithInterfaceResolvers,
            ],
            'resolvers' => $this->resolvers,
            'inheritResolversFromInterfaces' => false,
        ]);

        $query    = '{ user { id name } }';
        $response = GraphQL::executeQuery($mergedSchema, $query);
        static::assertCount(1, $response->errors);
        static::assertEquals('Cannot return null for non-nullable field User.id.', $response->errors[0]->getMessage());
        static::assertEquals(['user', 'id'], $response->errors[0]->getPath());
    }

    /** @see it('does not copy resolvers from interface when flag is not provided') */
    public function testDoesNotCopyResolversFromInterfaceWhenFlagIsNotProvided(): void
    {
        $mergedSchema = GraphQLTools::mergeSchemas([
            'schemas' => [
                // pull in an executable schema just so mergeSchema doesn't complain
                // about not finding default types (e.g. ID)
                TestingSchemas::propertySchema(),
                $this->testSchemaWithInterfaceResolvers,
            ],
            'resolvers' => $this->resolvers,
        ]);

        $query    = '{ user { id name } }';
        $response = GraphQL::executeQuery($mergedSchema, $query);
        static::assertCount(1, $response->errors);
        static::assertEquals('Cannot return null for non-nullable field User.id.', $response->errors[0]->getMessage());
        static::assertEquals(['user', 'id'], $response->errors[0]->getPath());
    }
}
