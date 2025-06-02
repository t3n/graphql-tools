<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\GraphQL;
use GraphQLTools\GraphQLTools;
use PHPUnit\Framework\TestCase;

class DelegateToSchemaTest extends TestCase
{
    /**
     * @param mixed[] $properties
     *
     * @return mixed[]
     */
    protected static function findPropertyByLocationName(array $properties, string $name): array|null
    {
        foreach ($properties as $key => $property) {
            if ($property['location']['name'] === $name) {
                return $property;
            }
        }

        return null;
    }

    protected string $COORDINATES_QUERY = '
        query BookingCoordinates($bookingId: ID!) {
            bookingById (id: $bookingId) {
                property {
                    location {
                        coordinates
                    }
                }
            }
        }
    ';

    /** @return mixed[] */
    protected static function proxyResolvers(string $spec): array
    {
        return [
            'Booking' => [
                'property' => [
                    'fragment' => '... on Booking { propertyId }',
                    'resolve' => static function ($booking, $args, $context, $info) use ($spec) {
                        $delegateFn = $spec === 'standalone' ? static function (...$args) {
                            return GraphQLTools::delegateToSchema(...$args);
                        } : static function (...$args) use ($info) {
                            return $info->mergeInfo->delegateToSchema(...$args);
                        };

                        return $delegateFn([
                            'schema' => TestingSchemas::propertySchema(),
                            'operation' => 'query',
                            'fieldName' => 'propertyById',
                            'args' => ['id' => $booking['propertyId']],
                            'context' => $context,
                            'info' => $info,
                        ]);
                    },
                ],
            ],
            'Location' => [
                'coordinates' => [
                    'fragment' => '... on Location { name }',
                    'resolve' => static function ($location, $args, $context, $info) {
                        $name = $location['name'];

                        return static::findPropertyByLocationName(
                            TestingSchemas::$sampleData['Property'],
                            $name,
                        )['location']['coordinates'];
                    },
                ],
            ],
        ];
    }

    protected string $proxyTypeDefs = '
        extend type Booking {
            property: Property!
        }
        extend type Location {
            coordinates: String!
        }
    ';

    public function testDelegateToSchemaStandaloneShouldAddFragmentsForDeepTypes(): void
    {
        $schema = GraphQLTools::mergeSchemas([
            'schemas' => [TestingSchemas::bookingSchema(), TestingSchemas::propertySchema(), $this->proxyTypeDefs],
            'resolvers' => $this->proxyResolvers('standalone'),
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            $this->COORDINATES_QUERY,
            [],
            [],
            ['bookingId' => 'b1'],
        );

        $coordinates = TestingSchemas::$sampleData['Property']['p1']['location']['coordinates'];

        static::assertEquals(
            [
                'data' => [
                    'bookingById' => [
                        'property' => [
                            'location' => ['coordinates' => $coordinates],
                        ],
                    ],
                ],
            ],
            $result->toArray(),
        );
    }

    public function testDelegateToSchemaInfoMergeInfoShouldAddFragmentsForDeepTypes(): void
    {
        $schema = GraphQLTools::mergeSchemas([
            'schemas' => [TestingSchemas::bookingSchema(), TestingSchemas::propertySchema(), $this->proxyTypeDefs],
            'resolvers' => $this->proxyResolvers('info.mergeInfo'),
        ]);

        $result = GraphQL::executeQuery(
            $schema,
            $this->COORDINATES_QUERY,
            [],
            [],
            ['bookingId' => 'b1'],
        );

        $coordinates = TestingSchemas::$sampleData['Property']['p1']['location']['coordinates'];
        static::assertEquals(
            [
                'data' => [
                    'bookingById' => [
                        'property' => [
                            'location' => ['coordinates' => $coordinates],
                        ],
                    ],
                ],
            ],
            $result->toArray(),
        );
    }
}
