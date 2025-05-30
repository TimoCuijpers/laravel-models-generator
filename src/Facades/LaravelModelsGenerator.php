<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \TimoCuijpers\LaravelModelsGenerator\LaravelModelsGenerator
 */
class LaravelModelsGenerator extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \TimoCuijpers\LaravelModelsGenerator\LaravelModelsGenerator::class;
    }
}
