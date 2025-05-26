<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;

class Resolvers
{
    /**
     * @param mixed[] $transforms
     * @param mixed[] $mapping
     *
     * @return mixed[]
     */
    public static function generateProxyingResolvers(Schema $targetSchema, array $transforms, array $mapping): array
    {
        $result = [];
        foreach ($mapping as $name => $innerMapping) {
            $result[$name] = [];

            foreach ($innerMapping as $from => $to) {
                $resolverType         = $to['operation'] === 'subscription' ? 'subscribe' : 'resolve';
                $result[$name][$from] = [
                    $resolverType => static::createProxyingResolver(
                        $targetSchema,
                        $to['operation'],
                        $to['name'],
                        $transforms,
                    ),
                ];
            }
        }

        return $result;
    }

    /** @return mixed[] */
    public static function generateSimpleMapping(Schema $targetSchema): array
    {
        $query        = $targetSchema->getQueryType();
        $mutation     = $targetSchema->getMutationType();
        $subscription = $targetSchema->getSubscriptionType();

        $result = [];
        if ($query) {
            $result[$query->name] = static::generateMappingFromObjectType($query, 'query');
        }

        if ($mutation) {
            $result[$mutation->name] = static::generateMappingFromObjectType($mutation, 'mutation');
        }

        if ($subscription) {
            $result[$subscription->name] = static::generateMappingFromObjectType($subscription, 'subscription');
        }

        return $result;
    }

    /** @return mixed[] */
    private static function generateMappingFromObjectType(ObjectType $type, string $operation): array
    {
        $result = [];
        $fields = $type->getFields();

        foreach ($fields as $fieldName => $_) {
            $result[$fieldName] = [
                'name' => $fieldName,
                'operation' => $operation,
            ];
        }

        return $result;
    }

    /** @param mixed[] $transforms */
    private static function createProxyingResolver(
        Schema $schema,
        string $operation,
        string $fieldName,
        array $transforms,
    ): callable {
        return static function (
            $parent,
            $args,
            $context,
            ResolveInfo $info,
        ) use (
            $schema,
            $operation,
            $fieldName,
            $transforms,
        ) {
            return DelegateToSchema::invoke([
                'schema' => $schema,
                'operation' => $operation,
                'fieldName' => $fieldName,
                'args' => [],
                'context' => $context,
                'info' => $info,
                'transforms' => $transforms,
            ]);
        };
    }
}
