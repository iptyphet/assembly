<?php

declare(strict_types=1);

namespace Assembly\Data;

/**
 * Document store: one JSON file per aggregate, in a subdirectory per
 * aggregate type (proposals/{id}.json, sessions/{id}.json) — the same
 * document-per-aggregate layout as the .NET lane's FileSystemDocumentStore.
 *
 * Writes are atomic (temp file in the same directory + rename) and
 * pretty-printed so the documents stay readable and diff cleanly. There is
 * deliberately NO locking: this sandbox is single-user local play, unlike
 * the .NET lane's AppState mutation gate.
 */
final class Store
{
    public const TYPES = ['proposals', 'sessions'];

    public function __construct(private readonly string $dataDir)
    {
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    /**
     * Load one document, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $type, string $id): ?array
    {
        $file = $this->filePath($type, $id);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * List every document of a type, keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function list(string $type): array
    {
        $this->assertType($type);
        $dir = $this->dataDir . '/' . $type;
        $result = [];
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['id'])) {
                $result[(string) $data['id']] = $data;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function save(string $type, array $data): void
    {
        $dir = $this->dataDir . '/' . $this->assertType($type);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $file = $dir . '/' . $this->assertId((string) $data['id']) . '.json';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = tempnam($dir, '.tmp-');
        if ($tmp === false || file_put_contents($tmp, $json . "\n") === false) {
            throw new \RuntimeException("Cannot write $file");
        }
        rename($tmp, $file);
    }

    public function delete(string $type, string $id): void
    {
        $file = $this->filePath($type, $id);
        if (is_file($file)) {
            unlink($file);
        }
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** Current UTC timestamp as an ISO-8601 string. */
    public static function nowUtc(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function filePath(string $type, string $id): string
    {
        return $this->dataDir . '/' . $this->assertType($type) . '/' . $this->assertId($id) . '.json';
    }

    private function assertType(string $type): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown document type \"$type\"");
        }

        return $type;
    }

    private function assertId(string $id): string
    {
        if (!preg_match('/^[a-z0-9-]+$/', $id)) {
            throw new \InvalidArgumentException("Invalid document id \"$id\"");
        }

        return $id;
    }
}
