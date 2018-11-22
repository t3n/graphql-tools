<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;

class DefaultMergedResolver
{
    /**
     * @param mixed   $parent
     * @param mixed[] $args
     * @param mixed   $context
     *
     * @return array|mixed[]|null
     *
     * @throws Error
     */
    public static function invoke($parent, array $args, $context, ResolveInfo $info)
    {
        if (! $parent) {
            return null;
        }

        $responseKey = GetResponseKeyFromInfo::invoke($info);
        $errorResult = Errors::getErrorsFromParent($parent, $responseKey);

        if ($errorResult['kind'] === 'OWN') {
            $error = Error::createLocatedError(
                new Error($errorResult['error']->message, $info->fieldNodes, Errors::responsePathAsArray($info->path))
            );
            throw $error;
        }

        $result = $parent[$responseKey] ?? null;

        if (! $result && isset($parent['data'][$responseKey])) {
            $result = $parent['data'][$responseKey];
        }

        if (isset($errorResult['errors'])) {
            return Errors::annotateWithChildrenErrors($result, $errorResult['errors']);
        }

        return $result;
    }
}
