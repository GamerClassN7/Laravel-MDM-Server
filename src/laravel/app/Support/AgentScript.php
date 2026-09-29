<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class AgentScript
{
    /**
     * The agent served at /agent/app.ps1 (bundled in the Docker image, or the repository copy).
     */
    public static function path(): ?string
    {
        return collect([resource_path('agent/app.ps1'), base_path('../powershell/app.ps1')])
            ->first(fn (string $path) => is_file($path));
    }

    /**
     * The agent as served: the server's public key is filled into $EmbeddedServerKey, so a new or
     * updated agent pins this server's key without asking the server for it.
     */
    public static function content(): ?string
    {
        $path = static::path();
        if ($path === null) {
            return null;
        }

        $key = Signing::publicKey();

        return preg_replace(
            '/^\$EmbeddedServerKey = \'\'/m',
            '\$EmbeddedServerKey = \''.$key['n'].':'.$key['e'].'\'',
            (string) file_get_contents($path),
            1,
        );
    }

    /** Signature of the served agent, checked by the running agent before it updates itself. */
    public static function signature(string $content): string
    {
        return Signing::sign('MDM1-AGENT', hash('sha256', $content));
    }

    /**
     * Version of the agent this server serves, read from the script's $AgentVersion.
     */
    public static function version(): ?string
    {
        $path = static::path();
        if ($path === null) {
            return null;
        }

        return Cache::remember('agent-version-'.filemtime($path), 3600, function () use ($path) {
            return preg_match('/^\$AgentVersion = \'([^\']+)\'/m', file_get_contents($path), $matches) ? $matches[1] : null;
        });
    }
}
