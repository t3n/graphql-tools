<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Type\Definition\ResolveInfo;

class GetResponseKeyFromInfo
{
    /**
     * Get the key under which the result of this resolver will be placed in the response JSON. Basically, just
     * resolves aliases.
     */
    public static function invoke(ResolveInfo $info): string
    {
        return $info->fieldNodes[0]->alias ? $info->fieldNodes[0]->alias->value : $info->fieldName;
    }
}
