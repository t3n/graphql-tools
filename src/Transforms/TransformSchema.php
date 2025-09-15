<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Type\Schema;
use GraphQLTools\MakeExecutableSchema;
use GraphQLTools\Stitching\Resolvers;

class TransformSchema
{
    /** @param Transform[] $transforms */
    public static function invoke(Schema $targetSchema, array $transforms): Schema
    {
        $schema    = VisitSchema::invoke($targetSchema, [], true);
        $mapping   = Resolvers::generateSimpleMapping($targetSchema);
        $resolvers = Resolvers::generateProxyingResolvers($targetSchema, $transforms, $mapping);
        $schema    = MakeExecutableSchema::addResolveFunctionsToSchema(
            $schema,
            $resolvers,
            ['allowResolversNotInSchema' => true],
        );

        $schema             = Transforms::applySchemaTransforms($schema, $transforms);
        $schema->transforms = $transforms;

        return $schema;
    }
}
