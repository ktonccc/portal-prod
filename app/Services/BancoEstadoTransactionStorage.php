<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class BancoEstadoTransactionStorage
{
    public function __construct(
        private readonly string $directory
    ) {
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): array
    {
        $orderId = $this->generateOrderId();
        $record = array_merge(
            [
                'order_id' => $orderId,
                'created_at' => time(),
                'history' => [],
            ],
            $attributes
        );

        $this->write($orderId, $record);

        return $record;
    }

    public function get(string $orderId): ?array
    {
        $path = $this->pathFor($orderId);

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function merge(string $orderId, array $attributes): ?array
    {
        $current = $this->get($orderId);

        if ($current === null) {
            return null;
        }

        $merged = $this->recursiveMerge($current, $attributes);
        $this->write($orderId, $merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function appendHistory(string $orderId, array $entry): void
    {
        $entry['timestamp'] = $entry['timestamp'] ?? time();
        $record = $this->get($orderId);

        if ($record === null) {
            $record = [
                'order_id' => $orderId,
                'created_at' => time(),
                'history' => [],
            ];
        }

        if (!isset($record['history']) || !is_array($record['history'])) {
            $record['history'] = [];
        }

        $record['history'][] = $entry;
        $this->write($orderId, $record);
    }

    private function generateOrderId(): string
    {
        $attempts = 0;

        do {
            $attempts++;
            $id = sprintf(
                'HNBE-%s-%s',
                date('YmdHis'),
                substr(bin2hex(random_bytes(6)), 0, 6)
            );
        } while (file_exists($this->pathFor($id)) && $attempts < 5);

        if ($attempts >= 5 && file_exists($this->pathFor($id))) {
            throw new RuntimeException('No fue posible generar un identificador único para BancoEstado.');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $orderId, array $data): void
    {
        $this->ensureDirectory();

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($encoded === false) {
            throw new RuntimeException('No fue posible serializar la transacción de BancoEstado.');
        }

        $path = $this->pathFor($orderId);

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException("No fue posible guardar la transacción de BancoEstado en {$path}.");
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException("No fue posible crear el directorio {$this->directory} para BancoEstado.");
        }
    }

    private function pathFor(string $orderId): string
    {
        $hash = hash('sha256', $orderId);

        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function recursiveMerge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (is_array($value) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = $this->recursiveMerge($left[$key], $value);
                continue;
            }

            $left[$key] = $value;
        }

        return $left;
    }
}
