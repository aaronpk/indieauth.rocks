<?php

declare(strict_types=1);

namespace IndieAuthRocks\Tests\Unit\View;

use IndieAuthRocks\View\BodyFormat;
use PHPUnit\Framework\TestCase;

final class BodyFormatTest extends TestCase
{
    public function test_a_form_encoded_body_is_one_parameter_per_line(): void
    {
        $this->assertSame(
            "grant_type=authorization_code\ncode=abc\nredirect_uri=https://client.example/callback",
            BodyFormat::pretty('grant_type=authorization_code&code=abc&redirect_uri=https%3A%2F%2Fclient.example%2Fcallback'),
        );
    }

    public function test_a_redacted_value_reads_as_text(): void
    {
        $this->assertSame(
            '_csrf=[redacted len=43 sha256:3f8fdbf2]',
            BodyFormat::pretty('_csrf=%5Bredacted%20len%3D43%20sha256%3A3f8fdbf2%5D'),
        );
    }

    public function test_a_json_body_is_pretty_printed(): void
    {
        $this->assertSame(
            "{\n    \"me\": \"https://me.example/\",\n    \"profile\": {\n        \"name\": \"Test\"\n    }\n}",
            BodyFormat::pretty('{"me":"https:\/\/me.example\/","profile":{"name":"Test"}}'),
        );
    }

    public function test_control_characters_cannot_rearrange_the_layout(): void
    {
        $this->assertSame('note=one·two', BodyFormat::pretty('note=one%0Atwo'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function leftAlone(): iterable
    {
        yield 'empty'         => [''];
        yield 'plain text'    => ['Not found'];
        yield 'broken json'   => ['{"me": '];
        yield 'html'          => ['<!doctype html><title>Hi</title>'];
        yield 'a bare value'  => ['just-a-token'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leftAlone')]
    public function test_anything_else_is_left_alone(string $body): void
    {
        $this->assertSame($body, BodyFormat::pretty($body));
    }
}
