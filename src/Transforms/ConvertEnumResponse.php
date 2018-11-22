<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Definition\EnumType;

class ConvertEnumResponse implements Transform
{
    /** @var EnumType */
    private $enumNode;

    public function __construct(EnumType $enumType)
    {
        $this->enumNode = $enumType;
    }

    public function transformResult(ExecutionResult $result) : ExecutionResult
    {
        $value = $this->enumNode->getValue($result);
        if ($value) {
            return $value->value;
        }

        return $result;
    }
}
