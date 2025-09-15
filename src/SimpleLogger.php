<?php

declare(strict_types=1);

namespace GraphQLTools;

use Throwable;

class SimpleLogger implements Logger
{
    /** @var Throwable[] */
    public array $errors;
    /** @var callable|null  */
    public $callback;

    public function __construct(public string|null $name = null, callable|null $callback = null)
    {
        $this->errors   = [];
        $this->callback = $callback;
    }

    public function log(Throwable $error): void
    {
        $this->errors[] = $error;
        if (! $this->callback) {
            return;
        }

        $callback = $this->callback;
        $callback($error);
    }
}
