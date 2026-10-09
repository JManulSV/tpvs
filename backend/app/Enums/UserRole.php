<?php

namespace App\Enums;

enum UserRole: string
{
    case EDITOR = 'EDITOR';
    case VIEWER = 'VIEWER';
}
