<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Schema;

class ComposeTransforms implements Transform
{
    /** @var Transform[] */
    private $transforms;

    /**
     * @param Transform[] $transforms
     */
    public function __construct(array $transforms)
    {
        $this->transforms = $transforms;
    }

    public function transformSchema(Schema $schema) : Schema
    {
        return Transforms::applySchemaTransforms($schema, $this->transforms);
    }

    /**
     * @param mixed[] $originalRequest
     *
     * @return mixed[]
     */
    public function transformRequest(array $originalRequest) : array
    {
        return Transforms::applyRequestTransforms($originalRequest, $this->transforms);
    }

    public function transformResult(ExecutionResult $result) : ExecutionResult
    {
        return Transforms::applyResultTransform($result, $this->transforms);
    }
}
