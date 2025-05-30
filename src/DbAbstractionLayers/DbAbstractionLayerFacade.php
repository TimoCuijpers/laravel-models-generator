<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\DbAbstractionLayers;

use TimoCuijpers\LaravelModelsGenerator\Contracts\DbAbstractionLayerInterface;
use TimoCuijpers\LaravelModelsGenerator\Exceptions\DbAbstractionLayerNotFound;

class DbAbstractionLayerFacade
{
    /**
     * @throws DbAbstractionLayerNotFound
     */
    public static function instance(): DbAbstractionLayerInterface
    {
        throw new DbAbstractionLayerNotFound;
    }
}
