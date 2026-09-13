<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\Fetch;

use IndieAuthRocks\Fetch\Redactor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Redactor::class)]
final class RedactorTest extends TestCase
{
    public function test_redacts_secret_query_parameters_but_keeps_the_rest(): void
    {
        $url = Redactor::url('https://app.example/cb?code=abc123&state=xyz&iss=https%3A%2F%2Fa.example%2F');

        $this->assertStringNotContainsString('abc123', $url);
        $this->assertStringContainsString('state=xyz', $url);
        $this->assertStringContainsString('iss=https%3A%2F%2Fa.example%2F', $url);
        $this->assertStringContainsString(rawurlencode('[redacted len=6 sha256:' . substr(hash('sha256', 'abc123'), 0, 8) . ']'), $url);
    }

    public function test_redacts_authorization_headers_keeping_the_scheme(): void
    {
        $headers = Redactor::headers(['Authorization' => 'Bearer secret-token', 'Accept' => 'application/json', 'set-cookie' => ['a=1', 'b=2']]);

        $this->assertStringStartsWith('Bearer [redacted len=12', $headers['Authorization']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertStringStartsWith('[redacted', $headers['set-cookie'][1]);
    }

    public function test_redacts_form_and_json_bodies(): void
    {
        $form = Redactor::body('grant_type=authorization_code&code=c0de&code_verifier=v', 'application/x-www-form-urlencoded', 1000);
        $this->assertStringContainsString('grant_type=authorization_code', (string) $form);
        $this->assertStringNotContainsString('c0de', (string) $form);

        $json = Redactor::body('{"access_token":"tok","me":"https://me.example/","nested":{"refresh_token":"r"}}', 'application/json; charset=utf-8', 1000);
        $this->assertStringNotContainsString('"tok"', (string) $json);
        $this->assertStringNotContainsString('"r"', (string) $json);
        $this->assertStringContainsString('https://me.example/', (string) $json);
    }

    public function test_truncates_long_bodies(): void
    {
        $this->assertStringEndsWith('[truncated]', (string) Redactor::body(str_repeat('x', 50), 'text/plain', 10));
    }
}
