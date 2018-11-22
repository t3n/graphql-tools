<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reduce;

class ExtendResolversFromInterfaces
{
    /**
     * @param mixed[] $resolvers
     *
     * @return mixed[]
     */
    public static function invoke(Schema $schema, array $resolvers) : array
    {
        $typeNames = array_keys(array_merge(
            $schema->getTypeMap(),
            $resolvers
        ));

        $extendedResolvers = [];
        foreach ($typeNames as $typeName) {
            $typeResolvers = $resolvers[$typeName] ?? null;
            $type          = $schema->getType($typeName);

            if ($type instanceof ObjectType) {
                $interfaceResolvers = array_map(static function (InterfaceType $iFace) use ($resolvers) {
                    return $resolvers[$iFace->name] ?? [];
                }, $type->getInterfaces());

                $extendedResolvers[$typeName] = array_reduce(
                    $interfaceResolvers,
                    static function ($resolvers, $iFaceResolvers) {
                        return array_merge($resolvers, $iFaceResolvers);
                    },
                    $typeResolvers ?? []
                );
            } else {
                if ($typeResolvers) {
                    $extendedResolvers[$typeName] = $typeResolvers;
                }
            }
        }

        return $extendedResolvers;
    }
}
