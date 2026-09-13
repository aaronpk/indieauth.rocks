<?php

declare(strict_types=1);

namespace IndieAuthRocks\Controllers;

use IndieAuthRocks\Config\SiteConfig;
use IndieAuthRocks\Http\HttpException;
use IndieAuthRocks\Http\Request;
use IndieAuthRocks\Http\Response;

/**
 * Development only: the home page of the profile URL used by automated
 * end-to-end runs. `driver:myindieauth:enroll` writes the metadata URL it
 * should link to.
 */
final class FixtureController
{
    public static function file(): string
    {
        return SiteConfig::root() . '/storage/fixture.json';
    }

    /** @param array<string, string> $params */
    public function home(Request $request, array $params = []): Response
    {
        $data     = is_file(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : null;
        $metadata = is_array($data) && is_string($data['metadata_url'] ?? null) ? $data['metadata_url'] : null;

        if ($metadata === null) {
            throw HttpException::notFound();
        }

        $href = htmlspecialchars($metadata, ENT_QUOTES | ENT_HTML5);

        return Response::html(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>End-to-end test profile</title>
                <link rel="indieauth-metadata" href="{$href}">
            </head>
            <body><p>A profile page for automated IndieAuth Rocks runs.</p></body>
            </html>
            HTML);
    }
}
