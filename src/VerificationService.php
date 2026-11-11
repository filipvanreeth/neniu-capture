<?php

declare(strict_types=1);

namespace Neniu\Verify;

use PDO;
use Throwable;

final class VerificationService
{
    public function __construct(
        private PDO $db,
        private RecordRepository $records,
        private Clock $clock,
    ) {
    }

    public function issue(): array
    {
        $token = bin2hex(random_bytes(32));
        $session = [
            'token' => $token,
            'session_id' => bin2hex(random_bytes(16)),
            'issued_at' => $this->clock->now(),
            'expires_at' => time() + 600,
        ];
        $this->db->prepare('DELETE FROM verification_sessions WHERE expires_at <= ?')->execute([time()]);
        $this->db->prepare('INSERT INTO verification_sessions VALUES (?, ?, ?, ?)')->execute([
            hash('sha256', $token), $session['session_id'], $session['issued_at'], $session['expires_at'],
        ]);
        return $session;
    }

    public function save(array $record, string $bytes, string $token): bool
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $query = $this->db->prepare('SELECT * FROM verification_sessions WHERE token_hash = ? AND expires_at > ?');
            $query->execute([hash('sha256', $token), time()]);
            $session = $query->fetch();
            if (!$session) {
                $this->db->exec('ROLLBACK');
                return false;
            }
            $record['metadata']['verification_session'] = [
                'source' => 'server',
                'id' => $session['session_id'],
                'issued_at' => $session['issued_at'],
                'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', (int) $session['expires_at']),
            ];
            $this->records->save($record, $bytes);
            $this->db->prepare('DELETE FROM verification_sessions WHERE token_hash = ?')
                ->execute([$session['token_hash']]);
            $this->db->exec('COMMIT');
            return true;
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK');
            throw $error;
        }
    }
}
