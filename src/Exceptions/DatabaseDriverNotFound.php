<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Exceptions;

use Exception;
use Throwable;

class DatabaseDriverNotFound extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
