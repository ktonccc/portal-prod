<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class BancoEstadoPaymentService
{
    private string $apiUrl;
    private string $apiKey;
    private string $commerce;
    private string $redirectUrl;
    private string $statusUrl;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->apiUrl = trim((string) ($config['api_url'] ?? ''));
        $this->apiKey = trim((string) ($config['api_key'] ?? ''));
        $this->commerce = trim((string) ($config['commerce'] ?? ''));
        $this->redirectUrl = trim((string) ($config['redirect_url'] ?? ''));
        $this->statusUrl = trim((string) ($config['status_url'] ?? ''));

        if ($this->apiUrl === '' || $this->apiKey === '') {
            throw new RuntimeException('Debe configurar la URL y el API Key de BancoEstado.');
        }

        if ($this->redirectUrl === '' || $this->statusUrl === '') {
            throw new RuntimeException('Debe configurar las URL de retorno y notificación de BancoEstado.');
        }

        if ($this->commerce === '') {
            throw new RuntimeException('Debe especificar el identificador de comercio para BancoEstado.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{raw: string, data: array<string, mixed>}
     */
    public function createIntent(string $orderId, int $total, array $items): array
    {
        if ($orderId === '') {
            throw new RuntimeException('El identificador de la orden de BancoEstado no puede venir vacío.');
        }

        if ($total <= 0) {
            throw new RuntimeException('El total de la orden de BancoEstado debe ser mayor a cero.');
        }

        if (empty($items)) {
            throw new RuntimeException('BancoEstado requiere al menos un ítem para generar la intención de pago.');
        }

        $payload = [
            'comercio' => $this->commerce,
            'oc' => $orderId,
            'total' => $total,
            'url_redirect' => $this->redirectUrl,
            'url_respuesta' => $this->statusUrl,
            'items' => $items,
        ];

        $rawResponse = $this->dispatch($payload);
        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('BancoEstado devolvió una respuesta inválida al generar la intención de pago.');
        }

        return [
            'raw' => $rawResponse,
            'data' => $decoded,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(array $payload): string
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($body === false) {
            throw new RuntimeException('No fue posible preparar la solicitud a BancoEstado.');
        }

        $curl = curl_init($this->apiUrl);
        if ($curl === false) {
            throw new RuntimeException('No fue posible iniciar la conexión hacia BancoEstado.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('BancoEstado no respondió correctamente: ' . $error);
        }

        curl_close($curl);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('BancoEstado rechazó la solicitud (HTTP %d).', $httpCode));
        }

        return $response;
    }
}
