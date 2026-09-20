<?php

declare(strict_types=1);

namespace IndieAuthRocks\Checkup;

/**
 * The handful of hosts worth recognising by name.
 *
 * Not a directory, and not used to grade anything: it exists so the page can
 * say "that's Micro.blog, and it's set up correctly" instead of leaving someone
 * to work out whether a hostname they have never seen is theirs.
 */
final class KnownServers
{
    /** Sites people commonly mistake for their own identity. */
    private const SOCIAL = [
        'twitter.com', 'x.com', 'github.com', 'gitlab.com', 'instagram.com', 'facebook.com',
        'threads.net', 'bsky.app', 'medium.com', 'linkedin.com', 'tumblr.com', 'youtube.com',
        'reddit.com', 'mastodon.social', 'mastodon.online', 'hachyderm.io', 'fosstodon.org',
    ];

    private const SERVERS = [
        'indieauth.com'     => 'IndieAuth.com',
        'micro.blog'        => 'Micro.blog',
        'indielogin.com'    => 'IndieLogin.com',
        'known.host'        => 'Known',
    ];

    public static function hostOf(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** Whether a profile URL is a page on somebody else's site. */
    public static function isSocial(string $profileUrl): bool
    {
        $host = self::hostOf($profileUrl);

        return $host !== null && in_array($host, self::SOCIAL, true);
    }

    public static function name(?string $url): ?string
    {
        $host = self::hostOf($url);

        return $host === null ? null : (self::SERVERS[$host] ?? null);
    }

    /**
     * Anything the person should know about the server they are using, beyond
     * that it works — with somewhere to go about it, rather than an
     * instruction to go looking.
     *
     * @return array{headline: string, fix: string, links: list<array{text: string, url: string}>}|null
     */
    public static function noteAbout(?string $url): ?array
    {
        return match (self::hostOf($url)) {
            'indieauth.com' => [
                'headline' => 'Your site uses IndieAuth.com as its IndieAuth server. That service is being '
                    . 'retired, so your login will keep working for now but not forever.',
                'fix'   => 'Move your site to IndieKey, which replaces it.',
                'links' => [['text' => 'IndieKey', 'url' => 'https://indiekey.id/migrate']],
            ],
            default => null,
        };
    }
}
