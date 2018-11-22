<?php

declare(strict_types=1);

namespace GraphQLTools;

use Throwable;

class SimpleLogger implements Logger
{
    /** @var Throwable[] */
    public $errors;
    /** @var string|null  */
    public $name;
    /** @var callable|null  */
    public $callback;

    public function __construct(?string $name = null, ?callable $callback = null)
    {
        $this->name     = $name;
        $this->errors   = [];
        $this->callback = $callback;
    }

    public function log(Throwable $error) : void
    {
        $this->errors[] = $error;
        if (! $this->callback) {
            return;
        }

        $callback = $this->callback;
        $callback($error);
    }
}
