<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A WebAuthn authenticator implemented in software, for tests.
 *
 * It holds a real P-256 key and produces genuine ES256 signatures over correctly
 * shaped authenticator data, so the ceremony code is verified rather than
 * stubbed. A mock would only prove that the mock agrees with itself; this proves
 * the server accepts something a real authenticator would send, and — more
 * usefully — rejects the things it should.
 *
 * Deliberately manipulable: the sign counter can be rewound, the relying party id
 * or origin swapped, and the challenge replaced, because those are the failure
 * modes worth asserting on.
 */
final class SoftwareAuthenticator
{
    private OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private int $signCount = 0;

    public function __construct(
        private readonly string $relyingPartyId = 'localhost',
        private readonly string $origin = 'http://localhost:7777',
    ) {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Could not create a test key.');
        }

        $this->key          = $key;
        $this->credentialId = random_bytes(32);
    }

    public function credentialId(): string
    {
        return $this->credentialId;
    }

    public function signCount(): int
    {
        return $this->signCount;
    }

    /** Pretend to be a cloned authenticator by rewinding the counter. */
    public function rewindCounter(int $to = 0): void
    {
        $this->signCount = $to;
    }

    /**
     * A registration response.
     *
     * @return array{clientDataJSON: string, attestationObject: string}
     */
    public function register(string $challenge, bool $userVerified = true): array
    {
        $flags = 0x01 | 0x40; // user present, attested credential data included

        if ($userVerified) {
            $flags |= 0x04;
        }

        $authData = hash('sha256', $this->relyingPartyId, true)
            . chr($flags)
            . pack('N', $this->signCount)
            . str_repeat("\0", 16)                       // AAGUID: all zeroes, as a platform passkey reports
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $this->coseKey();

        // 'none' attestation: no statement to verify, which is what a platform
        // passkey produces.
        $attestation = self::cbor([
            'fmt'      => 'none',
            'attStmt'  => [],
            'authData' => new CborBytes($authData),
        ]);

        return [
            'clientDataJSON'    => $this->clientData('webauthn.create', $challenge),
            'attestationObject' => $attestation,
        ];
    }

    /**
     * An authentication response.
     *
     * @return array{clientDataJSON: string, authenticatorData: string, signature: string, userHandle: string}
     */
    public function authenticate(
        string $challenge,
        string $userHandle = '',
        bool $userVerified = true,
        ?string $relyingPartyId = null,
        ?string $origin = null,
    ): array {
        $this->signCount++;

        $flags = 0x01;

        if ($userVerified) {
            $flags |= 0x04;
        }

        $authData = hash('sha256', $relyingPartyId ?? $this->relyingPartyId, true)
            . chr($flags)
            . pack('N', $this->signCount);

        $clientData = $this->clientData('webauthn.get', $challenge, $origin);

        // The signature covers the authenticator data concatenated with the hash
        // of the client data, which is what binds the assertion to this challenge
        // and this origin.
        $signature = '';
        if (!openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the assertion.');
        }

        return [
            'clientDataJSON'    => $clientData,
            'authenticatorData' => $authData,
            'signature'         => $signature,
            'userHandle'        => $userHandle,
        ];
    }

    /** Authenticate without incrementing, as a cloned credential would. */
    public function authenticateWithCounter(string $challenge, int $counter, string $userHandle = ''): array
    {
        $this->signCount = $counter - 1;

        return $this->authenticate($challenge, $userHandle);
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return (string) json_encode([
            'type'        => $type,
            'challenge'   => rtrim(strtr(base64_encode($challenge), '+/', '-_'), '='),
            'origin'      => $origin ?? $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /** The public key as a COSE_Key structure, per RFC 8152. */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Could not read the test key.');
        }

        return self::cbor([
            1  => 2,   // kty: EC2
            3  => -7,  // alg: ES256
            -1 => 1,   // crv: P-256
            -2 => new CborBytes(str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => new CborBytes(str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
        ]);
    }

    /**
     * A minimal CBOR encoder: the subset WebAuthn structures need.
     *
     * Written here rather than pulled in as a dependency, because the only thing
     * that needs to *encode* CBOR is this test authenticator — the application
     * only ever decodes, which lbuchs/webauthn does.
     */
    public static function cbor(mixed $value): string
    {
        if ($value instanceof CborBytes) {
            return self::head(2, strlen($value->bytes)) . $value->bytes;
        }

        if (is_int($value)) {
            return $value >= 0
                ? self::head(0, $value)
                : self::head(1, -($value + 1));
        }

        if (is_string($value)) {
            return self::head(3, strlen($value)) . $value;
        }

        if (is_array($value)) {
            $encoded = self::head(5, count($value));

            foreach ($value as $key => $item) {
                $encoded .= self::cbor(is_int($key) ? $key : (string) $key);
                $encoded .= self::cbor($item);
            }

            return $encoded;
        }

        throw new RuntimeException('Cannot CBOR-encode ' . get_debug_type($value));
    }

    /** A CBOR initial byte plus whatever length representation it needs. */
    private static function head(int $majorType, int $value): string
    {
        $type = $majorType << 5;

        return match (true) {
            $value < 24          => chr($type | $value),
            $value < 0x100       => chr($type | 24) . chr($value),
            $value < 0x10000     => chr($type | 25) . pack('n', $value),
            $value < 0x100000000 => chr($type | 26) . pack('N', $value),
            default              => chr($type | 27) . pack('J', $value),
        };
    }
}
