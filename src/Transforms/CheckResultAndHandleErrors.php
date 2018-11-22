<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQLTools\Stitching\Errors;

class CheckResultAndHandleErrors implements Transform
{
    /** @var ResolveInfo */
    private $info;

    /** @var string */
    private $fieldName;

    public function __construct(ResolveInfo $info, ?string $fieldName = null)
    {
        $this->info      = $info;
        $this->fieldName = $fieldName;
    }

    /**
     * @return mixed
     */
    public function transformResult(ExecutionResult $result)
    {
        return Errors::checkResultAndHandleErrors($result, $this->info, $this->fieldName);
    }
}
