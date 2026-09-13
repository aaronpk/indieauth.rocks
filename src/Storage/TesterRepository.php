<?php

declare(strict_types=1);

namespace IndieAuthRocks\Storage;

use IndieAuthRocks\Identity\Tester;

final class TesterRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Tester
    {
        $row = $this->db->one('SELECT * FROM testers WHERE id = ?', [$id]);

        return $row === null ? null : Tester::fromRow($row);
    }

    /** Find or create the tester for a verified profile URL, and record the login. */
    public function signIn(string $me): Tester
    {
        $hash = hash('sha256', $me);
        $now  = Database::now();

        $this->db->execute(
            'INSERT INTO testers (me, me_hash, created_at, last_login_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_login_at = VALUES(last_login_at)',
            [$me, $hash, $now, $now],
        );

        $row = $this->db->one('SELECT * FROM testers WHERE me_hash = ?', [$hash]);

        return Tester::fromRow((array) $row);
    }
}
