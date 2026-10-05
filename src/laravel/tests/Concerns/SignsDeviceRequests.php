<?php

namespace Tests\Concerns;

use App\Models\Device;
use App\Support\Signing;
use Illuminate\Testing\TestResponse;

/**
 * Requests as a signing agent (1.7.0+) sends them: X-MDM-Timestamp, X-MDM-Nonce and
 * X-MDM-Signature with the device key.
 */
trait SignsDeviceRequests
{
    private static ?\OpenSSLAsymmetricKey $testDeviceKey = null;

    /** The nonce of the last signed request, to check the signed response against. */
    protected ?string $lastNonce = null;

    /** A 2048-bit key (the smallest accepted) shared by the tests, generating one takes a while. */
    protected function deviceKey(): \OpenSSLAsymmetricKey
    {
        return self::$testDeviceKey ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    }

    /** @return array{n: string, e: string} */
    protected function devicePublicKey(?\OpenSSLAsymmetricKey $key = null): array
    {
        $details = openssl_pkey_get_details($key ?? $this->deviceKey());

        return ['n' => base64_encode(ltrim($details['rsa']['n'], "\0")), 'e' => base64_encode(ltrim($details['rsa']['e'], "\0"))];
    }

    protected function registerDeviceKey(Device $device): Device
    {
        $device->public_key = $this->devicePublicKey();
        $device->key_registered_at = now();
        $device->save();

        return $device;
    }

    protected function signedJson(string $method, string $uri, array $data = [], ?string $token = null, array $options = []): TestResponse
    {
        // options.raw: a body that is not JSON of $data (compressed by the agent).
        $body = $options['raw'] ?? json_encode($data);
        $timestamp = (string) ($options['timestamp'] ?? time());
        $nonce = $this->lastNonce = $options['nonce'] ?? bin2hex(random_bytes(16));
        $message = implode("\n", ['MDM1-REQ', strtoupper($method), parse_url($uri, PHP_URL_PATH), $timestamp, $nonce, hash('sha256', $body)]);
        openssl_sign($message, $signature, $options['key'] ?? $this->deviceKey(), OPENSSL_ALGO_SHA256);

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_MDM_TIMESTAMP' => $timestamp,
            'HTTP_X_MDM_NONCE' => $nonce,
            'HTTP_X_MDM_SIGNATURE' => $options['signature'] ?? base64_encode($signature),
        ];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $server += $options['server'] ?? [];

        // The api guard caches the device of the previous request.
        $this->app['auth']->forgetGuards();

        return $this->call(strtoupper($method), $uri, [], [], [], $server, $body);
    }

    /** The response is signed with the server key for the last request and the given device. */
    protected function assertSignedResponse(TestResponse $response, int $deviceId): TestResponse
    {
        $message = implode("\n", [$deviceId, $this->lastNonce, $response->headers->get('X-MDM-Timestamp'), hash('sha256', (string) $response->getContent())]);
        $this->assertSame((string) $deviceId, $response->headers->get('X-MDM-Device'));
        $this->assertTrue(Signing::verify(Signing::publicKey(), 'MDM1-RESP', $message, $response->headers->get('X-MDM-Signature')), 'The response is not signed with the server key.');

        return $response;
    }
}
