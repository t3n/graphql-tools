<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Language\AST\Node;
use GraphQL\Language\Printer;
use const PHP_EOL;
use function array_map;
use function array_merge;
use function array_reduce;
use function gettype;
use function implode;
use function in_array;
use function is_callable;
use function is_string;
use function trim;

class ConcatenateTypeDefs
{
    /**
     * @param string[]|Node[] $typeDefinitionsAry
     * @param callable[]      $calledFunctionRefs
     *
     * @throws SchemaError
     */
    public static function invoke(array $typeDefinitionsAry, array &$calledFunctionRefs = []) : string
    {
        $resolvedTypeDefinitions = [];
        foreach ($typeDefinitionsAry as $typeDef) {
            if ($typeDef instanceof Node) {
                $typeDef = Printer::doPrint($typeDef);
            }

            if (is_callable($typeDef)) {
                if (! in_array($typeDef, $calledFunctionRefs)) {
                    $calledFunctionRefs[]      = $typeDef;
                    $resolvedTypeDefinitions[] = static::invoke($typeDef(), $calledFunctionRefs);
                }
            } elseif (is_string($typeDef)) {
                $resolvedTypeDefinitions[] = trim($typeDef);
            } else {
                $type = gettype($typeDef);
                throw new SchemaError('typeDef array must contain only strings and functions, got ' . $type);
            }
        }

        return implode(
            PHP_EOL,
            static::uniq(
                array_map(
                    static function ($x) {
                        return trim($x);
                    },
                    $resolvedTypeDefinitions
                )
            )
        );
    }

    /**
     * @param mixed[] $array
     *
     * @return mixed[]
     */
    protected static function uniq(array $array) : array
    {
        return array_reduce(
            $array,
            static function ($accumulator, $currentValue) {
                return ! in_array($currentValue, $accumulator)
                    ? array_merge($accumulator, [$currentValue])
                    : $accumulator;
            },
            []
        );
    }
}
