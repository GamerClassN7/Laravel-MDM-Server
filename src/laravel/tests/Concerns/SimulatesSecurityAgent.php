<?php

namespace Tests\Concerns;

use App\Models\SecurityInventory;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * An agent's side of the security collection (Get-SecurityDeltas in app.ps1): it keeps the state
 * the server acknowledged for each source and sends only what changed against it.
 */
trait SimulatesSecurityAgent
{
    /** @var array<string, array<string, array{state: string, map: array<string, string>}>> token => source => acknowledged state */
    private array $agentStates = [];

    /** The last body this agent sent, and its size. */
    protected array $lastCollection = [];

    /**
     * The sources of a collection from the lists the agent just read.
     *
     * @param  array<string, mixed>  $inventory
     * @param  array<int, string>  $whole  sources sent whole whatever the stored state is
     * @return array{sources: array<int, array>, pending: array<string, array>}
     */
    protected function agentSources(string $token, array $inventory, array $whole = []): array
    {
        $sources = [];
        $pending = [];
        foreach (SecurityInventory::SOURCES as $source) {
            if (! array_key_exists($source, $inventory)) {
                continue;
            }
            $items = $source === 'posture' ? [$inventory['posture']] : $inventory[$source];
            $map = [];
            $rows = [];
            foreach ($items as $item) {
                $item = SecurityInventory::sanitizeItem($source, $item);
                $key = SecurityInventory::itemKey($source, $item);
                $hash = SecurityInventory::itemHash($source, $item);
                $map[$key] = $hash;
                $rows[$key] = [$key, $hash, $item];
            }
            $state = SecurityInventory::stateOf($map);
            $previous = $this->agentStates[$token][$source] ?? null;
            if ($previous === null || in_array($source, $whole, true)) {
                $sources[] = ['source' => $source, 'full' => true, 'state' => $state, 'upsert' => array_values($rows)];
            } elseif ($previous['state'] === $state) {
                $sources[] = ['source' => $source, 'state' => $state];
            } else {
                $changed = array_filter($rows, fn ($row, $key) => ($previous['map'][$key] ?? null) !== $row[1], ARRAY_FILTER_USE_BOTH);
                $sources[] = ['source' => $source, 'base' => $previous['state'], 'state' => $state, 'upsert' => array_values($changed), 'remove' => array_values(array_diff(array_keys($previous['map']), array_keys($map)))];
            }
            $pending[$source] = ['state' => $state, 'map' => $map];
        }

        return ['sources' => $sources, 'pending' => $pending];
    }

    /**
     * Sends a collection and, like the agent, sends the sources the server asks for whole.
     *
     * @return TestResponse the last response
     */
    protected function agentSend(string $token, array $inventory, array $logs = [], ?string $collectionId = null, array $cursors = [], bool $gzip = false)
    {
        $whole = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            ['sources' => $sources, 'pending' => $pending] = $this->agentSources($token, $inventory, $whole);
            $body = ['collection_id' => $collectionId ?? (string) Str::uuid(), 'sources' => $sources, 'logs' => $logs] + ($cursors === [] ? [] : ['cursors' => $cursors]);
            $this->lastCollection = $body;
            // Compressed before it is signed: the signature covers the bytes that are sent.
            $options = $gzip ? ['raw' => gzencode(json_encode($body), 6), 'server' => ['HTTP_CONTENT_ENCODING' => 'gzip']] : [];
            $response = $this->signedJson('POST', '/api/device/security', $body, $token, $options);
            if ($response->status() === 409) {
                $whole = $response->json('resync');
                $collectionId = null;

                continue;
            }
            if ($response->status() === 202) {
                foreach ($response->json('ack') as $source => $state) {
                    $this->agentStates[$token][$source] = $pending[$source];
                }
            }

            return $response;
        }

        return $response;
    }

    /** The server lost what the agent thinks it has (restored backup, failed job): the agent still has its state. */
    protected function agentForgetsNothing(): void
    {
        // The agent keeps $agentStates; nothing to do, kept for the tests to say so.
    }
}
