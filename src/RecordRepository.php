<?php

declare(strict_types=1);

namespace Neniu\Capture;

use PDO;
use stdClass;

final class RecordRepository
{
    public function __construct(private PDO $db, private string $storage)
    {
    }

    public function save(array $record, string $bytes): void
    {
        $query = $this->db->prepare('INSERT INTO records
            (code, created_at, latitude, longitude, accuracy_meters, source, mime, sha256, image, metadata)
            VALUES (:code, :created_at, :latitude, :longitude, :accuracy, :source, :mime, :sha256, :image, :metadata)
            ON CONFLICT(code) DO NOTHING');
        $query->bindValue(':code', $record['code']);
        $query->bindValue(':created_at', $record['created_at']);
        $query->bindValue(':latitude', $record['location']['latitude']);
        $query->bindValue(':longitude', $record['location']['longitude']);
        $query->bindValue(':accuracy', $record['location']['accuracy_meters']);
        $query->bindValue(':source', $record['location']['source']);
        $query->bindValue(':mime', $record['image']['mime']);
        $query->bindValue(':sha256', $record['image']['sha256']);
        $query->bindValue(':image', $bytes, PDO::PARAM_LOB);
        $query->bindValue(':metadata', json_encode(
            $record['metadata'] ?? new stdClass(),
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        $query->execute();
    }

    public function find(string $code): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $code)) {
            return null;
        }

        $query = $this->db->prepare('SELECT * FROM records WHERE code = :code');
        $query->execute(['code' => $code]);
        $row = $query->fetch();
        if ($row) {
            return [
                'code' => $row['code'],
                'created_at' => $row['created_at'],
                'metadata' => json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR),
                'location' => [
                    'latitude' => $row['latitude'],
                    'longitude' => $row['longitude'],
                    'accuracy_meters' => $row['accuracy_meters'],
                    'source' => $row['source'],
                ],
                'image' => [
                    'mime' => $row['mime'],
                    'sha256' => $row['sha256'],
                    'base64' => base64_encode($row['image']),
                ],
            ];
        }

        // Import legacy records on first access; keep the original as a backup.
        $path = "{$this->storage}/$code.json";
        if (!is_file($path)) {
            return null;
        }
        $record = json_decode(file_get_contents($path), true);
        if (!is_array($record) || ($record['code'] ?? null) !== $code) {
            return null;
        }
        $bytes = base64_decode($record['image']['base64'] ?? '', true);
        if (
            $bytes === false || !isset(
                $record['created_at'],
                $record['location']['latitude'],
                $record['location']['longitude'],
                $record['location']['accuracy_meters'],
                $record['location']['source'],
                $record['image']['mime'],
                $record['image']['sha256']
            )
        ) {
            return null;
        }
        $this->save($record, $bytes);
        return $record;
    }
}
