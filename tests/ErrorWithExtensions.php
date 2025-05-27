<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Error\Error;

class ErrorWithExtensions extends Error
{
    public function __construct(string $message, string $code)
    {
        parent::__construct($message, null, null, [], null, null, ['code' => $code]);
    }
}
