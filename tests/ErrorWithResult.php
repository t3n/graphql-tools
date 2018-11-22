<?php

declare(strict_types=1);

namespace GraphQLTools\Tests;

use GraphQL\Error\Error;

class ErrorWithResult extends Error
{
    /** @var mixed */
    public $result;

    /**
     * @param mixed $result
     */
    public function __construct(string $message, $result)
    {
        parent::__construct($message);
        $this->result = $result;
    }
}
