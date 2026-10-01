<?php

namespace App\Livewire;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\Enrolment;
use App\Models\ScriptRun;
use App\Support\Bytes;
use App\Support\InstallCommands;
use App\Support\SmartAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The devices page: the fleet overview (a table with the state of every device, filters and bulk
 * actions), or the detail of one device (?selectedDeviceId=), or the enrolment of a new one.
 */
class ShowDevices extends Component
{
    public $devices;

    #[Url]
    public $selectedDeviceId;

    /** Shows only the devices with this tag. */
    #[Url(except: '')]
    public string $tag = '';

    #[Url(except: '')]
    public string $search = '';

    /** '' (all), 'attention' or 'offline' */
    #[Url(except: '')]
    public string $status = '';

    /** Devices ticked for a bulk action. @var array<int, string> */
    public array $selected = [];

    public string $bulkTag = '';

    /* Device Enrolment*/
    public $addDevice = false;
    public $enrollmentCode;
    public $enrollmentCodeExpiration;

    /** Short labels of the smart alerts in the "Attention" column (updates have their own column). */
    private const FLAGS = [
        'restart' => 'Restart',
        'agent' => 'Agent update',
        'disk_space' => 'Disk',
        'disk_health' => 'S.M.A.R.T.',
        'services' => 'Services',
        'scripts' => 'Remediation',
    ];

    public function selectDevice($id)
    {
        $this->selectedDeviceId = $id ?: null;
        $this->addDevice = false;
    }

    public function showList(): void
    {
        $this->selectedDeviceId = null;
        $this->addDevice = false;
    }

    public function updatedAddDevice($value)
    {
        if ($value) {
            Enrolment::Where('expire_at', '<', CarbonImmutable::now())->delete();

            $this->enrollmentCode = mt_rand(1000, 9999);
            $this->enrollmentCodeExpiration = CarbonImmutable::now()->add(15, 'min');

            $enrolment = new Enrolment();
            $enrolment->code = $this->enrollmentCode;
            $enrolment->expire_at = $this->enrollmentCodeExpiration;
            $enrolment->save();
        }
    }

    #[On('device-deleted')]
    public function deviceDeleted()
    {
        $this->loadDevices();
        $this->forgetMissingDevice();
    }

    #[On('device-tags-changed')]
    public function loadDevices(): void
    {
        $this->devices = Device::all();
    }

    public function filterTag(string $tag): void
    {
        $this->tag = $this->tag === $tag ? '' : $tag;
        $this->selected = [];
    }

    public function filterStatus(string $status): void
    {
        $this->status = in_array($status, ['attention', 'offline'], true) && $this->status !== $status ? $status : '';
        $this->selected = [];
    }

    public function mount()
    {
        $this->loadDevices();
        $this->forgetMissingDevice();
    }

    /** A link to a deleted device shows the overview instead of an empty page. */
    private function forgetMissingDevice(): void
    {
        if ($this->selectedDeviceId !== null && ! $this->devices->contains('id', (int) $this->selectedDeviceId)) {
            $this->selectedDeviceId = null;
        }
    }

    // Bulk actions on the ticked devices.

    /** @return Collection<int, Device> */
    private function selectedDevices(): Collection
    {
        return Device::query()->whereIn('id', array_map('intval', $this->selected))->get();
    }

    public function bulkCommand(string $command): void
    {
        if (! in_array($command, ['doUpdates', 'restart'], true)) {
            return;
        }
        $sent = $this->selectedDevices()->filter(fn (Device $device) => $device->issueCommand($command, [], auth()->user()) !== null)->count();
        $skipped = count($this->selected) - $sent;
        alert()->success(trans_choice('Sent to :count device|Sent to :count devices', $sent, ['count' => $sent])
            .($skipped > 0 ? ' · '.trans_choice(':count skipped (offline or already on its way)|:count skipped (offline or already on its way)', $skipped, ['count' => $skipped]) : ''))->now();
    }

    public function bulkTagChange(bool $add): void
    {
        $tags = Device::normalizeTags($this->bulkTag);
        if ($tags === []) {
            $this->addError('bulkTag', __('Enter a tag.'));

            return;
        }
        foreach ($this->selectedDevices() as $device) {
            $current = $device->tagList;
            $new = $add
                ? Device::normalizeTags(array_merge($current, $tags))
                : array_values(array_filter($current, fn ($tag) => ! in_array(mb_strtolower($tag), array_map('mb_strtolower', $tags), true)));
            $device->tags = $new === [] ? null : $new;
            $device->save();
        }
        $this->bulkTag = '';
        $this->loadDevices();
    }

    public function bulkRunScript(): void
    {
        Gate::authorize('is-system-admin');
        $this->dispatch('openModal', 'script.pick', __('Run a script'), ['deviceIds' => array_map('intval', $this->selected)], 'lg');
    }

    /**
     * One row of the overview: the device and what the table shows about it.
     */
    private function row(Device $device, Collection $firing): array
    {
        $alerts = empty($device->data) ? [] : SmartAlerts::for($device);
        $flags = [];
        foreach ($alerts as $alert) {
            $key = str_starts_with($alert['key'], 'failed:') ? 'failed' : $alert['key'];
            if ($key === 'failed') {
                $flags[] = ['label' => __('Failed command'), 'severity' => 'danger', 'title' => $alert['title']];
            } elseif (isset(self::FLAGS[$key])) {
                $label = $key === 'disk_space' ? __('Disk :percent %', ['percent' => collect($device->drives)->max('PercentUsed')]) : __(self::FLAGS[$key]);
                $flags[] = ['label' => $label, 'severity' => $alert['severity'] === 'secondary' ? 'info' : $alert['severity'], 'title' => $alert['title'].($alert['message'] ? ': '.$alert['message'] : '')];
            }
        }
        foreach ($firing->get($device->id, collect()) as $event) {
            $flags[] = ['label' => __('Alert: :rule', ['rule' => $event->rule->label]), 'severity' => 'danger', 'title' => $event->message];
        }
        $flags = collect($flags)->unique('label')->sortBy(fn ($flag) => SmartAlerts::SEVERITIES[$flag['severity']] ?? 9)->values()->all();

        $drive = collect($device->drives)->filter(fn ($drive) => isset($drive['PercentUsed']))->sortByDesc('PercentUsed')->first();
        $updates = count($device->installableUpdates) + count($device->apps_packages_updates) + count($device->moduleUpdates);
        $addresses = collect($device->networks)->where('Connected', true)->whereIn('Type', ['lan', 'wifi', 'bridge'])->pluck('IPAddresses')->flatten()
            ->first(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4));

        return [
            'device' => $device,
            'flags' => $flags,
            'attention' => $device->offline || collect($flags)->contains(fn ($flag) => in_array($flag['severity'], ['danger', 'warning'], true)),
            'updates' => $updates,
            'drive' => $drive ? ['percent' => (int) $drive['PercentUsed'], 'free' => Bytes::format($drive['SizeRemaining'] ?? 0)] : null,
            'ip' => $addresses,
            'searchText' => mb_strtolower(implode(' ', [$device->displayName, $device->name, $device->os, $addresses, implode(' ', $device->tagList)])),
        ];
    }

    public function render()
    {
        $selectedDevice = $this->selectedDeviceId ? Device::find($this->selectedDeviceId) : null;
        $view = ['selectedDevice' => $selectedDevice, 'tags' => Device::allTags()];

        if ($this->addDevice) {
            return view('livewire.show-devices', $view + [
                'installCommands' => $this->enrollmentCode ? InstallCommands::for($this->enrollmentCode) : [],
            ]);
        }
        if ($selectedDevice) {
            return view('livewire.show-devices', $view);
        }

        // The user's open alerts per device ("Alert: Status" in the Attention column).
        $firing = AlertEvent::query()->with('rule')->whereNull('resolved_at')
            ->whereIn('alert_rule_id', AlertRule::query()->where('user_id', auth()->id())->select('id'))
            ->get()->groupBy('device_id');

        $rows = $this->devices->sortBy(fn (Device $device) => mb_strtolower($device->displayName))->map(fn (Device $device) => $this->row($device, $firing))->values();
        $search = mb_strtolower(trim($this->search));
        $visible = $rows->filter(fn (array $row) => ($this->tag === '' || $row['device']->hasTag($this->tag))
            && ($search === '' || str_contains($row['searchText'], $search))
            && match ($this->status) {
                'attention' => $row['attention'],
                'offline' => $row['device']->offline,
                default => true,
            })->values();

        $latestRuns = ScriptRun::query()->whereIn('id', ScriptRun::query()->selectRaw('max(id)')->groupBy('script_id', 'device_id'))->pluck('status');
        $pendingDevices = $rows->where('updates', '>', 0);

        return view('livewire.show-devices', $view + [
            'rows' => $visible,
            'counts' => [
                'all' => $rows->count(),
                'online' => $rows->filter(fn ($row) => ! $row['device']->offline)->count(),
                'offline' => $rows->filter(fn ($row) => $row['device']->offline)->count(),
                'attention' => $rows->where('attention', true)->count(),
                'updates' => $pendingDevices->sum('updates'),
                'updateDevices' => $pendingDevices->count(),
                'firing' => $firing->flatten()->count(),
                'compliant' => $latestRuns->whereIn('status', ['compliant', 'remediated'])->count(),
                'runs' => $latestRuns->whereNotIn('status', ['pending', 'sent', 'expired', 'superseded'])->count(),
            ],
        ]);
    }
}
