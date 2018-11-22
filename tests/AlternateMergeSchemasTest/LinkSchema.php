<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\AlternateMergeSchemasTest;

class LinkSchema
{
    public static function get() : string
    {
        return '
            """
            A new type linking the Property type.
            """
            
            type LinkType {
                test: String
                """
                The property.
                """
                property: Properties_Property
            }
            interface Node {
                id: ID!
            }
            extend type Bookings_Booking implements Node {
                """
                The property of the booking.
                """
                property: Properties_Property
            }
            extend type Properties_Property implements Node {
                """
                A list of bookings.
                """
                bookings(
                    """
                    The maximum number of bookings to retrieve.
                    """
                    limit: Int
                ): [Bookings_Booking]
            }
            extend type Query {
                linkTest: LinkType
                node(id: ID!): Node
                nodes: [Node]
            }
            extend type Bookings_Customer implements Node
        ';
    }
}
