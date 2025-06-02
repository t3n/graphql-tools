<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Error\Error;

class ErrorWithResult extends Error
{
    public function __construct(string $message, public mixed $result)
    {
        parent::__construct($message);
    }
}
