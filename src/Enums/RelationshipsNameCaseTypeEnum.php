<?php

declare(strict_types=1);

namespace TimoCuijpers\LaravelModelsGenerator\Enums;

enum RelationshipsNameCaseTypeEnum: string
{
    case CAMEL_CASE = 'camel_case';

    case SNAKE_CASE = 'snake_case';
}
