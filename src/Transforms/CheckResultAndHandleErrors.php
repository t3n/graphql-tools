<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQLTools\Stitching\Errors;

class CheckResultAndHandleErrors implements Transform
{
    private string $fieldName;

    public function __construct(private ResolveInfo $info, string|null $fieldName = null)
    {
        $this->fieldName = $fieldName;
    }

    public function transformResult(ExecutionResult $result): mixed
    {
        return Errors::checkResultAndHandleErrors($result, $this->info, $this->fieldName);
    }
}
