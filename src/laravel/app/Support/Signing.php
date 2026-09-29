<?php

namespace App\Support;

use RuntimeException;

/**
 * RSA-3072 signatures (PKCS#1 v1.5, SHA-256) between the server and the agents.
 *
 * The server key signs everything the server sends to devices (API responses, WebSocket events,
 * the agent script); every device has its own key that signs what it sends. Public keys travel as
 * {n, e}: the big-endian modulus and exponent in base64, which the agent imports into .NET
 * RSAParameters on Windows PowerShell 5.1 and PowerShell 7 alike.
 *
 * Every signed text starts with a context prefix (MDM1-REQ, MDM1-RESP, ...), so a signature made
 * for one purpose is never valid for another.
 */
class Signing
{
    private static ?\OpenSSLAsymmetricKey $privateKey = null;

    private static ?string $loadedPath = null;

    public static function keyPath(): string
    {
        return config('mdm.signing_key') ?: storage_path('mdm-signing.key');
    }

    /**
     * The server's private key, generated on first use. It lives in a file readable by the app
     * only, never in the database.
     */
    public static function privateKey(): \OpenSSLAsymmetricKey
    {
        $path = static::keyPath();
        if (static::$privateKey !== null && static::$loadedPath === $path) {
            return static::$privateKey;
        }

        if (! is_file($path)) {
            static::generate($path);
        }

        $key = openssl_pkey_get_private((string) file_get_contents($path));
        if ($key === false) {
            throw new RuntimeException("The signing key in $path cannot be read.");
        }

        static::$loadedPath = $path;

        return static::$privateKey = $key;
    }

    /** Creates the key file once; concurrent workers wait for the first one and reuse its key. */
    public static function generate(string $path): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $lock = fopen("$path.lock", 'c');
        flock($lock, LOCK_EX);
        try {
            if (is_file($path)) {
                return;
            }

            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            if ($key === false || ! openssl_pkey_export($key, $pem)) {
                throw new RuntimeException('Generating the signing key failed: '.openssl_error_string());
            }

            $temporary = "$path.".bin2hex(random_bytes(4)).'.tmp';
            file_put_contents($temporary, $pem);
            chmod($temporary, 0600);
            rename($temporary, $path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{n: string, e: string} */
    public static function publicKey(): array
    {
        $details = openssl_pkey_get_details(static::privateKey());

        return [
            'n' => base64_encode(ltrim($details['rsa']['n'], "\0")),
            'e' => base64_encode(ltrim($details['rsa']['e'], "\0")),
        ];
    }

    /** SHA-256 of "rsa:{n}:{e}", the value admins compare with the agent's log and install command. */
    public static function fingerprint(?array $publicKey = null): string
    {
        $publicKey ??= static::publicKey();

        return hash('sha256', 'rsa:'.$publicKey['n'].':'.$publicKey['e']);
    }

    /** Base64 signature of "$context\n$message" with the server key. */
    public static function sign(string $context, string $message): string
    {
        if (! openssl_sign($context."\n".$message, $signature, static::privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    /** Checks a base64 signature of "$context\n$message" made with the given public key. */
    public static function verify(array $publicKey, string $context, string $message, ?string $signature): bool
    {
        $binary = $signature === null ? false : base64_decode($signature, true);
        $pem = static::publicKeyPem($publicKey);
        if ($binary === false || $pem === null) {
            return false;
        }

        return openssl_verify($context."\n".$message, $binary, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * A {n, e} key sent by a device, or null when it is not a usable RSA key (2048 to 4096 bits).
     *
     * @return array{n: string, e: string}|null
     */
    public static function normalizePublicKey(mixed $key): ?array
    {
        if (! is_array($key) || ! is_string($key['n'] ?? null) || ! is_string($key['e'] ?? null)) {
            return null;
        }

        $n = base64_decode($key['n'], true);
        $e = base64_decode($key['e'], true);
        if ($n === false || $e === false) {
            return null;
        }
        $n = ltrim($n, "\0");
        $e = ltrim($e, "\0");
        $bits = strlen($n) * 8;
        if ($bits < 2048 || $bits > 4096 || $e === '' || strlen($e) > 8) {
            return null;
        }

        $normalized = ['n' => base64_encode($n), 'e' => base64_encode($e)];

        return static::publicKeyPem($normalized) === null ? null : $normalized;
    }

    /** PEM (SubjectPublicKeyInfo) for openssl from {n, e}. */
    public static function publicKeyPem(array $publicKey): ?string
    {
        $n = base64_decode((string) ($publicKey['n'] ?? ''), true);
        $e = base64_decode((string) ($publicKey['e'] ?? ''), true);
        if (! $n || ! $e) {
            return null;
        }

        $rsaKey = static::der(0x30, static::derInteger($n).static::derInteger($e));
        // AlgorithmIdentifier: rsaEncryption (1.2.840.113549.1.1.1), NULL parameters.
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $spki = static::der(0x30, $algorithm.static::der(0x03, "\0".$rsaKey));

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";

        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        // Unsigned: a leading 1 bit would make the integer negative.
        if ($bytes === '' || ord($bytes[0]) & 0x80) {
            $bytes = "\0".$bytes;
        }

        return static::der(0x02, $bytes);
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag).chr($length).$content;
        }
        $lengthBytes = ltrim(pack('N', $length), "\0");

        return chr($tag).chr(0x80 | strlen($lengthBytes)).$lengthBytes.$content;
    }
}
