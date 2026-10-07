<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use SteelAnts\LaravelBoilerplate\Models\Setting;
use SteelAnts\LaravelBoilerplate\Types\SettingDataType;

/**
 * Languages of the user interface (config mdm.locales, translations in lang/*.json). Each user has
 * their own (setting profile.locale); the one picked on the setup page is also the default for users
 * without one and for the pages before signing in (setting main.general.locale). Only web requests
 * switch the language: logs, the scheduler and the queue stay in English (APP_LOCALE).
 */
class Locales
{
    public const USER_SETTING = 'profile.locale';

    public const SYSTEM_SETTING = 'main.general.locale';

    /** Code => name in its own language. */
    public static function available(): array
    {
        return config('mdm.locales', ['en' => 'English']);
    }

    public static function isValid(?string $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::available());
    }

    /** The default of users without a language of their own, or null before the setup. */
    public static function system(): ?string
    {
        // Not settings(): it keeps what it read once, also after the setup saved the language.
        $locale = Setting::query()->whereNull('settable_type')->whereNull('settable_id')->where('index', self::SYSTEM_SETTING)->value('value');

        return self::isValid($locale) ? $locale : null;
    }

    /** The language the user picked in the profile, or null. */
    public static function ofUser(User $user): ?string
    {
        $locale = $user->getSettings(self::USER_SETTING);

        return self::isValid($locale) ? $locale : null;
    }

    /** The language of a web request: the user's, the one picked before signing in, the system's, the browser's. */
    public static function forRequest(Request $request): string
    {
        if ($user = $request->user()) {
            return self::ofUser($user) ?? self::system() ?? config('app.locale');
        }

        $picked = $request->hasSession() ? $request->session()->get('locale') : null;

        return (self::isValid($picked) ? $picked : null)
            ?? self::system()
            ?? $request->getPreferredLanguage(array_keys(self::available()))
            ?? config('app.locale');
    }

    /** Runs the callback in English (alerts, notifications), then switches back to the language before. */
    public static function inEnglish(callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale('en');
        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    public static function setForUser(User $user, string $locale): void
    {
        $user->settings()->updateOrCreate(
            ['index' => self::USER_SETTING],
            ['value' => $locale, 'type' => SettingDataType::STRING],
        );
        $user->unsetRelation('settings');
    }

    public static function setSystem(string $locale): void
    {
        Setting::query()->updateOrCreate(
            ['index' => self::SYSTEM_SETTING, 'settable_id' => null, 'settable_type' => null],
            ['value' => $locale, 'type' => SettingDataType::STRING],
        );
    }
}
