<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQLTools\Stitching\Errors;
use PHPUnit\Framework\TestCase;

use function count;

class ErrorsTest extends TestCase
{
    /** @see it('should return OWN error kind if path is not defined') */
    public function testShouldReturnOWNErrorKindIfPathIsNotDefined(): void
    {
        $mockErrors = [
            'responseKey' => '',
            Errors::ERROR_SYMBOL => [
                ['message' => 'Test error without path'],
            ],
        ];

        static::assertEquals(
            Errors::getErrorsFromParent($mockErrors, 'responseKey'),
            [
                'kind' => 'OWN',
                'error' => $mockErrors[Errors::ERROR_SYMBOL][0],
            ],
        );
    }

    /** @see it('persists single error with a result') */
    public function testPersistsSingleErrorWithAResult(): void
    {
        $result = new ExecutionResult(
            null,
            [new ErrorWithResult('Test error', 'result')],
        );

        try {
            Errors::checkResultAndHandleErrors($result, ResolveInfoHelper::createResolveInfo([]), 'responseKey');
        } catch (Error $e) {
            static::assertEquals($e->getMessage(), 'Test error');
            static::assertFalse(isset($e->getPrevious()->errors));
        }
    }

    /** @see it('persists single error with extensions') */
    public function testPersistsSingleErrorWithExtensions(): void
    {
        $result = new ExecutionResult(
            null,
            [new ErrorWithExtensions('Test error', 'UNAUTHENTICATED')],
        );

        try {
            Errors::checkResultAndHandleErrors($result, ResolveInfoHelper::createResolveInfo([]), 'responseKey');
        } catch (Error $e) {
            static::assertEquals($e->getMessage(), 'Test error');
            static::assertEquals($e->getExtensions()['code'], 'UNAUTHENTICATED');
            static::assertFalse(isset($e->getPrevious()->errors));
        }
    }

    /** @see it('persists original errors without a result') */
    public function testPersistsOriginalErrorsWithoutAResult(): void
    {
        $result = new ExecutionResult(
            null,
            [new Error('Test error')],
        );

        try {
            Errors::checkResultAndHandleErrors($result, ResolveInfoHelper::createResolveInfo([]), 'responseKey');
        } catch (Error $e) {
            static::assertEquals($e->getMessage(), 'Test error');
            $originalError = $e->getPrevious();
            static::assertNotNull($originalError);
            static::assertNotEmpty($originalError->errors);
            static::assertCount(count($result->errors), $originalError->errors);
            foreach ($result->errors as $i => $error) {
                static::assertEquals($originalError->errors[$i], $error);
            }
        }
    }

    /** @see it('combines errors and persists the original errors') */
    public function testCombinesErrorsAndPersistsTheOriginalErrors(): void
    {
        $result = new ExecutionResult(
            null,
            [
                new Error('Error1'),
                new Error('Error2'),
            ],
        );

        try {
            Errors::checkResultAndHandleErrors($result, ResolveInfoHelper::createResolveInfo([]), 'responseKey');
        } catch (Error $e) {
            static::assertEquals($e->getMessage(), 'Error1\nError2');
            $originalError = $e->getPrevious();

            static::assertNotNull($originalError);
            static::assertNotEmpty($originalError->errors);
            static::assertCount(count($result->errors), $originalError->errors);
            foreach ($result->errors as $i => $error) {
                static::assertEquals($error, $originalError->errors[$i]);
            }
        }
    }
}
