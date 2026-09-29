<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Support\Signing;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed device API (agents 1.7.0+), in both directions.
 *
 * Requests carry X-MDM-Timestamp, X-MDM-Nonce and X-MDM-Signature, a signature with the device key
 * over "MDM1-REQ\n{METHOD}\n/{path}\n{timestamp}\n{nonce}\n{sha256(body)}". A device with a
 * registered key must sign every request; a nonce is accepted once.
 *
 * Every response to a request with a nonce is signed with the server key over
 * "MDM1-RESP\n{device id}\n{nonce}\n{timestamp}\n{sha256(body)}", error responses too, so the
 * agent accepts only answers from this server to its own request.
 *
 * Runs before auth:api (it resolves the device through the api guard itself), so the 401 of a
 * wrong token is signed as well.
 */
class DeviceSignature
{
    /** Routes that register a device key: the request is signed with the key in its body. */
    private const KEY_ROUTES = ['api/device/register', 'api/device/key'];

    public function handle(Request $request, Closure $next): Response
    {
        $error = $this->verify($request);
        $response = $error === null
            ? $next($request)
            : response()->json(['error' => $error], 401);

        $nonce = $request->header('X-MDM-Nonce');
        if (is_string($nonce) && preg_match('/^[0-9a-f]{32}$/', $nonce)) {
            $device = $request->attributes->get('mdm_device_id') ?? Auth::guard('api')->user()?->getKey() ?? 0;
            $timestamp = (string) time();
            $body = (string) $response->getContent();
            $response->headers->set('X-MDM-Device', (string) $device);
            $response->headers->set('X-MDM-Timestamp', $timestamp);
            $response->headers->set('X-MDM-Signature', Signing::sign('MDM1-RESP', implode("\n", [$device, $nonce, $timestamp, hash('sha256', $body)])));
        }

        return $response;
    }

    /** Null when the request may pass, otherwise the error code for the agent. */
    private function verify(Request $request): ?string
    {
        /** @var Device|null $device */
        $device = Auth::guard('api')->user();
        $signed = $request->hasHeader('X-MDM-Signature');
        $keyRoute = in_array($request->path(), self::KEY_ROUTES, true);

        if ($device !== null && $device->public_key !== null) {
            $key = $device->public_key;
        } elseif ($signed && $keyRoute) {
            // Proof of possession: signed with the key being registered.
            $key = Signing::normalizePublicKey($request->json('public_key'));
            if ($key === null) {
                return 'invalid_public_key';
            }
        } elseif ($signed && $device !== null) {
            // A signing agent whose key the server does not know (reset by an admin): it registers
            // the key again.
            return 'key_not_registered';
        } else {
            // Agents before 1.7.0 do not sign. Unauthenticated requests are left to auth:api.
            return $device !== null && config('mdm.require_signed_agents') ? 'signature_required' : null;
        }

        if (! $signed) {
            return 'signature_required';
        }

        $timestamp = (string) $request->header('X-MDM-Timestamp');
        $nonce = (string) $request->header('X-MDM-Nonce');
        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > config('mdm.signature_max_skew', 300)) {
            return 'clock_skew';
        }
        if (! preg_match('/^[0-9a-f]{32}$/', $nonce)) {
            return 'invalid_nonce';
        }

        $message = implode("\n", [$request->method(), '/'.$request->path(), $timestamp, $nonce, hash('sha256', $request->getContent())]);
        if (! Signing::verify($key, 'MDM1-REQ', $message, $request->header('X-MDM-Signature'))) {
            return 'invalid_signature';
        }

        // Checked after the signature, so nobody can use up nonces of a device.
        $scope = $device?->getKey() ?? 'new';
        if (! Cache::add("mdm-nonce:$scope:$nonce", true, 2 * config('mdm.signature_max_skew', 300))) {
            return 'replayed';
        }

        $request->attributes->set('mdm_signed', true);
        $request->attributes->set('mdm_public_key', $key);

        return null;
    }
}
