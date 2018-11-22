<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Schema;
use function array_reduce;
use function array_reverse;
use function is_callable;

class Transforms
{
    /**
     * @param Transform[] $transforms
     */
    public static function applySchemaTransforms(Schema $originalSchema, array $transforms) : Schema
    {
        return array_reduce($transforms, static function (Schema $schema, Transform $transform) {
            return is_callable([$transform, 'transformSchema']) ? $transform->transformSchema($schema) : $schema;
        }, $originalSchema);
    }

    /**
     * @param mixed[]     $originalRequest
     * @param Transform[] $transforms
     *
     * @return mixed[]
     */
    public static function applyRequestTransforms(array $originalRequest, array $transforms) : array
    {
        return array_reduce($transforms, static function ($request, Transform $transform) {
            return is_callable([$transform, 'transformRequest']) ? $transform->transformRequest($request) : $request;
        }, $originalRequest);
    }

    /**
     * @param Transform[] $transforms
     *
     * @return mixed
     */
    public static function applyResultTransform(ExecutionResult $originalResult, array $transforms)
    {
        return array_reduce($transforms, static function ($result, Transform $transform) {
            return is_callable([$transform, 'transformResult']) ? $transform->transformResult($result) : $result;
        }, $originalResult);
    }

    /**
     * @param Transform[] $transforms
     */
    public static function composeTransforms(array $transforms) : Transform
    {
        $reverseTransforms = array_reverse($transforms);
        return new ComposeTransforms($reverseTransforms);
    }
}
