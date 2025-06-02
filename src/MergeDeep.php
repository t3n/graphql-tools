<?php

declare(strict_types=1);

namespace GraphQLTools;

use function is_array;

class MergeDeep
{
    /**
     * @param mixed[] $target
     * @param mixed[] $source
     *
     * @return mixed[]
     */
    public static function invoke(array $target, array $source): array
    {
        $output = $target;
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                if (! isset($target[$key])) {
                    $output[$key] = $value;
                } else {
                    $output[$key] = static::invoke($target[$key], $value);
                }
            } else {
                $output[$key] = $value;
            }
        }

        return $output;
    }
}
