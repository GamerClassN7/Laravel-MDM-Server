<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use SteelAnts\LaravelBoilerplate\Dashboard\Models\Dashboard;
use SteelAnts\LaravelBoilerplate\Dashboard\Policies\DashboardPolicy as BaseDashboardPolicy;

/**
 * The package lets only the owner edit a dashboard. System admins (APP_SYSTEM_ADMINS) may edit
 * every one, also the shared default dashboard, which has no owner on a fresh installation.
 */
class DashboardPolicy extends BaseDashboardPolicy
{
    public function view(Authenticatable $user, Dashboard $dashboard): bool
    {
        return parent::view($user, $dashboard) || $this->isSystemAdmin($user);
    }

    public function update(Authenticatable $user, Dashboard $dashboard): bool
    {
        return parent::update($user, $dashboard) || $this->isSystemAdmin($user);
    }

    private function isSystemAdmin(Authenticatable $user): bool
    {
        return (bool) ($user->isSystemAdmin ?? false);
    }
}
