<?php

declare(strict_types=1);:

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    private array $lockedIds = [];

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool
    {
        foreach (array_keys($this->lockedIds) as $id) {
            try {
                $stmt = Database::connection()->prepare('SELECT pg_advisory_unlock(hashtext(:id))');
                $stmt->execute(['id' => $id]);
            } catch (Throwable $error) {
                error_log('Persistent session unlock failed.');
            }
            unset($this->lockedIds[$id]);
        }
        return true;
    }

    private function lock(string $id): void
    {
        if (isset($this->lockedIds[$id])) return;
        $stmt = Database::connection()->prepare('SELECT pg_advisory_lock(hashtext(:id))');
        $stmt->execute(['id' => $id]);
        $this->lockedIds[$id] = true;
    }

    public function read(string $id): string|false
    {
        try {
            $this->lock($id);
            $stmt = Database::connection()->prepare('SELECT payload, expires_at FROM app.sessions WHERE id = :id AND expires_at > now()');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            if (!$row) return '';
            $decoded = base64_decode((string) $row['payload'], true);
            return $decoded === false ? '' : $decoded;
        } catch (Throwable $error) {
            error_log('Persistent session read failed.');
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->lock($id);
            $accountId = $_SESSION['account_id'] ?? null;
            $stmt = Database::connection()->prepare(
                'INSERT INTO app.sessions (id,account_id,payload,expires_at,updated_at) VALUES (:id,:account_id,:payload,now() + interval \'30 minutes\',now()) '
                . 'ON CONFLICT (id) DO UPDATE SET account_id=EXCLUDED.account_id,payload=EXCLUDED.payload,expires_at=EXCLUDED.expires_at,updated_at=now()'
            );
            $stmt->execute(['id' => $id, 'account_id' => $accountId, 'payload' => base64_encode($data)]);
            return true;
        } catch (Throwable $error) {
            error_log('Persistent session write failed.');
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = Database::connection()->prepare('DELETE FROM app.sessions WHERE id = :id');
            return $stmt->execute(['id' => $id]);
        } catch (Throwable $error) {
            error_log('Persistent session destroy failed.');
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = Database::connection()->prepare('DELETE FROM app.sessions WHERE expires_at < now() - (:lifetime * interval \'1 second\')');
            $stmt->execute(['lifetime' => $max_lifetime]);
            return $stmt->rowCount();
        } catch (Throwable $error) {
            error_log('Persistent session cleanup failed.');
            return false;
        }
    }
}
