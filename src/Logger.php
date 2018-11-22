<?php

declare(strict_types=1);

namespace GraphQLTools;

use Throwable;

interface Logger
{
    public function log(Throwable $error) : void;
}
