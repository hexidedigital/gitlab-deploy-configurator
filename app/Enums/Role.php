<?php

namespace App\Enums;

enum Role: int
{
    case Root = 80;
    case Admin = 50;
    case Developer = 20;
}
