<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class BancoEstadoJwt
{
    /**
     * @return array<string, mixed>
     */
    public function decode(string $token, string $secret): array
    {
        if ($token === '') {
            throw new RuntimeException('El JWT de BancoEstado no puede ser vacío.');
        }

        if ($secret === '') {
            throw new RuntimeException('No se configuró la llave secreta para validar el JWT de BancoEstado.');
        }

        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new RuntimeException('El JWT recibido desde BancoEstado es inválido.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;
        $header = $this->jsonDecodeSegment($encodedHeader);
        $payload = $this->jsonDecodeSegment($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);

        $algorithm = strtoupper((string) ($header['alg'] ?? ''));
        if ($algorithm !== 'HS256') {
            throw new RuntimeException('BancoEstado envió un algoritmo no soportado (' . $algorithm . ').');
        }

        $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);

        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('La firma del JWT de BancoEstado no es válida.');
        }

        if (!is_array($payload)) {
            throw new RuntimeException('No fue posible decodificar el contenido del JWT de BancoEstado.');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonDecodeSegment(string $segment): ?array
    {
        $decoded = json_decode($this->base64UrlDecode($segment), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new RuntimeException('No fue posible decodificar la cadena base64url.');
        }

        return $decoded;
    }
}
