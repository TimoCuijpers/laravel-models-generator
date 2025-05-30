<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Entities\Relationships;

use TimoCuijpers\LaravelModelsGenerator\Contracts\RelationshipInterface;

class MorphTo implements RelationshipInterface
{
    public function __construct(
        public string $name,
    ) {}
}
