<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Executor\Executor;

use function array_reduce;
use function is_callable;

class ChainResolvers
{
    /** @param callable[] $resolvers */
    public static function invoke(array $resolvers): callable
    {
        return static function ($root, $args, $ctx, $info) use ($resolvers) {
            return array_reduce($resolvers, static function ($prev, $curResolver) use ($args, $ctx, $info) {
                if (is_callable($curResolver)) {
                    return $curResolver($prev, $args, $ctx, $info);
                }

                return Executor::defaultFieldResolver($prev, $args, $ctx, $info);
            }, $root);
        };
    }
}
