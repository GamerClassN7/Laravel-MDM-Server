# Dashboard

## Dashboard

`/dashboard` (menu **Dashboard**) is a configurable dashboard from
[steelants/laravel-boilerplate.dashboard](https://packistry.sa-dev.cz/public): every user can create
their own dashboards, share them, and add, resize and move widgets in the editor. A shared
**Overview** dashboard is created by a migration with the **Devices** widget (online and offline
devices right now, the offline ones listed, refreshed every 30 seconds). Owners edit their
dashboards; system admins (`APP_SYSTEM_ADMINS`) can edit every dashboard
(`App\Policies\DashboardPolicy`).

New widgets are Blade components in `app/View/Components/Widgets` and show up in the editor
automatically. The package comes from the `packistry` Composer repository configured in
`composer.json`.
