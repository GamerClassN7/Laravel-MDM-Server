<?php

namespace App\Support;

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\ScriptRun;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What needs attention on a device, most severe first, each with the action that fixes it where
 * there is one (restart, update the agent, install the updates, retry a failed command).
 *
 * An alert: key, severity (danger, warning, info, secondary), icon, title, message, action
 * (command, params, label, icon, confirm) or null, active (the command of the action on its way)
 * and tab (the device tab with the details).
 */
class SmartAlerts
{
    public const SEVERITIES = ['danger' => 0, 'warning' => 1, 'info' => 2, 'secondary' => 3];

    /** Drives this full (percent) raise a warning, from DISK_DANGER on an error. */
    public const DISK_WARNING = 90;

    public const DISK_DANGER = 95;

    /** Failed commands are shown this long (seconds), unless the command ran again since. */
    public const FAILED_COMMAND_WINDOW = 86400;

    /**
     * @param  Collection<int, DeviceCommand>|null  $commands  the device's commands of the last day (loaded when null)
     */
    public static function for(Device $device, ?Collection $commands = null): array
    {
        if (empty($device->data)) {
            return [];
        }
        $commands ??= self::recentCommands($device);
        $active = $commands->filter->active;
        $alerts = [];

        if ($device->offline) {
            $alerts[] = [
                'key' => 'offline',
                'severity' => 'secondary',
                'icon' => 'fas fa-plug',
                'title' => __('Device is offline'),
                'message' => __('Last seen :time', ['time' => ($device->last_seen_at ?? $device->updated_at)?->diffForHumans()]),
            ];
        }

        if ($device->restartPending) {
            $alerts[] = [
                'key' => 'restart',
                'severity' => 'warning',
                'icon' => 'fas fa-redo',
                'title' => __('Restart required'),
                'message' => __('Installed updates take effect after a restart.'),
                'action' => ['command' => 'restart', 'label' => __('Restart'), 'icon' => 'fas fa-redo', 'confirm' => __('Restart :device now? Unsaved work of its users is lost.', ['device' => $device->displayName])],
            ];
        }

        if ($device->agentOutdated) {
            $canUpdate = $device->agentUpdatable && self::remoteUpdateAvailable();
            $alerts[] = [
                'key' => 'agent',
                'severity' => $device->signsRequests ? 'warning' : 'danger',
                'icon' => 'fas fa-robot',
                'title' => __('A newer agent is available'),
                'message' => __(':current → :latest', ['current' => $device->agentVersion ?? __('unknown'), 'latest' => AgentScript::version()]).' · '.match (true) {
                    ! $device->agentUpdatable => __('This agent cannot update itself, reinstall it with the command from Add device.'),
                    ! self::remoteUpdateAvailable() => __('Remote update needs the portal on HTTPS.'),
                    ! $device->signsRequests => __('The agent does not sign its communication, only the agent update can be sent to it.'),
                    default => __('The agent updates itself and restarts, the device keeps running.'),
                },
                'action' => $canUpdate ? ['command' => 'updateAgent', 'label' => __('Update agent'), 'icon' => 'fas fa-download'] : null,
                'copy' => $device->agentUpdatable ? InstallCommands::update($device) : null,
            ];
        }

        $os = count($device->installableUpdates);
        $apps = count($device->apps_packages_updates);
        $modules = count($device->moduleUpdates);
        if ($os + $apps + $modules > 0) {
            $alerts[] = [
                'key' => 'updates',
                'severity' => $os > 0 ? 'warning' : 'info',
                'icon' => 'fas fa-sync',
                'title' => trans_choice(':count update available|:count updates available', $os + $apps + $modules),
                'message' => collect([
                    $os ? __(':count operating system', ['count' => $os]) : null,
                    $apps ? __(':count applications', ['count' => $apps]) : null,
                    $modules ? __(':count PowerShell modules', ['count' => $modules]) : null,
                ])->filter()->implode(', '),
                'action' => ['command' => 'doUpdates', 'label' => __('Install all'), 'icon' => 'fas fa-download', 'confirm' => __('Install all updates on :device?', ['device' => $device->displayName])],
                'tab' => 'updates',
            ];
        }

        $full = collect($device->drives)->filter(fn ($drive) => ($drive['PercentUsed'] ?? 0) >= self::DISK_WARNING);
        if ($full->isNotEmpty()) {
            $alerts[] = [
                'key' => 'disk_space',
                'severity' => $full->max('PercentUsed') >= self::DISK_DANGER ? 'danger' : 'warning',
                'icon' => 'fas fa-hdd',
                'title' => __('Low disk space'),
                'message' => $full->map(fn ($drive) => __(':drive :percent % full, :free free', ['drive' => $drive['DriveLetter'] ?? $drive['FriendlyName'] ?? '?', 'percent' => $drive['PercentUsed'], 'free' => Bytes::format($drive['SizeRemaining'] ?? 0)]))->implode(', '),
                'tab' => 'drives',
            ];
        }

        if ($device->showDiskHealth && $device->diskHealthProblem) {
            $alerts[] = [
                'key' => 'disk_health',
                'severity' => 'danger',
                'icon' => 'fas fa-heartbeat',
                'title' => __('A disk reports a problem'),
                'message' => collect($device->diskHealth['disks'])->filter(fn ($disk) => in_array($disk['Health'] ?? null, ['failed', 'warning'], true))->map(fn ($disk) => $disk['Model'] ?? $disk['Device'] ?? '?')->implode(', '),
                'tab' => 'health',
            ];
        }

        $failedServices = collect($device->services)->where('State', 'failed');
        if ($failedServices->isNotEmpty()) {
            $alerts[] = [
                'key' => 'services',
                'severity' => 'warning',
                'icon' => 'fas fa-cogs',
                'title' => trans_choice(':count service failed|:count services failed', $failedServices->count()),
                'message' => $failedServices->pluck('Name')->take(5)->implode(', ').($failedServices->count() > 5 ? ' …' : ''),
                'tab' => 'services',
            ];
        }

        $failedScripts = $device->scriptRuns()->with('script')
            ->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->where('device_id', $device->id)->groupBy('script_id'))
            ->whereIn('status', ['failed', 'error', 'rejected'])->get();
        if ($failedScripts->isNotEmpty()) {
            $alerts[] = [
                'key' => 'scripts',
                'severity' => 'danger',
                'icon' => 'fas fa-scroll',
                'title' => trans_choice(':count remediation failed|:count remediations failed', $failedScripts->count()),
                'message' => $failedScripts->map(fn ($run) => $run->script?->name)->filter()->implode(', '),
                'tab' => 'scripts',
            ];
        }

        // The last run of each command (and target) that failed, unless it ran again since.
        foreach ($commands->groupBy(fn ($command) => $command->command.'|'.$command->target) as $runs) {
            $last = $runs->sortByDesc('id')->first();
            if (! in_array($last->status, ['failed', 'expired'], true) || in_array($last->command, DeviceCommand::UNTRACKED, true)) {
                continue;
            }
            $failure = ($last->message ?: $last->statusLabel).' · '.$last->updated_at->diffForHumans();
            // An alert with the same action (e.g. the agent is still outdated) tells it there.
            foreach ($alerts as &$alert) {
                if (($alert['action']['command'] ?? null) === $last->command && ($alert['action']['params'] ?? []) == ($last->params ?? [])) {
                    $alert['failure'] = $failure;

                    continue 2;
                }
            }
            unset($alert);
            $alerts[] = [
                'key' => 'failed:'.$last->id,
                'severity' => 'danger',
                'icon' => $last->icon,
                'title' => __(':command failed', ['command' => $last->label]),
                'message' => ($last->message ?: $last->statusLabel).' · '.$last->updated_at->diffForHumans(),
                'action' => ['command' => $last->command, 'params' => $last->params ?? [], 'label' => __('Try again'), 'icon' => 'fas fa-redo'],
                'tab' => 'history',
            ];
        }

        foreach ($alerts as &$alert) {
            $alert += ['message' => null, 'action' => null, 'tab' => null, 'copy' => null, 'failure' => null, 'refusal' => null];
            $alert['active'] = null;
            if ($alert['action']) {
                $alert['action'] += ['params' => [], 'confirm' => null];
                // The command on its way (also a doUpdates for a single update).
                $alert['active'] = Device::findActive($active, $alert['action']['command'], $alert['action']['params'] ?: null)
                    ?? ($alert['action']['command'] === 'installUpdate' ? Device::findActive($active, 'doUpdates') : null);
                $alert['refusal'] = $device->commandRefusal($alert['action']['command'], $alert['action']['params']);
            }
        }
        unset($alert);

        // Offline first: it is why no action can run now. Then the most severe.
        usort($alerts, fn ($a, $b) => [$a['key'] !== 'offline', self::SEVERITIES[$a['severity']]] <=> [$b['key'] !== 'offline', self::SEVERITIES[$b['severity']]]);

        return $alerts;
    }

    /** The device's commands of the last day (active ones are never older). */
    public static function recentCommands(Device $device): Collection
    {
        DeviceCommand::expireStale($device->id);

        return $device->commands()->where('updated_at', '>=', now()->subSeconds(self::FAILED_COMMAND_WINDOW))->orderBy('id')->get();
    }

    /**
     * Runs the action of the device's alert. The alert is looked up again, so only an action the
     * device needs right now runs; a command already on its way is not sent twice. Returns the
     * reason when it did not run, null when the command was queued.
     */
    public static function run(Device $device, string $key, ?User $user = null): ?string
    {
        $alert = collect(self::for($device))->firstWhere('key', $key);
        if ($alert === null || $alert['action'] === null) {
            return __('Nothing to do anymore.');
        }
        if ($alert['active']) {
            return __('Already on its way.');
        }
        if ($alert['refusal']) {
            return $alert['refusal'];
        }

        return $device->issueCommand($alert['action']['command'], $alert['action']['params'], $user) ? null : __('Already on its way.');
    }

    /** Agents refuse remote updates over plain HTTP (except localhost). */
    public static function remoteUpdateAvailable(): bool
    {
        return str_starts_with(url('/'), 'https://') || in_array(parse_url(url('/'), PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true);
    }
}
