<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use Exception;
use GraphQL\Error\Error;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Promise;
use GraphQLTools\Logger;
use Throwable;

use const PHP_EOL;

class DecorateWithLogger
{
    public static function invoke(callable|null $fn, Logger $logger, string $hint): callable
    {
        if ($fn === null) {
            $fn = [Executor::class, 'defaultFieldResolver'];
        }

        $logError = static function (Throwable $e) use ($logger, $hint): void {
            $message = $e->getMessage();
            if ($hint) {
                $message = 'Error in resolver ' . $hint . PHP_EOL . $message;
            }

            $newE = new Exception($message, $e->getCode(), $e);
            $logger->log($newE);
        };

        return static function ($root, $args, $ctx, $info) use ($fn, $logError) {
            try {
                $result = $fn($root, $args, $ctx, $info);

                if ($result instanceof Promise) {
                    $result->then(null, static function (Error $reason) use ($logError) {
                        $logError($reason);

                        return $reason;
                    });
                }

                return $result;
            } catch (Throwable $e) {
                $logError($e);

                throw $e;
            }
        };
    }
}
