<?php

declare(strict_types=1);

/**
 * Apple ID Token Verifier (pure PHP, zero dependencies).
 *
 * Validates Apple Sign-In identity tokens against Apple's JWKS.
 * JWKS is cached on disk for 24 hours to avoid hitting Apple on every login.
 *
 * Zero external dependencies — RS256 signature verification is done with
 * openssl_verify() and a manually constructed PEM key from the JWK modulus
 * and exponent.
 */
class AppleIdTokenVerifier
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';
    private const CACHE_TTL_SECONDS = 86400; // 24h
    private const PRIVATE_RELAY_DOMAIN = '@privaterelay.appleid.com';

    /**
     * Verifies an Apple identity token.
     *
     * @param string $identityToken The JWT supplied by Apple.
     * @param array  $expectedAudiences Audience claims to accept (Services ID + Bundle ID).
     * @return array|null ['sub' => ..., 'email' => ?, 'emailVerified' => bool, 'isPrivateRelay' => bool]
     *                    or null when validation fails.
     */
    public static function verify(string $identityToken, array $expectedAudiences): ?array
    {
        if ($identityToken === '' || empty($expectedAudiences)) {
            return null;
        }

        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = self::decodeJson($headerB64);
        $payload = self::decodeJson($payloadB64);
        $signature = self::base64UrlDecode($signatureB64);

        if ($header === null || $payload === null || $signature === false) {
            return null;
        }

        // --- Header checks ---
        $alg = $header['alg'] ?? '';
        $kid = $header['kid'] ?? '';
        if ($alg !== 'RS256' || $kid === '') {
            return null;
        }

        // --- Claim checks ---
        if (($payload['iss'] ?? '') !== self::ISSUER) {
            return null;
        }

        $aud = $payload['aud'] ?? '';
        if (!in_array($aud, $expectedAudiences, true)) {
            return null;
        }

        $now = time();
        $exp = (int)($payload['exp'] ?? 0);
        if ($exp < $now) {
            return null;
        }

        $nbf = isset($payload['nbf']) ? (int)$payload['nbf'] : null;
        if ($nbf !== null && $nbf > $now + 60) {
            return null;
        }

        // --- Signature verification against Apple JWKS ---
        $jwks = self::loadJwks();
        if ($jwks === null) {
            return null;
        }

        $jwk = null;
        foreach ($jwks as $key) {
            if (($key['kid'] ?? null) === $kid) {
                $jwk = $key;
                break;
            }
        }

        // If kid is unknown, refresh the cache once in case Apple rotated keys.
        if ($jwk === null) {
            $jwks = self::loadJwks(true);
            if ($jwks !== null) {
                foreach ($jwks as $key) {
                    if (($key['kid'] ?? null) === $kid) {
                        $jwk = $key;
                        break;
                    }
                }
            }
        }

        if ($jwk === null) {
            return null;
        }

        $publicKey = self::jwkToPem($jwk);
        if ($publicKey === null) {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $ok = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return null;
        }

        $sub = $payload['sub'] ?? '';
        if ($sub === '') {
            return null;
        }

        $email = isset($payload['email']) ? (string)$payload['email'] : null;
        $emailVerified = false;
        if (isset($payload['email_verified'])) {
            $ev = $payload['email_verified'];
            $emailVerified = $ev === true || $ev === 'true' || $ev === 1 || $ev === '1';
        }

        $isPrivateRelay = false;
        if ($email !== null && str_ends_with(strtolower($email), self::PRIVATE_RELAY_DOMAIN)) {
            $isPrivateRelay = true;
        }
        if (isset($payload['is_private_email'])) {
            $pe = $payload['is_private_email'];
            if ($pe === true || $pe === 'true' || $pe === 1 || $pe === '1') {
                $isPrivateRelay = true;
            }
        }

        return [
            'sub' => (string)$sub,
            'email' => $email,
            'emailVerified' => $emailVerified,
            'isPrivateRelay' => $isPrivateRelay,
        ];
    }

    /**
     * Loads the Apple JWKS, using a 24h file cache.
     * Set $force=true to bypass the cache and refetch.
     */
    private static function loadJwks(bool $force = false): ?array
    {
        $cacheFile = self::cacheFilePath();
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        if (!$force && is_file($cacheFile)) {
            $mtime = filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < self::CACHE_TTL_SECONDS) {
                $raw = @file_get_contents($cacheFile);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded) && isset($decoded['keys']) && is_array($decoded['keys'])) {
                        return $decoded['keys'];
                    }
                }
            }
        }

        $raw = self::fetchUrl(self::JWKS_URL);
        if ($raw === null) {
            // Fallback to stale cache if network failed
            if (is_file($cacheFile)) {
                $cached = @file_get_contents($cacheFile);
                if ($cached !== false) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded) && isset($decoded['keys'])) {
                        return $decoded['keys'];
                    }
                }
            }
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            return null;
        }

        // Atomic write
        $tmp = $cacheFile . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $raw) !== false) {
            @rename($tmp, $cacheFile);
            @chmod($cacheFile, 0644);
        }

        return $decoded['keys'];
    }

    private static function cacheFilePath(): string
    {
        $baseDir = dirname(__DIR__, 2);
        return $baseDir . '/.cache/apple-jwks.json';
    }

    private static function fetchUrl(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'horizOn-simpleServer/1.0 AppleSignIn',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $status !== 200) {
                return null;
            }
            return (string)$body;
        }

        // Fallback: file_get_contents (needs allow_url_fopen)
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: horizOn-simpleServer/1.0 AppleSignIn\r\n",
            ],
            'https' => [
                'timeout' => 10,
                'header' => "User-Agent: horizOn-simpleServer/1.0 AppleSignIn\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return null;
        }
        return (string)$body;
    }

    /**
     * Converts a JWK (RSA) to a PEM-encoded public key string.
     * Manually DER-encodes the SubjectPublicKeyInfo structure so we don't need phpseclib.
     */
    private static function jwkToPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $n = self::base64UrlDecode($jwk['n']);
        $e = self::base64UrlDecode($jwk['e']);
        if ($n === false || $e === false) {
            return null;
        }

        // Ensure positive INTEGER encoding (prepend 0x00 if high bit set).
        $nEncoded = self::derEncodeInteger($n);
        $eEncoded = self::derEncodeInteger($e);

        $rsaPublicKey = self::derEncodeSequence($nEncoded . $eEncoded);

        // AlgorithmIdentifier for rsaEncryption OID 1.2.840.113549.1.1.1 + NULL
        $algorithmIdentifier = self::derEncodeSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00"
        );

        // BIT STRING wrapping the RSA key
        $bitString = "\x03" . self::derEncodeLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;

        $spki = self::derEncodeSequence($algorithmIdentifier . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spki), 64, "\n")
            . "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    private static function derEncodeInteger(string $bytes): string
    {
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // Strip leading zero bytes but keep at least one byte.
        while (strlen($bytes) > 1 && $bytes[0] === "\x00" && (ord($bytes[1]) & 0x80) === 0) {
            $bytes = substr($bytes, 1);
        }
        // Ensure positive: prepend 0x00 if high bit is set.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derEncodeLength(strlen($bytes)) . $bytes;
    }

    private static function derEncodeSequence(string $contents): string
    {
        return "\x30" . self::derEncodeLength(strlen($contents)) . $contents;
    }

    private static function derEncodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function base64UrlDecode(string $data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }

    private static function decodeJson(string $segment): ?array
    {
        $raw = self::base64UrlDecode($segment);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
