<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Entities\Relationships;

use TimoCuijpers\LaravelModelsGenerator\Contracts\RelationshipInterface;

class HasMany implements RelationshipInterface
{
    public string $name;

    public function __construct(
        public string $related,
        public string $foreignKeyName,
        public ?string $localKeyName = null
    ) {
        $this->name = $related;
    }
}
