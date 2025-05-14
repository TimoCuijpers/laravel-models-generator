<?php

declare(strict_types=1);

namespace GiacomoMasseroni\LaravelModelsGenerator\Concerns;

use DB;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\BigIntType;
use Doctrine\DBAL\Types\BooleanType;
use Doctrine\DBAL\Types\DateImmutableType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\DateTimeTzType;
use Doctrine\DBAL\Types\DateType;
use Doctrine\DBAL\Types\DecimalType;
use Doctrine\DBAL\Types\FloatType;
use Doctrine\DBAL\Types\IntegerType;
use Doctrine\DBAL\Types\SmallFloatType;
use Doctrine\DBAL\Types\SmallIntType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Doctrine\DBAL\Types\Type;
use GiacomoMasseroni\LaravelModelsGenerator\Contracts\DriverConnectorInterface;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Entity;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\PrimaryKey;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Property;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Relationships\BelongsTo;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Relationships\BelongsToMany;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Relationships\HasMany;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Relationships\MorphMany;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Relationships\MorphTo;
use GiacomoMasseroni\LaravelModelsGenerator\Entities\Table;
use GiacomoMasseroni\LaravelModelsGenerator\Enums\ColumnTypeEnum;
use GiacomoMasseroni\LaravelModelsGenerator\Helpers\NamingHelper;
use Illuminate\Support\Str;
use Log;

/**
 * @mixin DriverConnectorInterface
 */
trait DBALable
{
    private AbstractSchemaManager $sm;

    private Connection $conn;

    /**
     * @var array<string, mixed>
     */
    private static array $entityColumns = [];

    /**
     * @var array<string, mixed>
     */
    private static array $entityIndexes = [];

    /**
     * @var array<string, string>
     */
    private array $typeColumnPropertyMaps = [
        'datetime' => 'Carbon',
    ];

    /**
     * @throws Exception
     */
    public function listTables(): array
    {
        return $this->getTables($this->sm->listTables(), $this->sm->listViews());
    }

    /**
     * @param  list<\Doctrine\DBAL\Schema\Table>  $tables
     * @param  list<View>  $views
     *
     * @return array<string, Table>
     *
     * @throws Exception
     */
    private function getTables(array $tables, array $views): array
    {
        /** @var array<string, Table> $dbTables */
        $dbTables = [];
        $dbViews = [];

        $morphables = [];

         array_map(function($dbView) use (&$dbViews) {
             $sqlView = $dbView->getSql();
             $seperatedSections = explode("\r\n", $sqlView);
             $viewDeclarations = array_filter($seperatedSections, function ($section) {
                 return Str::contains($section, ' as ');
             });
             $viewColumns = [];
             array_map(function ($section) use (&$viewColumns) {
                 $viewColumns[Str::between($section, "\t ",' as ')] = Str::replace(' ', '', Str::between($section, ' as ', ' '));
             }, $viewDeclarations);
             $parentTable = Str::between($sqlView, 'FROM ', ';');

             $schema = explode('.', $parentTable)[0].'.';
             $dbViews[$parentTable] = [
                 'as' => $schema.$dbView->getName(),
                 'columns' => $viewColumns,
             ];
         }, $views);

         $tables = array_filter($tables, function ($dbTable) use ($dbViews) {
             return in_array($dbTable->getName(), array_keys($dbViews));
         });

        // Zorgt dat alle array keys bestaan
        array_map(function($dbTable) use (&$dbTables, $dbViews) {
            $dbTables[$dbViews[$dbTable->getName()]['as']] = $dbTable;
        }, $tables);

        foreach ($tables as $table) {
            $columns = $this->getEntityColumns($table->getName());
            $newColumns = [];
            array_map(function($column) use (&$dbTables, $dbViews, &$columns, $table, &$newColumns) {
                $viewColumnName = $dbViews[$table->getName()]['columns'][$column->getName()] ?? null;
                $translatedColumn = new Column($viewColumnName, $column->getType());
                $platformOptions = $column->getPlatformOptions();
                $values = $column->getValues();
                $column->setPlatformOptions([]);
                $column->setValues([]);
                $filteredColumns = array_filter($column->toArray(), function ($param, $key) {return $key !== 'name' && $key !== 'type' && $key !== 'default_constraint_name';}, ARRAY_FILTER_USE_BOTH);
                try {
                    $translatedColumn->setOptions([...$filteredColumns]);
                    $newColumns[$translatedColumn->getName()] = $translatedColumn;
                    $newColumns[$translatedColumn->getName()]->setPlatformOptions($platformOptions);
                    $newColumns[$translatedColumn->getName()]->setValues($values);
                } catch (\Exception $e) {
                    echo $e->getMessage();
                }
            }, $columns);

            $columns = $newColumns;

            $indexes = $this->getEntityIndexes($table->getName());
            $properties = [];
            $rules = [];

            $tableName = $dbViews[$table->getName()]['as'];
            $dbTable = new Table($tableName, dbEntityNameToModelName($tableName));
            if (isset($indexes['primary'])) {
                $primaryKeyName = $dbViews[$table->getName()]['columns'][$indexes['primary']->getColumns()[0]] ?? null;
                foreach ($columns as $column) {
                    if ($column->getName() == $primaryKeyName) {
                        $dbTable->primaryKey = new PrimaryKey($primaryKeyName, $column->getAutoincrement(), $this->laravelColumnType($this->mapColumnType($column->getType())));
                    }
                    break;
                }
            }

            foreach ($indexes as $index) {
                if (!$index->isPrimary() && $index->isUnique()) {
                    foreach ($index->getColumns() as $columnName) {
                        $rules[$columnName][] = 'unique:' . $dbTable->name;
                    };
                }
            }

            $dbTable->fillable = array_filter(
                array_diff(
                    array_keys($columns),
                    array_merge(
                        ['created_at', 'updated_at', 'deleted_at'],
                        $this->getArrayWithPrimaryKey($dbTable)
                    )
                ),
                static function (string $column): bool {
                    foreach (config('models-generator.exclude_columns', []) as $pattern) {
                        if (@preg_match($pattern, '') === false) {
                            $found = $pattern === $column;
                        } else {
                            $found = (bool) preg_match($pattern, $column);
                        }

                        if ($found) {
                            return false;
                        }
                    }

                    return true;
                }
            );
            if (in_array('password', $dbTable->fillable)) {
                $dbTable->hidden = ['password'];
            }

            $dbTable->timestamps = array_key_exists('created_at', $columns) && array_key_exists('updated_at', $columns);
            $dbTable->softDeletes = array_key_exists('deleted_at', $columns);

            /** @var Column $column */
            foreach ($columns as $column) {
                // TODO: Add $rules

                $laravelColumnType = $this->laravelColumnType($this->mapColumnType($column->getType()), $dbTable);
                $dbTable->casts[$column->getName()] = $this->laravelColumnTypeForCast($this->mapColumnType($column->getType()), $dbTable);

                $fieldType = match ($laravelColumnType) {
                    'integer', 'float' => 'numeric',
                    'boolean' => 'boolean',
                    default => 'string',
                };

                $rules[$column->getName()][] = $column->getNotnull() ? 'required' : 'nullable';
                $rules[$column->getName()][] = 'size:' . ($column->getLength() ?? $column->getPrecision());
                $rules[$column->getName()][] = $fieldType;

                if ($fieldType === 'numeric') {
                    $rules[$column->getName()][] = 'max_digits:' . $column->getScale();
                    $rules[$column->getName()][] = 'min_digits:' . ($column->getFixed() ? $column->getScale() : '0');
                }

                $properties[] = new Property(
                    '$' . $column->getName(),
                    ($this->typeColumnPropertyMaps[$laravelColumnType] ?? $laravelColumnType) . ($column->getNotnull() ? '' : '|null'),
                    comment: $column->getComment()
                ); // $laravelColumnType.($column->getNotnull() ? '' : '|null').' $'.$column->getName();

                // Get morph
                if (str_ends_with($column->getName(), '_model_type') && in_array(Str::replace('_model_type', '', $column->getName()) . '_id', array_keys($columns))) {
                    $dbTable->morphTo[] = new MorphTo(Str::replace('_model_type', '', $column->getName()));

                    $morphables[Str::replace('_model_type', '', $column->getName())] = $dbTable->className;
                }
            }
            $dbTable->rules = $rules;
            $dbTable->properties = $properties;

//            dd($dbTable);

//            $this->addForeignKeyConstraintIfNeeded($dbTable, $columns, $fks, $dbTables);

//            $fks = $table->getForeignKeys();
//
//            foreach ($fks as $fk) {
//                if (isRelationshipToBeAdded($dbTable->name, $fk->getForeignTableName())) {
//                    $dbTable->addBelongsTo(new BelongsTo($fk));
//                }
//            }

            $dbTables[$dbTable->name] = $dbTable;
        }

//        foreach ($dbTables as $dbTable) {
//            foreach ($dbTable->belongsTo as $foreignName => $belongsTo) {
//                $foreignTableName = $belongsTo->foreignKey->getForeignTableName();
//                $foreignKeyName = $belongsTo->foreignKey->getLocalColumns()[0];
//                $localKeyName = $belongsTo->foreignKey->getForeignColumns()[0];
//
//                if ($localKeyName == $dbTables[$foreignTableName]->primaryKey) {
//                    $localKeyName = null;
//                }
//                if (isRelationshipToBeAdded($dbTable->name, $foreignTableName)) {
//                    $dbTables[$foreignTableName]->addHasMany(new HasMany($dbTable->className, $foreignKeyName, $localKeyName));
//                }
//
//                $dbTable->rules[$foreignKeyName][] = 'exists:'.$foreignTableName.','.$localKeyName;
//
//                if (count($dbTable->belongsTo) > 1) {
//                    foreach ($dbTable->belongsTo as $subForeignName => $subBelongsTo) {
//                        $subForeignTableName = $subBelongsTo->foreignKey->getForeignTableName();
//
//                        if ($foreignTableName != $subForeignTableName) {
//                            if (isRelationshipToBeAdded($dbTable->name, $subForeignTableName)) {
//                                $tableIndexes = $this->getEntityIndexes($dbTables[$foreignTableName]->name);
//                                $relatedTableIndexes = $this->getEntityIndexes($subForeignTableName);
//                                $pivotIndexes = $this->getEntityIndexes($dbTable->name);
//
//                                $foreignPivotKey = $tableIndexes['primary']->getColumns()[0];
//                                $relatedPivotKey = $relatedTableIndexes['primary']->getColumns()[0];
//                                $pivotPrimaryKey = isset($pivotIndexes['primary']) ? $pivotIndexes['primary']->getColumns()[0] : null;
//
//                                $pivotColumns = $this->getEntityColumns($dbTable->name);
//                                $pivotTimestamps = array_key_exists('created_at', $pivotColumns) && array_key_exists('updated_at', $pivotColumns);
//                                $pivotAttributes = array_diff(
//                                    array_keys($pivotColumns),
//                                    array_merge(
//                                        [$foreignPivotKey, $relatedPivotKey, $pivotPrimaryKey],
//                                        $pivotTimestamps ? ['created_at', 'updated_at'] : []
//                                    )
//                                );
//
//                                $belongsToMany = new BelongsToMany(
//                                    $subForeignTableName,
//                                    $dbTable->name,
//                                    $foreignPivotKey,
//                                    $relatedPivotKey,
//                                    pivotAttributes: $pivotAttributes
//                                );
//                                $belongsToMany->timestamps = $pivotTimestamps;
//
//                                $dbTables[$foreignTableName]->addBelongsToMany($belongsToMany);
//                            }
//                        }
//                    }
//                }
//            }
//
//            // Morph many
//            foreach (config('models-generator.morphs') as $table => $relationship) {
//                if ($table == $dbTable->name) {
//                    $dbTable->morphMany[] = new MorphMany(
//                        NamingHelper::caseRelationName(Str::plural($morphables[$relationship])),
//                        $morphables[$relationship],
//                        $relationship,
//                    );
//                }
//            }
//        }

        return $dbTables;
    }

    public function laravelColumnTypeForCast(ColumnTypeEnum $type, ?Entity $dbTable = null): string
    {
        return match ($type) {
            ColumnTypeEnum::INT => 'integer',
            ColumnTypeEnum::DATETIME => 'datetime',
            ColumnTypeEnum::FLOAT => 'float',
            ColumnTypeEnum::BOOLEAN => 'boolean',
            default => 'string',
        };
    }

    public function laravelColumnType(ColumnTypeEnum $type, ?Entity $dbTable = null): string
    {
        if ($type == ColumnTypeEnum::DATETIME) {
            if ($dbTable !== null) {
                $dbTable->imports[] = 'Carbon\Carbon';
            }

            return 'datetime';
        }
        return match ($type) {
            ColumnTypeEnum::INT => 'integer',
            ColumnTypeEnum::FLOAT => 'float',
            ColumnTypeEnum::BOOLEAN => 'boolean',
            default => 'string',
        };
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function getEntityColumns(string $entityName): array
    {
        if (! isset(self::$entityColumns[$entityName])) {
            self::$entityColumns[$entityName] = $this->sm->listTableColumns($entityName);
        }

        return self::$entityColumns[$entityName];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function getEntityIndexes(string $entityName): array
    {
        if (! isset(self::$entityIndexes[$entityName])) {
            self::$entityIndexes[$entityName] = $this->sm->listTableIndexes($entityName);
        }

        return self::$entityIndexes[$entityName];
    }

    private function mapColumnType(Type $type): ColumnTypeEnum
    {
        if ($type instanceof SmallIntType ||
            $type instanceof BigIntType ||
            $type instanceof IntegerType
        ) {
            return ColumnTypeEnum::INT;
        }
        if ($type instanceof DateType ||
            $type instanceof DateTimeType ||
            $type instanceof DateImmutableType ||
            $type instanceof DateTimeImmutableType ||
            $type instanceof DateTimeTzType ||
            $type instanceof DateTimeTzImmutableType
        ) {
            return ColumnTypeEnum::DATETIME;
        }
        if ($type instanceof StringType ||
            $type instanceof TextType) {
            return ColumnTypeEnum::STRING;
        }
        if ($type instanceof DecimalType ||
            $type instanceof SmallFloatType ||
            $type instanceof FloatType
        ) {
            return ColumnTypeEnum::FLOAT;
        }
        if ($type instanceof BooleanType) {
            return ColumnTypeEnum::BOOLEAN;
        }

        return ColumnTypeEnum::STRING;
    }

    /**
     * @return list<string>
     */
    private function getArrayWithPrimaryKey(Table $dbTable): array
    {
        return $dbTable->primaryKey !== null ? (config('models-generator.primary_key_in_fillable', false) && ! empty($dbTable->primaryKey->name) ? [] : [$dbTable->primaryKey->name]) : [];
    }

    /**
     * Voegt een foreign key constraint toe indien nodig.
     *
     * @param Table $dbTable
     * @param Column[] $columns
     * @param array $fks
     * @param array $dbTables
     */
    private function addForeignKeyConstraintIfNeeded(Table $dbTable, array $columns, array $fks, array $dbTables): void
    {
//        echo "Foreign key contstraints disabled";
//        foreach ($columns as $column) {
//            $columnName = $column->getName();
//            $tableParts = explode('.', $dbTable->name);
//            if (count($tableParts) < 2) {
//                continue;
//            }
//            [$schema, $tableName] = $tableParts;
//
//            // Controleer of er al een foreign key constraint bestaat voor deze kolom.
//            $hasConstraint = false;
//            foreach ($fks as $fk) {
//                if (in_array($columnName, $fk->getLocalColumns())) {
//                    $hasConstraint = true;
//                    break;
//                }
//            }
//            if ($hasConstraint) {
//                continue;
//            }
//
//            // Bepaal de referentietabel aan de hand van de kolomnaam.
//            [$refTable, $refSchema] = $this->determineReferenceTable($schema, $columnName, $dbTables, $dbTable);
//            if (!$refTable || !$refSchema) {
//                continue;
//            }
//
//            $constraintName = 'fk_' . $tableName . '_' . $columnName;
//            $sql = "ALTER TABLE [{$schema}].[{$tableName}]
//                ADD CONSTRAINT [{$constraintName}]
//                FOREIGN KEY ([{$columnName}])
//                REFERENCES [{$refSchema}].[{$refTable}]([id])";
//
//            try {
//                DB::statement($sql);
//                echo "Constraint toegevoegd: {$tableName}.{$columnName} naar {$refSchema}.{$refTable}.id\n";
//            } catch (\Exception $e) {
//                echo "Fout bij constraint {$constraintName}: " . $e->getMessage() . "\n";
//                Log::error("Fout bij constraint {$constraintName}: " . $e->getMessage());
//            }
//        }
    }

    /**
     * Bepaalt de referentietabel gebaseerd op naamgevingsconventies.
     *
     * @param string $schema
     * @param string $columnName
     * @param array $dbTables
     * @return array|null Geeft de tabelnaam terug als deze gevonden is, anders null.
     */
    private function determineReferenceTable(string $schema, string $columnName, array $dbTables, Table $table): ?array
    {
        $searchableTables = [];
        foreach ($dbTables as $key => $dbTable) {
            $searchableTables[Str::after($key, '.')] = Str::before($key, '.');
        }

        if (preg_match('/^(.+?)_(\w+)_id$/', $columnName, $matches)) {
            $possibleTable = $matches[2];
            $pluralTable = Str::plural($possibleTable);

            if (isset($searchableTables[$pluralTable])) {
                $schema = $searchableTables[$pluralTable];
                return [$pluralTable, $schema];
            } elseif (isset($searchableTables[$possibleTable])) {
                $schema = $searchableTables[$possibleTable];
                return [$possibleTable, $schema];
            }
        }

        // Tweede patroon: [tabelnaam]_id
        if (preg_match('/^(.+?)_id$/', $columnName, $matches)) {
            $possibleTable = $matches[1];
            $pluralTable = Str::plural($possibleTable);

            if (isset($searchableTables[$pluralTable])) {
                $schema = $searchableTables[$pluralTable];
                return [$pluralTable, $schema];
            } elseif (isset($searchableTables[$possibleTable])) {
                $schema = $searchableTables[$possibleTable];
                return [$possibleTable, $schema];
            }
        }

        return null;
    }
}
