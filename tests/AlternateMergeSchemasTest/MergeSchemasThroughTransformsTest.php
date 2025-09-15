<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\AlternateMergeSchemasTest;

use Exception;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQLTools\GraphQLTools;
use GraphQLTools\Tests\TestingSchemas;
use GraphQLTools\Transforms\FilterRootFields;
use GraphQLTools\Transforms\RenameRootFields;
use GraphQLTools\Transforms\RenameTypes;
use PHPUnit\Framework\TestCase;

class MergeSchemasThroughTransformsTest extends TestCase
{
    protected Schema $transformedPropertySchema;
    protected Schema $transformedBookingSchema;
    protected Schema $mergedSchema;

    public function setUp(): void
    {
        parent::setUp();

        $propertySchema = TestingSchemas::propertySchema();
        $bookingSchema  = TestingSchemas::bookingSchema();

        $this->transformedPropertySchema = GraphQLTools::transformSchema(
            $propertySchema,
            [
                new FilterRootFields(
                    static function ($operation, $rootField) {
                        return $operation . $rootField === 'Query.properties';
                    },
                ),
                new RenameTypes(
                    static function ($name) {
                        return 'Properties_' . $name;
                    },
                ),
                new RenameRootFields(
                    static function ($name) {
                        return 'Properties_' . $name;
                    },
                ),
            ],
        );

        $this->transformedBookingSchema = GraphQLTools::transformSchema(
            $bookingSchema,
            [
                new FilterRootFields(
                    static function ($operation, $rootField) {
                        return $operation . $rootField === 'Query.bookings';
                    },
                ),
                new RenameTypes(
                    static function ($name) {
                        return 'Bookings_' . $name;
                    },
                ),
                new RenameRootFields(
                    static function ($name) {
                        return 'Booking_' . $name;
                    },
                ),
            ],
        );

        $this->mergedSchema = GraphQLTools::mergeSchemas([
            'schemas' => [
                $this->transformedPropertySchema,
                $this->transformedBookingSchema,
                LinkSchema::get(),
            ],
            'resolvers' => [
                'Query' => [
                    'node' => function ($parent, $args, $context, $info) use ($propertySchema, $bookingSchema) {
                        if ($args['id'][0] === 'p') {
                            return $info->mergeInfo->delegateToSchema([
                                'schema' => $propertySchema,
                                'operation' => 'query',
                                'fieldName' => 'propertyById',
                                'args' => $args,
                                'context' => $context,
                                'info' => $info,
                                'transforms' => $this->transformedPropertySchema->transforms,
                            ]);
                        }

                        if ($args['id'][0] === 'b') {
                            return $info->mergeInfo->delegateToSchema([
                                'schema' => $bookingSchema,
                                'operation' => 'query',
                                'fieldName' => 'bookingById',
                                'args' => $args,
                                'context' => $context,
                                'info' => $info,
                                'transforms' => $this->transformedBookingSchema->transforms,
                            ]);
                        }

                        throw new Exception('Invalid id');
                    },
                ],
                'Properties_Property' => [
                    'bookings' => [
                        'fragment' => 'fragment PropertyFragment on Property { id }',
                        'resolve' => function ($parent, $args, $context, $info) use ($bookingSchema) {
                            return $info->mergeInfo->delegateToSchema([
                                'schema' => $bookingSchema,
                                'operation' => 'query',
                                'fieldName' => 'bookingsByPropertyId',
                                'args' => [
                                    'propertyId' => $parent['id'],
                                    'limit' => $args['limit'] ?? null,
                                ],
                                'context' => $context,
                                'info' => $info,
                                'transforms' => $this->transformedBookingSchema->transforms,
                            ]);
                        },
                    ],
                ],
                'Bookings_Booking' => [
                    'property' => [
                        'fragment' => 'fragment BookingFragment on Booking { propertyId }',
                        'resolve' => function ($parent, $args, $context, $info) use ($propertySchema) {
                            return $info->mergeInfo->delegateToSchema([
                                'schema' => $propertySchema,
                                'operation' => 'query',
                                'fieldName' => 'propertyById',
                                'args' => [
                                    'id' => $parent['propertyId'],
                                ],
                                'context' => $context,
                                'info' => $info,
                                'transforms' => $this->transformedPropertySchema->transforms,
                            ]);
                        },
                    ],
                ],
            ],
        ]);
    }

    /** @see it('node should work') */
    public function testNodeShouldWork(): void
    {
        $result = GraphQL::executeQuery(
            $this->mergedSchema,
            '
                query($pid: ID!, $bid: ID!) {
                    property: node(id: $pid) {
                        __typename
                        ... on Properties_Property {
                            name
                            bookings {
                                startTime
                                endTime
                            }
                        }
                    }
                    booking: node(id: $bid) {
                        __typename
                        ... on Bookings_Booking {
                            startTime
                            endTime
                            property {
                                id  
                                name
                            }
                        }
                    }
                }
            ',
            [],
            [],
            [
                'pid' => 'p1',
                'bid' => 'b1',
            ],
        );

        static::assertEquals(
            [
                'data' => [
                    'booking' => [
                        '__typename' => 'Bookings_Booking',
                        'endTime' => '2016-06-03',
                        'property' => [
                            'id' => 'p1',
                            'name' => 'Super great hotel',
                        ],
                        'startTime' => '2016-05-04',
                    ],
                    'property' => [
                        '__typename' => 'Properties_Property',
                        'bookings' => [
                            [
                                'endTime' => '2016-06-03',
                                'startTime' => '2016-05-04',
                            ],
                            [
                                'endTime' => '2016-07-03',
                                'startTime' => '2016-06-04',
                            ],
                            [
                                'endTime' => '2016-09-03',
                                'startTime' => '2016-08-04',
                            ],
                        ],
                        'name' => 'Super great hotel',
                    ],
                ],
            ],
            $result->toArray(),
        );
    }
}
