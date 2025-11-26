<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class MercadoPagoTransactionStorage
{
    private const PROCESSING_TTL_SECONDS = 600;

    public function __construct(
        private readonly string $directory
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $transactionId, array $data): void
    {
        $data['transaction_id'] = $transactionId;

        $data['ingresar_pago'] = $this->normalizeIngresarPagoMeta($data['ingresar_pago'] ?? null);
        $data['mercadopago'] = $this->normalizeMercadoPagoMeta($data['mercadopago'] ?? null);

        $this->writeFile($transactionId, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $transactionId): ?array
    {
        $path = $this->pathForId($transactionId);

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
    public function merge(string $transactionId, array $attributes): ?array
    {
        $existing = $this->get($transactionId);
        if ($existing === null) {
            return null;
        }

        $merged = $this->recursiveMerge($existing, $attributes);
        $this->writeFile($transactionId, $merged);

        return $merged;
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function appendResponse(string $transactionId, array $response): array
    {
        $record = $this->get($transactionId);

        if ($record === null) {
            $record = [
                'transaction_id' => $transactionId,
                'created_at' => time(),
                'ingresar_pago' => $this->normalizeIngresarPagoMeta(null),
                'mercadopago' => $this->normalizeMercadoPagoMeta(null),
            ];
        }

        $record['mercadopago'] = $this->normalizeMercadoPagoMeta($record['mercadopago']);

        $record['mercadopago']['responses'][] = $response;
        $this->writeFile($transactionId, $record);

        return $record;
    }

    public function startIngresarPagoProcessing(string $transactionId): bool
    {
        return $this->withIngresarPagoLock($transactionId, function () use ($transactionId) {
            $record = $this->get($transactionId);
            if ($record === null) {
                return false;
            }

            $meta = $this->normalizeIngresarPagoMeta($record['ingresar_pago'] ?? null);

            if (!empty($meta['processed'])) {
                return false;
            }

            if (!empty($meta['processing'])) {
                $startedAt = (int) ($meta['processing_started_at'] ?? 0);
                if ($startedAt > 0 && (time() - $startedAt) <= self::PROCESSING_TTL_SECONDS) {
                    return false;
                }
            }

            $meta['processing'] = true;
            $meta['processing_started_at'] = time();
            $record['ingresar_pago'] = $meta;
            $this->writeFile($transactionId, $record);

            return true;
        });
    }

    public function resetIngresarPagoProcessing(string $transactionId): void
    {
        $this->withIngresarPagoLock($transactionId, function () use ($transactionId) {
            $record = $this->get($transactionId);
            if ($record === null) {
                return;
            }

            $meta = $this->normalizeIngresarPagoMeta($record['ingresar_pago'] ?? null);
            $meta['processing'] = false;
            unset($meta['processing_started_at']);
            $record['ingresar_pago'] = $meta;
            $this->writeFile($transactionId, $record);
        });
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function markProcessed(string $transactionId, array $meta): void
    {
        $updated = $this->withIngresarPagoLock($transactionId, function () use ($transactionId, $meta) {
            $record = $this->get($transactionId);
            if ($record === null) {
                return null;
            }

            $ingresarMeta = $this->normalizeIngresarPagoMeta($record['ingresar_pago'] ?? null);
            $ingresarMeta = $this->recursiveMerge($ingresarMeta, $meta);
            $ingresarMeta = $this->recursiveMerge($ingresarMeta, [
                'processed' => true,
                'processed_at' => time(),
                'processing' => false,
                'processing_finished_at' => time(),
            ]);

            unset($ingresarMeta['processing_started_at']);

            $record['ingresar_pago'] = $ingresarMeta;
            $this->writeFile($transactionId, $record);

            return $record;
        });

        if ($updated === null) {
            throw new RuntimeException("No se encontró la transacción Mercado Pago asociada al ID {$transactionId} para marcarla como procesada.");
        }
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

    /**
     * @param array<string, mixed> $data
     */
    private function writeFile(string $transactionId, array $data): void
    {
        $this->ensureDirectory();

        $encoded = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if ($encoded === false) {
            throw new RuntimeException('No fue posible codificar la transacción de Mercado Pago en JSON.');
        }

        $path = $this->pathForId($transactionId);

        if (file_put_contents($path, $encoded, LOCK_EX) === false) {
            throw new RuntimeException("No fue posible escribir la transacción Mercado Pago en {$path}.");
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->directory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException("No fue posible crear el directorio {$this->directory} para almacenar transacciones Mercado Pago.");
        }
    }

    private function pathForId(string $transactionId): string
    {
        $hash = hash('sha256', $transactionId);

        return rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    /**
     * @param array<string, mixed>|null $meta
     * @return array<string, mixed>
     */
    private function normalizeIngresarPagoMeta(mixed $meta): array
    {
        if (!is_array($meta)) {
            $meta = [];
        }

        if (!isset($meta['processed'])) {
            $meta['processed'] = false;
        }

        if (!isset($meta['processing'])) {
            $meta['processing'] = false;
        }

        if (!isset($meta['attempts']) || !is_array($meta['attempts'])) {
            $meta['attempts'] = [];
        }

        if (!isset($meta['responses']) || !is_array($meta['responses'])) {
            $meta['responses'] = [];
        }

        return $meta;
    }

    /**
     * @param array<string, mixed>|null $meta
     * @return array<string, mixed>
     */
    private function normalizeMercadoPagoMeta(mixed $meta): array
    {
        if (!is_array($meta)) {
            $meta = [];
        }

        if (!isset($meta['responses']) || !is_array($meta['responses'])) {
            $meta['responses'] = [];
        }

        return $meta;
    }

    /**
     * @return mixed
     */
    private function withIngresarPagoLock(string $transactionId, callable $callback)
    {
        $this->ensureDirectory();
        $lockPath = $this->pathForId($transactionId) . '.lock';
        $handle = fopen($lockPath, 'c');

        if ($handle === false) {
            throw new RuntimeException("No fue posible abrir el candado para la transacción {$transactionId}.");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException("No fue posible bloquear la transacción {$transactionId} para evitar reprocesos simultáneos.");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
