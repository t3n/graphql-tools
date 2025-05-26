<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\TransformsTest;

use GraphQL\Language\Parser;
use GraphQL\Language\Printer;
use GraphQLTools\Tests\TestingSchemas;
use GraphQLTools\Transforms\FilterToSchema;
use PHPUnit\Framework\TestCase;

/** @see describe('filter to schema') */
class FilterToSchemaTest extends TestCase
{
    protected FilterToSchema $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new FilterToSchema(TestingSchemas::bookingSchema());
    }

    /** @see it('should remove empty selection sets on objects') */
    public function testShouldRemoveEmptySelectionSetsOnObjects(): void
    {
        $query = Parser::parse('
            query customerQuery($id: ID!) {
                customerById(id: $id) {
                    id
                    name
                    address {
                        planet
                    }
                }
            }
        ');

        $filteredQuery = $this->filter->transformRequest([
            'document' => $query,
            'variables' => ['id' => 'c1'],
        ]);

        $expected = Parser::parse('
            query customerQuery($id: ID!) {
                customerById(id: $id) {
                    id
                    name
                }
            }
        ');

        static::assertEquals(Printer::doPrint($expected), Printer::doPrint($filteredQuery['document']));
    }

    /** @see it('should also remove variables when removing empty selection sets') */
    public function testShouldAlsoRemoveVariablesWhenRemovingEmptySelectionSets(): void
    {
        $query = Parser::parse('
            query customerQuery($id: ID!, $limit: Int) {
                customerById(id: $id) {
                    id
                    name
                    bookings(limit: $limit) {
                        paid
                    }
                }
            }
        ');

        $filteredQuery = $this->filter->transformRequest([
            'document' => $query,
            'variables' => [
                'id' => 'c1',
                'limit' => 10,
            ],
        ]);

        $expected = Parser::parse('
            query customerQuery($id: ID!) {
                customerById(id: $id) {
                    id
                    name
                }
            }
        ');

        static::assertEquals(Printer::doPrint($expected), Printer::doPrint($filteredQuery['document']));
    }

    /** @see it('should remove empty selection sets on wrapped objects (non-nullable/lists)') */
    public function testShouldRemoveEmptySelectionSetsOnWrappedObjectsNonNullableLists(): void
    {
        $query = Parser::parse('
            query bookingQuery($id: ID!) {
                bookingById(id: $id) {
                    id
                    propertyId
                    customer {
                        favoriteFood
                    }   
                }
            }
        ');

        $filteredQuery = $this->filter->transformRequest([
            'document' => $query,
            'variables' => ['id' => 'b1'],
        ]);

        $expected = Parser::parse('
            query bookingQuery($id: ID!) {
                bookingById(id: $id) {
                    id
                    propertyId
                }
            }
        ');

        static::assertEquals(Printer::doPrint($expected), Printer::doPrint($filteredQuery['document']));
    }
}
