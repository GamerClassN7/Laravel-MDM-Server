<?php

namespace App\Livewire\Notifications;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\NotificationSetting;
use App\Support\Notifier;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * The user's notifications, as in Beszel: where to send them (e-mail addresses, push / webhook
 * URLs, each with a test) and the alert rules with the recent alerts.
 */
class Page extends Component
{
    /** alerts, channels or history */
    #[Url(except: 'alerts')]
    public string $tab = 'alerts';

    public string $emails = '';

    /** @var array<int, string> */
    public array $urls = [];

    /** Result of the last test per URL index (or 'email'): ['ok' => bool, 'message' => string]. */
    public array $tests = [];

    public function mount(): void
    {
        $settings = NotificationSetting::for(auth()->user());
        $this->emails = implode("\n", $settings->emailList);
        $this->urls = $settings->urlList ?: [''];
    }

    public function addUrl(): void
    {
        if (count($this->urls) < NotificationSetting::MAX_CHANNELS) {
            $this->urls[] = '';
        }
    }

    public function removeUrl(int $index): void
    {
        unset($this->urls[$index], $this->tests[$index]);
        $this->urls = array_values($this->urls) ?: [''];
        $this->tests = [];
    }

    public function save(): void
    {
        $emails = $this->emailList();
        $urls = array_values(array_filter(array_map('trim', $this->urls), fn ($url) => $url !== ''));
        $this->resetErrorBag();

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('emails', __(':email is not an e-mail address.', ['email' => $email]));
            }
        }
        foreach ($this->urls as $index => $url) {
            if (trim($url) !== '' && ! Notifier::validUrl(trim($url))) {
                $this->addError("urls.$index", __('Unsupported notification URL.'));
            }
        }
        if (count($emails) > NotificationSetting::MAX_CHANNELS || count($urls) > NotificationSetting::MAX_CHANNELS) {
            $this->addError('emails', __('At most :count of each.', ['count' => NotificationSetting::MAX_CHANNELS]));
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $settings = NotificationSetting::for(auth()->user());
        $settings->fill(['emails' => $emails, 'urls' => $urls])->save();
        // Alerts that picked a channel that is gone: they keep the others, or send to all again.
        $available = array_keys($settings->channelOptions);
        foreach (AlertRule::query()->where('user_id', auth()->id())->whereNotNull('channels')->get() as $rule) {
            $kept = array_values(array_intersect($rule->channels, $available));
            $rule->update(['channels' => $kept === [] ? null : $kept]);
        }
        $this->urls = $urls ?: [''];
        $this->tests = [];
        alert()->success(__('Saved'))->now();
    }

    public function testUrl(int $index): void
    {
        $url = trim($this->urls[$index] ?? '');
        try {
            if (! Notifier::validUrl($url)) {
                throw new \InvalidArgumentException(__('Unsupported notification URL.'));
            }
            Notifier::send($url, '🔔 '.config('app.name'), __('Test notification from :app.', ['app' => config('app.name')]));
            $this->tests[$index] = ['ok' => true, 'message' => __('Sent')];
        } catch (Throwable $e) {
            $this->tests[$index] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function testEmail(): void
    {
        $emails = array_filter($this->emailList(), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL));
        try {
            if ($emails === []) {
                throw new \InvalidArgumentException(__('Enter an e-mail address.'));
            }
            Mail::raw(__('Test notification from :app.', ['app' => config('app.name')]), fn ($mail) => $mail->to($emails)->subject('🔔 '.config('app.name')));
            $this->tests['email'] = ['ok' => true, 'message' => __('Sent')];
        } catch (Throwable $e) {
            $this->tests['email'] = ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function addRule(): void
    {
        $this->dispatch('openModal', 'notifications.rule-form', __('Add alert'), [], 'lg');
    }

    public function editRule(int $ruleId): void
    {
        $this->dispatch('openModal', 'notifications.rule-form', __('Edit alert'), ['ruleId' => $ruleId], 'lg');
    }

    public function toggleRule(int $ruleId): void
    {
        $rule = AlertRule::query()->where('user_id', auth()->id())->findOrFail($ruleId);
        $rule->update(['enabled' => ! $rule->enabled]);
    }

    public function deleteRule(int $ruleId): void
    {
        AlertRule::query()->where('user_id', auth()->id())->whereKey($ruleId)->delete();
    }

    #[On('alertRuleSaved')]
    public function refresh(): void {}

    /** @return array<int, string> */
    private function emailList(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $this->emails)))));
    }

    public function render()
    {
        $rules = AlertRule::query()->where('user_id', auth()->id())->orderBy('type')->orderBy('id')->get();
        $settings = NotificationSetting::for(auth()->user());
        $events = AlertEvent::query()->with(['rule', 'device'])->whereIn('alert_rule_id', $rules->pluck('id'));

        return view('livewire.notifications.page', [
            'rules' => $rules,
            'firing' => (clone $events)->whereNull('resolved_at')->latest('triggered_at')->get(),
            'events' => $this->tab === 'history' ? (clone $events)->latest('triggered_at')->limit(100)->get() : collect(),
            'channelOptions' => $settings->channelOptions,
            'hasChannels' => $settings->hasChannels,
        ])->title(__('Notifications'));
    }
}
