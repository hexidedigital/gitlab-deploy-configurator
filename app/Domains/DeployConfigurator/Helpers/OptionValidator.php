<?php

declare(strict_types=1);

namespace App\Domains\DeployConfigurator\Helpers;

use App\Domains\DeployConfigurator\DeploymentOptions\Options\BaseOption;

final class OptionValidator
{
    public static function onyOfKeyIsEmpty(BaseOption $option): bool
    {
        return collect($option->toArray())
            ->reject()
            ->except($option->allowEmptyValueForArrayKeys())
            ->isNotEmpty();
    }
}
