<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Mock;
use PHPUnit\Framework\TestCase;

class FragmentsAreNotDuplicatedTest extends TestCase
{
    /** @var string */
    protected $rawSchema;
    /** @var string */
    protected $query;
    /** @var mixed[] */
    protected $variables;

    public function setUp() : void
    {
        parent::setUp();

        $this->rawSchema = '
            type Post {
                id: ID!
                title: String!
                owner: User!
            }
            type User {
                id: ID!
                email: String
            }
            type Query {
                post(id: ID!): Post
            }
        ';

        $this->query = '
            query getPostById($id: ID!) {
                post(id: $id) {
                    ...Post
                    owner {
                        ...PostOwner
                        email
                    }
                }
            }
            
            fragment Post on Post {
                id
                title
                owner {
                    ...PostOwner
                }
            }
            
            fragment PostOwner on User {
                id
            }
        ';

        $this->variables = ['id' => 123];
    }

    private static function assertNoDuplicateFragmentErrors(ExecutionResult $result) : void
    {
        // Run assertion against each array element for better test failure output.

        foreach ($result->errors as $error) {
            static::assertEquals('', $error->getMessage());
        }
    }

    /**
     * @see it('should not throw `There can be only one fragment named "FieldName"` errors')
     */
    public function testShouldNotThrowThereCanBeOnlyOneFragmentNamedFieldNameErrors() : void
    {
        $originalSchema = GraphQLTools::makeExecutableSchema([
            'typeDefs' => $this->rawSchema,
        ]);

        Mock::addMockFunctionsToSchema(['schema' => $originalSchema]);

        $originalResult = GraphQL::executeQuery($originalSchema, $this->query, [], [], $this->variables);
        static::assertNoDuplicateFragmentErrors($originalResult);

        $transformedSchema = GraphQLTools::transformSchema($originalSchema, []);
        $transformedResult = GraphQL::executeQuery($transformedSchema, $this->query, null, null, $this->variables);

        static::assertNoDuplicateFragmentErrors($transformedResult);
        static::assertTrue(true);
    }
}
