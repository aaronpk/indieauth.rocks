<?php

declare(strict_types=1);

namespace IndieAuthRocks\Drivers\MyIndieAuth;

use RuntimeException;

/**
 * The enrolled account a MyIndieAuthDriver signs in as: server URL, profile
 * URL, and the software authenticator's private key and counter. Written with
 * 0600 permissions under storage/, which is not in version control.
 */
final class DriverConfigFile
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(private readonly string $path)
    {
        $raw = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        $this->data = is_array($raw) ? $raw : [];
    }

    public function exists(): bool
    {
        return $this->data !== [];
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /** @param array<string, mixed> $data */
    public function write(array $data): void
    {
        $this->data = $data;

        if (!is_dir(dirname($this->path)) && !mkdir(dirname($this->path), 0o700, true) && !is_dir(dirname($this->path))) {
            throw new RuntimeException('Could not create ' . dirname($this->path));
        }

        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($this->path, 0o600);
    }

    public function saveSignCount(int $count): void
    {
        if ($this->data !== []) {
            $this->write(['sign_count' => $count] + $this->data);
        }
    }

    public function authenticator(): SoftwareAuthenticator
    {
        $base = (string) $this->get('base');

        return SoftwareAuthenticator::restore(
            (string) parse_url($base, PHP_URL_HOST),
            rtrim($base, '/'),
            (string) $this->get('private_key'),
            MyIndieAuthDriver::unb64((string) $this->get('credential_id')),
            (int) $this->get('sign_count'),
        );
    }
}
