<?php

namespace App\Support;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Sends notifications to e-mail addresses and to push / webhook services given as URLs in the
 * style of Shoutrrr (as in Beszel):
 *
 *   ntfy://[user:password@]host/topic        ntfy (a token: ntfy://:token@host/topic)
 *   discord://token@webhookid                Discord webhook
 *   telegram://token@telegram?chats=@c,123   Telegram bot
 *   gotify://host[/path]/token               Gotify
 *   slack://hook:T000-B000-XXXX@webhook      Slack webhook
 *   pushover://shoutrrr:apiToken@userKey     Pushover
 *   generic://host/path                      JSON POST {title, message} (generic+http:// without TLS)
 *
 * ?disabletls=yes uses http instead of https (ntfy, gotify, generic).
 */
class Notifier
{
    public const SCHEMES = ['ntfy', 'discord', 'telegram', 'gotify', 'slack', 'pushover', 'generic', 'generic+http', 'generic+https'];

    /** Examples for the settings page. */
    public const EXAMPLES = [
        'ntfy' => 'ntfy://ntfy.sh/my-mdm-alerts',
        'Discord' => 'discord://token@webhookid',
        'Telegram' => 'telegram://123456:ABC-token@telegram?chats=@channel,123456789',
        'Gotify' => 'gotify://gotify.example.com/AppToken',
        'Slack' => 'slack://hook:T000-B000-XXXX@webhook',
        'Pushover' => 'pushover://shoutrrr:apiToken@userKey',
        'Webhook' => 'generic://example.com/hook',
    ];

    public static function validUrl(string $url): bool
    {
        try {
            self::request($url, 'test', 'test');

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Sends to one URL; throws with the reason when it did not work. */
    public static function send(string $url, string $title, string $message): void
    {
        [$method, $target, $payload, $options] = self::request($url, $title, $message);

        // Telegram: the same message to every chat.
        foreach (array_slice($options['chats'] ?? [], 1) as $chat) {
            self::deliver($method, $target, ['chat_id' => $chat] + $payload, $options);
        }
        self::deliver($method, $target, $payload, $options);
    }

    private static function deliver(string $method, string $target, mixed $payload, array $options): void
    {

        /** @var PendingRequest $http */
        $http = Http::timeout(10)->withHeaders($options['headers'] ?? []);
        if (isset($options['basic'])) {
            $http = $http->withBasicAuth(...$options['basic']);
        }
        if (isset($options['token'])) {
            $http = $http->withToken($options['token']);
        }

        /** @var Response $response */
        $response = match ($options['body'] ?? 'json') {
            'raw' => $http->withBody($payload, 'text/plain; charset=utf-8')->send($method, $target),
            'form' => $http->asForm()->send($method, $target, ['form_params' => $payload]),
            default => $http->send($method, $target, ['json' => $payload]),
        };

        if (! $response->successful()) {
            throw new \RuntimeException(__('The service answered :status: :body', ['status' => $response->status(), 'body' => mb_strimwidth(trim($response->body()), 0, 200, '…')]));
        }
    }

    /**
     * Sends to all channels of the user. Returns the errors per channel (empty when everything
     * was delivered); a failing channel does not stop the others.
     *
     * @return array<string, string>
     */
    public static function notify(User $user, string $title, string $message, ?array $only = null): array
    {
        $settings = NotificationSetting::for($user);
        $errors = [];
        // Only these channels ("email" or a URL) when the alert picked some.
        $allowed = fn (string $channel) => $only === null || in_array($channel, $only, true);

        if ($settings->emailList !== [] && $allowed('email')) {
            try {
                Mail::raw($message, fn ($mail) => $mail->to($settings->emailList)->subject($title));
            } catch (Throwable $e) {
                $errors['email'] = $e->getMessage();
            }
        }
        foreach (array_filter($settings->urlList, $allowed) as $url) {
            try {
                self::send($url, $title, $message);
            } catch (Throwable $e) {
                $errors[self::redact($url)] = $e->getMessage();
            }
        }

        foreach ($errors as $channel => $error) {
            Log::warning("Notification to {$channel} for user {$user->id} failed: {$error}");
        }

        return $errors;
    }

    /** "ntfy", "Discord", … for a notification URL. */
    public static function serviceName(string $url): string
    {
        return match (strtolower((string) parse_url($url, PHP_URL_SCHEME))) {
            'ntfy' => 'ntfy',
            'discord' => 'Discord',
            'telegram' => 'Telegram',
            'gotify' => 'Gotify',
            'slack' => 'Slack',
            'pushover' => 'Pushover',
            default => __('Webhook'),
        };
    }

    /** The URL without its secrets, for logs and the settings page. */
    public static function redact(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'])) {
            return '?';
        }
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';
        if (in_array($parts['scheme'], ['discord', 'telegram', 'slack', 'pushover'], true)) {
            return $parts['scheme'].'://…@'.$host;
        }
        if ($parts['scheme'] === 'gotify') {
            $path = preg_replace('#/[^/]+$#', '/…', $path);
        }

        return $parts['scheme'].'://'.(isset($parts['user']) || isset($parts['pass']) ? '…@' : '').$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }

    /**
     * The HTTP request for a notification URL: [method, url, payload, options].
     *
     * @throws InvalidArgumentException for URLs that are not supported
     */
    private static function request(string $url, string $title, string $message): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($parts === false || ! in_array($scheme, self::SCHEMES, true) || strlen($url) > 2000) {
            throw new InvalidArgumentException(__('Unsupported notification URL.'));
        }
        parse_str($parts['query'] ?? '', $query);
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
        $path = trim($parts['path'] ?? '', '/');
        $tls = ! in_array(strtolower((string) ($query['disabletls'] ?? '')), ['yes', 'true', '1'], true);
        $http = $tls ? 'https' : 'http';
        $require = function (bool $ok) {
            if (! $ok) {
                throw new InvalidArgumentException(__('Unsupported notification URL.'));
            }
        };

        switch ($scheme) {
            case 'ntfy':
                $require($host !== '' && $path !== '');
                $headers = ['Title' => self::header($title)];
                foreach (['priority' => 'Priority', 'tags' => 'Tags', 'icon' => 'Icon'] as $key => $header) {
                    if (isset($query[$key]) && is_string($query[$key])) {
                        $headers[$header] = self::header($query[$key]);
                    }
                }
                if (($query['scheme'] ?? null) === 'http') {
                    $http = 'http';
                }
                $options = ['headers' => $headers, 'body' => 'raw'];
                if ($user !== '') {
                    $options['basic'] = [$user, $pass];
                } elseif ($pass !== '') {
                    $options['token'] = $pass;
                }

                return ['POST', "{$http}://{$host}{$port}/{$path}", $message, $options];

            case 'discord':
                // discord://token@webhookid
                $require($user !== '' && $host !== '');

                return ['POST', 'https://discord.com/api/webhooks/'.rawurlencode($host).'/'.rawurlencode($user.($pass !== '' ? ':'.$pass : '')), ['content' => "**{$title}**\n{$message}"], []];

            case 'telegram':
                // The bot token has a colon, parse_url splits it into user and password.
                $token = $user.($pass !== '' ? ':'.$pass : '');
                $chats = array_values(array_filter(array_map('trim', explode(',', (string) ($query['chats'] ?? '')))));
                $require($token !== '' && $chats !== []);

                // Several chats: one request per chat.
                return ['POST', "https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chats[0], 'text' => "{$title}\n{$message}"], ['chats' => $chats]];

            case 'gotify':
                $segments = $path === '' ? [] : explode('/', $path);
                $token = array_pop($segments);
                $require($host !== '' && filled($token));
                $base = "{$http}://{$host}{$port}".($segments ? '/'.implode('/', $segments) : '');

                return ['POST', $base.'/message?token='.rawurlencode($token), ['title' => $title, 'message' => $message, 'priority' => (int) ($query['priority'] ?? 5)], []];

            case 'slack':
                // slack://hook:T000-B000-XXXX@webhook
                $require($user === 'hook' && substr_count($pass, '-') >= 2);

                return ['POST', 'https://hooks.slack.com/services/'.str_replace('-', '/', $pass), ['text' => "*{$title}*\n{$message}"], []];

            case 'pushover':
                // pushover://shoutrrr:apiToken@userKey
                $require($pass !== '' && $host !== '');
                $payload = ['token' => $pass, 'user' => $host, 'title' => $title, 'message' => $message];
                if (isset($query['devices']) && is_string($query['devices'])) {
                    $payload['device'] = $query['devices'];
                }

                return ['POST', 'https://api.pushover.net/1/messages.json', $payload, ['body' => 'form']];

            default:
                // generic://, generic+http://, generic+https://
                $require($host !== '');
                if ($scheme === 'generic+http') {
                    $http = 'http';
                } elseif ($scheme === 'generic+https') {
                    $http = 'https';
                }
                unset($query['disabletls']);
                $credentials = $user !== '' ? rawurlencode($user).($pass !== '' ? ':'.rawurlencode($pass) : '').'@' : '';
                $target = "{$http}://{$credentials}{$host}{$port}".($path !== '' ? '/'.$path : '').($query ? '?'.http_build_query($query) : '');

                return ['POST', $target, ['title' => $title, 'message' => $message], []];
        }
    }

    /** HTTP headers are Latin-1: other characters are replaced (ntfy reads UTF-8 titles as RFC 2047 too). */
    private static function header(string $value): string
    {
        return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?'.base64_encode($value).'?=' : $value;
    }
}
