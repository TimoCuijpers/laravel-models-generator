<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Entities;

use TimoCuijpers\LaravelModelsGenerator\Entities\Relationships\BelongsTo;
use TimoCuijpers\LaravelModelsGenerator\Entities\Relationships\BelongsToMany;
use TimoCuijpers\LaravelModelsGenerator\Entities\Relationships\HasMany;
use TimoCuijpers\LaravelModelsGenerator\Entities\Relationships\MorphMany;
use TimoCuijpers\LaravelModelsGenerator\Entities\Relationships\MorphTo;

class Entity
{
    /** @var array<string> */
    public array $imports = [];

    /** @var array<Property> */
    public array $properties = [];

    /** @var array<array> */
    public array $rules = [];

    /** @var array<HasMany> */
    public array $hasMany = [];

    /** @var array<BelongsTo> */
    public array $belongsTo = [];

    /** @var array<BelongsToMany> */
    public array $belongsToMany = [];

    /** @var array<MorphMany> */
    public array $morphMany = [];

    /** @var array<MorphTo> */
    public array $morphTo = [];

    /** @var array<string> */
    public array $hidden = [];

    /** @var array<string> */
    public array $fillable = [];

    /** @var array<string> */
    public array $casts = [];

    public bool $abstract = false;

    public ?string $parent = null;

    /** @var array<string> */
    public array $interfaces = [];

    /** @var array<string> */
    public array $traits = [];

    public bool $timestamps = false;

    public ?bool $showTableProperty = null;

    public bool $showTimestampsProperty = true;

    public bool $softDeletes = false;

    public ?string $namespace = null;

    public ?PrimaryKey $primaryKey = null;

    public function __construct(public string $name, public string $className)
    {
        /** @var array<string> $parts */
        $parts = explode('\\', (string)config('models-generator.parent', 'Model'));
        $this->parent = $parts ? end($parts) : 'Model';
        $this->interfaces = (array)config('models-generator.interfaces', []);
        $this->traits = (array)config('models-generator.traits', []);
        $this->showTableProperty = (bool)config('models-generator.table', false);
        $this->className = (string)implode(array_map('ucfirst', explode('.', $this->className)));
    }

    public function importLaravelModel(): bool
    {
        return !str_contains($this->parent ?? '', 'Base');
    }

    public function cleanForBase(): void
    {
        $this->rules = [];
        $this->fillable = [];
        $this->hasMany = [];
        $this->belongsTo = [];
        $this->belongsToMany = [];
        $this->morphMany = [];
        $this->morphTo = [];
        $this->casts = [];
        $this->properties = [];
        $this->interfaces = [];
        $this->primaryKey = null;
        $this->softDeletes = false;
        $this->traits = [];
        $this->showTableProperty = false;
        $this->showTimestampsProperty = false;
        $this->parent = 'Base' . $this->className;
        $this->abstract = false;
        $this->namespace = (string)config('models-generator.namespace', 'App\Models');
        $this->imports = [$this->namespace . '\\Base\\' . $this->className . ' as Base' . $this->className];
    }
}
