<?php

declare(strict_types=1);

namespace GraphQLTools\Stitching;

use GraphQL\Error\Error;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQLTools\Utils;

use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function count;
use function implode;
use function is_object;

class Errors
{
    public const ERROR_SYMBOL = '@@__subSchemaErrors';

    /** @param mixed[]|null $childrenErrors */
    public static function annotateWithChildrenErrors(mixed $object, array|null $childrenErrors): mixed
    {
        if ($childrenErrors === null || count($childrenErrors) === 0) {
            return $object;
        }

        // numeric array
        if (Utils::isNumericArray($object)) {
            $byIndex = [];

            foreach ($childrenErrors as $error) {
                if (! isset($error['path'])) {
                    continue;
                }

                $index           = $error['path'][1];
                $current         = $byIndex[$index] ?? [];
                $current[]       = array_merge($error, ['path' => array_slice($error['path'], 1)]);
                $byIndex[$index] = $current;
            }

            return array_map(static function ($item, int $index) use ($byIndex): array {
                return static::annotateWithChildrenErrors($item, $byIndex[$index]);
            }, $object, array_keys($object));
        }

        if (is_object($object)) {
            $object->{self::ERROR_SYMBOL} = array_map(static function (array $error): array {
                return array_merge(
                    $error,
                    isset($error['path']) ? ['path' => array_slice($error['path'], 1)] : [],
                );
            }, $childrenErrors);

            return $object;
        }

        return $object;
    }

    /**
     * @param mixed[] $object
     *
     * @return mixed[]
     */
    public static function getErrorsFromParent(array $object, string $fieldName): array
    {
        $errors         = $object[self::ERROR_SYMBOL] ?? [];
        $childrenErrors = [];

        foreach ($errors as $error) {
            if (! isset($error['path']) || (count($error['path']) === 1 && $error['path'][0] === $fieldName)) {
                return [
                    'kind' => 'OWN',
                    'error' => $error,
                ];
            }

            if ($error['path'][0] !== $fieldName) {
                continue;
            }

            $childrenErrors[] = $error;
        }

        return [
            'kind' => 'CHILDREN',
            'errors' => $childrenErrors,
        ];
    }

    public static function checkResultAndHandleErrors(ExecutionResult $result, ResolveInfo $info, string|null $responseKey): mixed
    {
        if (! $responseKey) {
            $responseKey = GetResponseKeyFromInfo::invoke($info);
        }

        if (count($result->errors) > 0 && (! $result->data || $result->data[$responseKey] === null)) {
            if (count($result->errors) === 1 && static::hasResult($result->errors[0])) {
                $newError = $result->errors[0];
            } else {
                $message  = static::concatErrors($result->errors);
                $newError = new class ($message, $result->errors) extends Error {
                    /** @var Error[] */
                    public array $errors;

                    /** @param Error[] $errors */
                    public function __construct(string $message, array $errors)
                    {
                        parent::__construct($message);

                        $this->errors = $errors;
                    }
                };
            }

            $locatedError = Error::createLocatedError(
                $newError,
                $info->fieldNodes,
                $info->path,
            );

            throw $locatedError;
        }

        $resultObject = $result->data[$responseKey];

        if (count($result->errors) > 0) {
            $resultObject = static::annotateWithChildrenErrors($resultObject, $result->errors);
        }

        return $resultObject;
    }

    /** @param Error[] $errors */
    protected static function concatErrors(array $errors): string
    {
        return implode('\n', array_map(static function (Error $error): string {
            return $error->message;
        }, $errors));
    }

    protected static function hasResult(Error $error): bool
    {
        return isset($error->result)
            || count($error->getExtensions()) > 0
            || ($error->getPrevious() && isset($error->getPrevious()->result));
    }
}
