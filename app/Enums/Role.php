<?php

namespace App\Enums;

enum Role: int
{
    case Root = 80;
    case Admin = 50;
    case Developer = 20;

    public function hasAccess(Role $role): bool
    {
        return $this->value >= $role->value;
    }
}
