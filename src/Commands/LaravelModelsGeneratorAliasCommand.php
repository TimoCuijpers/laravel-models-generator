<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Commands;

use Illuminate\Console\Command;

class LaravelModelsGeneratorAliasCommand extends Command
{
    public $signature = 'models:generate
                        {--s|schema= : The name of the database}
                        {--c|connection= : The name of the connection}
                        {--t|table= : The name of the table}
                        {--e|typescript : Generate typescript models}';

    protected $description = 'Dit is het kind command';

    public function handle()
    {
        $this->call('laravel-models-generator:generate', [
            '--schema' => $this->option('schema'),
            '--connection' => $this->option('connection'),
            '--table' => $this->option('table'),
            '--typescript' => $this->option('typescript'),
        ]);
    }
}
