<?php

namespace App\Types;

/**
 * Permission levels the boilerplate dashboard views check (auth()->user()->permission).
 * System admins (APP_SYSTEM_ADMINS) may edit every dashboard, also shared ones.
 */
enum PermissionType: string
{
    case ADMIN = 'admin';
    case USER = 'user';
}
