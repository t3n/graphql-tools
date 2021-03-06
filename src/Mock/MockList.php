<?php

declare(strict_types=1);

namespace GraphQLTools\Mock;

use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use function array_fill;
use function count;
use function is_array;
use function rand;

class MockList
{
    /** @var int|int[] */
    private $len;
    /** @var callable */
    private $wrappedFunction;

    /**
     * @param int|int[] $len
     */
    public function __construct($len, ?callable $wrappedFunction = null)
    {
        $this->len             = $len;
        $this->wrappedFunction = $wrappedFunction;
    }

    /**
     * @param mixed $root
     * @param mixed $args
     * @param mixed $context
     *
     * @return mixed[]
     */
    public function mock(
        $root,
        $args,
        $context,
        ResolveInfo $info,
        ListOfType $fieldType,
        callable $mockTypeFunc
    ) : array {
        $arr = null;
        if (is_array($this->len)) {
            $arr = array_fill(0, rand($this->len[0], $this->len[1]), null);
        } else {
            $arr = array_fill(0, $this->len, null);
        }

        $wrappedFunction = $this->wrappedFunction;
        for ($i = 0; $i< count($arr); $i++) {
            if ($wrappedFunction) {
                $res = $wrappedFunction($root, $args, $context, $info);
                if ($res instanceof MockList) {
                    $nullableType = Type::getNullableType($fieldType->ofType);
                    $arr[$i]      = $res->mock(
                        $root,
                        $args,
                        $context,
                        $info,
                        $nullableType,
                        $mockTypeFunc
                    );
                } else {
                    $arr[$i] = $res;
                }
            } else {
                $arr[$i] = $mockTypeFunc($fieldType->ofType)($root, $args, $context, $info);
            }
        }
        return $arr;
    }
}
