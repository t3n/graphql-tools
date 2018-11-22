<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Error\Error;
use GraphQL\Language\AST\BooleanValueNode;
use GraphQL\Language\AST\FloatValueNode;
use GraphQL\Language\AST\IntValueNode;
use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\ObjectValueNode;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Language\AST\ValueNode;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Schema;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use GraphQLTools\GraphQLTools;
use function is_array;
use function json_encode;

class TestingSchemas
{
    /**
     * @param mixed $value
     */
    protected static function coerceString($value) : string
    {
        if (is_array($value)) {
            throw new Error('String cannot represent an array value [' . $value . ']');
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    protected static function identity($value)
    {
        return $value;
    }

    /**
     * @return mixed
     */
    protected static function parseLiteral(ValueNode $ast)
    {
        if ($ast instanceof StringValueNode || $ast instanceof BooleanValueNode) {
            return $ast->value;
        }

        if ($ast instanceof IntValueNode || $ast instanceof FloatValueNode) {
            return (float) $ast->value;
        }

        if ($ast instanceof ObjectValueNode) {
            $value = [];
            foreach ($ast->fields as $field) {
                $value[$field->name->value] = static::parseLiteral($field->value);
            }
            return $value;
        }

        if ($ast instanceof ListValueNode) {
            return array_map(static function ($value) {
                return static::parseLiteral($value);
            }, $ast->values);
        }

        return null;
    }

    protected static function graphQLJSON() : CustomScalarType
    {
        return new CustomScalarType([
            'name' => 'JSON',
            'description' => 'The `JSON` scalar type represents JSON values as specified by'
            . '[ECMA-404](http://www.ecma-international.org/publications/files/ECMA-ST/ECMA-404.pdf).',
            'serialize' => static function ($value) {
                return static::identity($value);
            },
            'parseValue' => static function ($value) {
                return static::identity($value);
            },
            'parseLiteral' => static function ($ast) {
                return static::parseLiteral($ast);
            },
        ]);
    }

    protected static function dateTime() : CustomScalarType
    {
        return new CustomScalarType([
            'name' => 'DateTime',
            'description' => 'Simple fake datetime',
            'serialize' => static function ($value) {
                return static::coerceString($value);
            },
            'parseValue' => static function ($value) {
                return static::coerceString($value);
            },
            'parseLiteral' => static function ($ast) {
                if ($ast instanceof StringValueNode) {
                    return $ast->value;
                }

                return null;
            },
        ]);
    }

    /** @var mixed[] */
    public static $sampleData = [
        'Product' => [
            'pd1' => [
                'id' => 'pd1',
                'type' => 'simple',
                'price' => 100,
            ],
            'pd2' => [
                'id' => 'pd2',
                'type' => 'download',
                'url' => 'https=> //graphql.org',
            ],
        ],
        'Property' => [
            'p1' => [
                'id' => 'p1',
                'name' => 'Super great hotel',
                'location' => [
                    'name' => 'Helsinki',
                    'coordinates' => '60.1698° N, 24.9383° E',
                ],
            ],
            'p2' => [
                'id' => 'p2',
                'name' => 'Another great hotel',
                'location' => [
                    'name' => 'San Francisco',
                    'coordinates' => '37.7749° N, 122.4194° W',
                ],
            ],
            'p3' => [
                'id' => 'p3',
                'name' => 'BedBugs - The Affordable Hostel',
                'location' => [
                    'name' => 'Helsinki',
                    'coordinates' => '60.1699° N, 24.9384° E',
                ],
            ],
        ],
        'Booking' => [
            'b1' => [
                'id' => 'b1',
                'propertyId' => 'p1',
                'customerId' => 'c1',
                'startTime' => '2016-05-04',
                'endTime' => '2016-06-03',
            ],
            'b2' => [
                'id' => 'b2',
                'propertyId' => 'p1',
                'customerId' => 'c2',
                'startTime' => '2016-06-04',
                'endTime' => '2016-07-03',
            ],
            'b3' => [
                'id' => 'b3',
                'propertyId' => 'p1',
                'customerId' => 'c3',
                'startTime' => '2016-08-04',
                'endTime' => '2016-09-03',
            ],
            'b4' => [
                'id' => 'b4',
                'propertyId' => 'p2',
                'customerId' => 'c1',
                'startTime' => '2016-10-04',
                'endTime' => '2016-10-03',
            ],
        ],
        'Customer' => [
            'c1' => [
                'id' => 'c1',
                'email' => 'examplec1@example.com',
                'name' => 'Exampler Customer',
                'vehicleId' => 'v1',
            ],
            'c2' => [
                'id' => 'c2',
                'email' => 'examplec2@example.com',
                'name' => 'Joe Doe',
                'vehicleId' => 'v2',
            ],
            'c3' => [
                'id' => 'c3',
                'email' => 'examplec3@example.com',
                'name' => 'Liisa Esimerki',
                'address' => 'Esimerkikatu 1 A 77, 99999 Kyyjarvi',
            ],
        ],
        'Vehicle' => [
            'v1' => [
                'id' => 'v1',
                'bikeType' => 'MOUNTAIN',
            ],
            'v2' => [
                'id' => 'v2',
                'licensePlate' => 'GRAPHQL',
            ],
        ],
    ];

    protected static function addressTypeDef() : string
    {
        return '
            type Address {
                street: String
                city: String
                state: String
                zip: String
            }
        ';
    }

    protected static function propertyAddressTypeDef() : string
    {
        return '
            type Property {
                id: ID!
                name: String!
                location: Location
                error: String
            }
        ';
    }

    protected static function propertyRootTypeDefs() : string
    {
        return '
            type Location {
                name: String!
            }
            
            enum TestInterfaceKind {
                ONE
                TWO
            }
            
            interface TestInterface {
                kind: TestInterfaceKind
                testString: String
            }
            
            type TestImpl1 implements TestInterface {
                kind: TestInterfaceKind
                testString: String
                foo: String
            }
            
            type TestImpl2 implements TestInterface {
                kind: TestInterfaceKind
                testString: String
                bar: String
            }
            
            type UnionImpl {
                someField: String
            }
            
            union TestUnion = TestImpl1 | UnionImpl
            
            input InputWithDefault {
                test: String = "Foo"
            }
            
            type Query {
                propertyById(id: ID!): Property
                properties(limit: Int): [Property!]
                contextTest(key: String!): String
                dateTimeTest: DateTime
                jsonTest(input: JSON): JSON
                interfaceTest(kind: TestInterfaceKind): TestInterface
                unionTest(output: String): TestUnion
                errorTest: String
                errorTestNonNull: String!
                relay: Query!
                defaultInputTest(input: InputWithDefault!): String
            }
        ';
    }

    protected static function propertyAddressTypeDefs() : string
    {
        $addressTypeDef         = static::addressTypeDef();
        $propertyAddressTypeDef = static::propertyAddressTypeDef();
        $propertyRootTypeDefs   = static::propertyRootTypeDefs();

        return "
            scalar DateTime
            scalar JSON
            
            ${addressTypeDef}
            ${propertyAddressTypeDef}
            ${propertyRootTypeDefs}
        ";
    }

    /**
     * @return mixed[]
     */
    protected static function propertyResolvers() : array
    {
        return [
            'Query' => [
                'propertyById' => static function ($root, $args) {
                    return static::$sampleData['Property'][$args['id']];
                },
                'properties' => static function ($root, $args) {
                    $limit = $args['limit'];
                    $list  = array_values(static::$sampleData['Property']);
                    if ($limit) {
                        return array_slice($list, 0, $limit);
                    }

                    return $list;
                },
                'contextTest' => static function ($root, $args, $context) {
                    return json_encode($context[$args['key']]);
                },
                'dateTimeTest' => static function () {
                    return '1987-09-25T12:00:00';
                },
                'jsonTest' => static function ($root, $args) {
                    return $args['input'];
                },
                'interfaceTest' => static function ($root, $args) {
                    if ($args['kind'] === 'ONE') {
                        return [
                            'kind' => 'ONE',
                            'testString' => 'test',
                            'foo' => 'foo',
                        ];
                    }

                    return [
                        'kind' => 'TWO',
                        'testString' => 'test',
                        'bar' => 'bar',
                    ];
                },
                'unionTest' => static function ($root, $args) {
                    if ($args['output'] === 'Interface') {
                        return [
                            'kind' => 'ONE',
                            'testString' => 'test',
                            'foo' => 'foo',
                        ];
                    }

                    return ['someField' => 'Bar'];
                },
                'errorTest' => static function () : void {
                    throw new Error('Sample error!');
                },
                'errorTestNonNull' => static function () : void {
                    throw new Error('Sample error non-null!');
                },
                'defaultInputTest' => static function ($parent, $args) {
                    return $args['input']['test'];
                },
            ],
            'DateTime' => static::dateTime(),
            'JSON' => static::graphQLJSON(),
            'TestInterface' => [
                '__resolveType' => static function ($obj) {
                    if ($obj['kind'] === 'ONE') {
                        return 'TestImpl1';
                    }

                    return 'TestImpl2';
                },
            ],
            'TestUnion' => [
                '__resolveType' => static function ($obj) {
                    if ($obj->kind === 'ONE') {
                        return 'TestImpl1';
                    }

                    return 'UnionImpl';
                },
            ],
            'Property' => [
                'error' => static function () : void {
                    throw new Error('Property.error error');
                },
            ],
        ];
    }

    protected static function customerAddressTypeDef() : string
    {
        return '
            type Customer implements Person {
                id: ID!
                email: String!
                name: String!
                address: Address
                bookings(limit: Int): [Booking!]
                vehicle: Vehicle
                error: String
            }
        ';
    }

    protected static function bookingRootTypeDefs() : string
    {
        return '
            scalar DateTime
            type Booking {
                id: ID!
                propertyId: ID!
                customer: Customer!
                startTime: String!
                endTime: String!
                error: String
                errorNonNull: String!
            }
            interface Person {
                id: ID!
                name: String!
            }
            union Vehicle = Bike | Car
            type Bike {
                id: ID!
                bikeType: String
            }
            type Car {
                id: ID!
                licensePlate: String
            }
            type Query {
                bookingById(id: ID!): Booking
                bookingsByPropertyId(propertyId: ID!, limit: Int): [Booking!]
                customerById(id: ID!): Customer
                bookings(limit: Int): [Booking!]
                customers(limit: Int): [Customer!]
            }
            input BookingInput {
                propertyId: ID!
                customerId: ID!
                startTime: DateTime!
                endTime: DateTime!
            }
            type Mutation {
                addBooking(input: BookingInput): Booking
            }
        ';
    }

    protected static function bookingAddressTypeDefs() : string
    {
        $addressTypeDef         = static::addressTypeDef();
        $customerAddressTypeDef = static::customerAddressTypeDef();
        $bookingRootTypeDefs    = static::bookingRootTypeDefs();

        return "
            ${addressTypeDef}
            ${customerAddressTypeDef}
            ${bookingRootTypeDefs}
        ";
    }

    /**
     * @return mixed[]
     */
    protected static function bookingResolvers() : array
    {
        return [
            'Query' => [
                'bookingById' => static function ($parent, $args) {
                    return static::$sampleData['Booking'][$args['id']];
                },
                'bookingsByPropertyId' => static function ($parent, $args) {
                    $limit = $args['limit'] ?? null;
                    $list  = array_values(static::$sampleData['Booking']);
                    $list  = array_filter($list, static function ($booking) use ($args) {
                        return $booking['propertyId'] === $args['propertyId'];
                    });

                    if ($limit) {
                        return array_slice($list, 0, $limit);
                    }

                    return $list;
                },
                'customerById' => static function ($parent, $args) {
                    return static::$sampleData['Customer'][$args['id']];
                },
                'bookings' => static function ($parent, $args) {
                    $limit = $args['limit'] ?? null;
                    $list  = array_values(static::$sampleData['Booking']);
                    if ($limit) {
                        return array_slice($list, 0, $limit);
                    }

                    return $list;
                },
                'customers' => static function ($parent, $args) {
                    $limit = $args['limit'] ?? null;
                    $list  = array_values(static::$sampleData['Customer']);
                    if ($limit) {
                        return array_slice($list, 0, $limit);
                    }

                    return $list;
                },
            ],
            'Mutation' => [
                'addBooking' => static function ($parent, $args) {
                    $input = $args['input'];
                    [
                        'propertyId' => $propertyId,
                        'customerId' => $customerId,
                        'startTime' => $startTime,
                        'endTime' => $endTime,
                    ]      = $input;

                    return [
                        'id' => 'newId',
                        'propertyId' => $propertyId,
                        'customerId' => $customerId,
                        'startTime' => $endTime,
                    ];
                },
            ],
            'Booking' => [
                '__isTypeOf' => static function ($source, $context, $info) {
                    return isset($source['id']);
                },
                'customer' => static function ($parent) {
                    return static::$sampleData['Customer'][$parent['customerId']];
                },
                'error' => static function () : void {
                    throw new Error('Booking.error error');
                },
                'errorNonNull' => static function () : void {
                    throw new Error('Booking.errorNoNull error');
                },
            ],
            'Customer' => [
                'bookings' => static function ($parent, $args) {
                    $limit = $args['limit'] ?? null;
                    $list  = array_values(static::$sampleData['Booking']);
                    $list  = array_filter($list, static function ($booking) use ($parent) {
                        return $booking['customerId'] === $parent['id'];
                    });
                    if ($limit) {
                        return array_slice($list, 0, $limit);
                    }

                    return $list;
                },
                'vehicle' => static function ($parent) {
                    return static::$sampleData['Vehicle'][$parent['vehicleId']];
                },
                'error' => static function () : void {
                    throw new Error('Customer.error error');
                },
            ],
            'Vehicle' => [
                '__resolveType' => static function ($parent) {
                    if (isset($parent['licensePlate'])) {
                        return 'Car';
                    }

                    if (isset($parent['bikeType'])) {
                        return 'Bike';
                    }

                    throw new Error('Could not resolve Vehicle type');
                },
            ],
            'DateTime' => static::dateTime(),
        ];
    }

    public static function propertySchema() : Schema
    {
        return GraphQLTools::makeExecutableSchema([
            'typeDefs' => static::propertyAddressTypeDefs(),
            'resolvers' => static::propertyResolvers(),
        ]);
    }

    public static function bookingSchema() : Schema
    {
        return GraphQLTools::makeExecutableSchema([
            'typeDefs' => static::bookingAddressTypeDefs(),
            'resolvers' => static::bookingResolvers(),
            'resolverValidationOptions' => ['requireResolversForResolveType' => false],
        ]);
    }
}
