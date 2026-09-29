<?php

namespace App\Models;

use App\Support\Signing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptRun extends Model
{
    /** pending: waits for the agent, sent: the agent took it, then the result. */
    public const STATUSES = ['pending', 'sent', 'compliant', 'remediated', 'failed', 'error', 'rejected', 'expired', 'superseded'];

    /** What the agent may report. */
    public const RESULTS = ['compliant', 'remediated', 'failed', 'error', 'rejected'];

    public const MAX_OUTPUT = 16384;

    protected $fillable = ['device_id', 'version', 'fingerprint', 'status', 'issued_by', 'issued_at', 'expires_at'];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function script(): BelongsTo
    {
        return $this->belongsTo(Script::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function getFinishedAttribute(): bool
    {
        return ! in_array($this->status, ['pending', 'sent'], true);
    }

    /**
     * What the agent gets: the manifest (exactly the signed JSON), its signature and both scripts
     * in base64 (their bytes are covered by the hashes in the manifest).
     */
    public function toSignedPayload(): array
    {
        $script = $this->script;
        $manifest = json_encode([
            'run_id' => $this->id,
            'device_id' => $this->device_id,
            'script_id' => $script->id,
            'name' => $script->name,
            'version' => $this->version,
            'fingerprint' => $this->fingerprint,
            'platform' => $script->platform,
            'timeout' => $script->timeout,
            'detection_sha256' => hash('sha256', $script->detection),
            'remediation_sha256' => $script->remediation === null ? null : hash('sha256', $script->remediation),
            'issued_at' => $this->issued_at->getTimestamp(),
            'expires_at' => $this->expires_at->getTimestamp(),
        ]);

        return [
            'manifest' => $manifest,
            'signature' => Signing::sign('MDM1-SCRIPT', $manifest),
            'detection' => base64_encode($script->detection),
            'remediation' => $script->remediation === null ? null : base64_encode($script->remediation),
        ];
    }

    public function getStatusColorAttribute(): string
    {
        return self::colorFor($this->status);
    }

    public static function colorFor(string $status): string
    {
        return match ($status) {
            'compliant', 'remediated' => 'success',
            'failed', 'error', 'rejected' => 'danger',
            'pending', 'sent' => 'info',
            default => 'secondary',
        };
    }
}
