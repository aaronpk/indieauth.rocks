<?php

declare(strict_types=1);

namespace IndieAuthRocks\IndieAuth;

/**
 * Scope parsing. Any syntactically valid scope is accepted, because resource
 * servers such as Micropub endpoints define their own; the well-known ones get
 * a description on the consent screen.
 */
final class Scopes
{
    public const DESCRIPTIONS = [
        'profile'  => 'See your name, photo and URL',
        'email'    => 'See your email address',
        'create'   => 'Create new posts',
        'draft'    => 'Create draft posts only',
        'update'   => 'Edit your existing posts',
        'delete'   => 'Delete posts',
        'undelete' => 'Restore deleted posts',
        'media'    => 'Upload files to your media endpoint',
        'read'     => 'Read your feeds and channels',
        'follow'   => 'Follow and unfollow feeds',
        'mute'     => 'Mute and unmute users',
        'block'    => 'Block and unblock users',
        'channels' => 'Manage your channels',
    ];

    /**
     * Split and validate a scope string (RFC 6749 §3.3). Null when invalid.
     *
     * @return list<string>|null
     */
    public static function parse(string $scope): ?array
    {
        $scope = trim($scope);

        if ($scope === '') {
            return [];
        }

        if (strlen($scope) > 1000) {
            return null;
        }

        $scopes = [];
        foreach (preg_split('/ +/', $scope) ?: [] as $token) {
            if (preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/', $token) !== 1) {
                return null;
            }

            if (!in_array($token, $scopes, true)) {
                $scopes[] = $token;
            }
        }

        return $scopes;
    }

    public static function describe(string $scope): ?string
    {
        return self::DESCRIPTIONS[$scope] ?? null;
    }

    /** @param list<string> $scopes */
    public static function toString(array $scopes): string
    {
        return implode(' ', $scopes);
    }
}
