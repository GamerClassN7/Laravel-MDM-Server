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
