<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Executor\Executor;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;

use function array_filter;
use function mt_getrandmax;
use function mt_rand;

class AddSchemaLevelResolveFunction
{
    public static function invoke(Schema $schema, callable $fn): void
    {
        /** @var ObjectType[] $rootTypes */
        $rootTypes = array_filter([
            $schema->getQueryType(),
            $schema->getMutationType(),
            $schema->getSubscriptionType(),
        ], static function ($type) {
            return $type !== null;
        });

        foreach ($rootTypes as $type) {
            $rootResolveFn = static::runAtMostOncePerRequest($fn);
            $fields        = $type->getFields();

            foreach ($fields as $fieldName => $field) {
                if ($type === $schema->getSubscriptionType()) {
                    $field->resolveFn = static::wrapResolver($field->resolveFn, $fn);
                } else {
                    $field->resolveFn = static::wrapResolver($field->resolveFn, $rootResolveFn);
                }
            }
        }
    }

    protected static function wrapResolver(callable|null $innerResolver, callable $outerResolver): callable
    {
        return static function ($obj, $args, &$ctx, ResolveInfo $info) use ($innerResolver, $outerResolver) {
            $root = $outerResolver($obj, $args, $ctx, $info);

            if ($innerResolver) {
                return $innerResolver($root, $args, $ctx, $info);
            }

            return Executor::defaultFieldResolver($root, $args, $ctx, $info);
        };
    }

    protected static function runAtMostOncePerRequest(callable $fn): callable
    {
        $value = null;
        // cast to string to all $randomNumber to be an array key
        $randomNumber = (string) (mt_rand() / mt_getrandmax());

        return static function ($root, $args, &$ctx, $info) use ($randomNumber, $fn, &$value) {
            if (! isset($info->operation->__runAtMostOnce)) {
                $info->operation->__runAtMostOnce = [];
            }

            if (! isset($info->operation->__runAtMostOnce[$randomNumber])) {
                $info->operation->__runAtMostOnce[$randomNumber] = true;
                $value                                           = $fn($root, $args, $ctx, $info);
            }

            return $value;
        };
    }
}
