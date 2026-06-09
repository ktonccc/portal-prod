<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class ZumpagoRedirectService
{
    private string $companyCode;
    private string $xmlKey;
    private string $verificationKey;
    private string $initializationVector;
    private string $endpointUrl;
    private string $paymentMethods;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $this->companyCode = $this->requireValue($config, 'company_code');
        $this->xmlKey = $this->requireValue($config, 'xml_key');
        $this->verificationKey = $this->requireValue($config, 'verification_key');
        $this->initializationVector = $this->requireValue($config, 'iv');
        $this->endpointUrl = $this->resolveEndpointUrl($config);

        $paymentMethods = trim((string) ($config['payment_methods'] ?? ''));
        $this->paymentMethods = $paymentMethods !== '' ? $paymentMethods : '016';
    }

    /**
     * @param string[] $documentIds
     * @return array{
     *     endpoint:string,
     *     redirect_url:string,
     *     encrypted_xml:string,
     *     xml:string,
     *     transaction:array{id:string,date:string,time:string,verification_code:string}
     * }
     */
    public function createRedirectData(
        string $normalizedRut,
        int $totalAmount,
        array $documentIds,
        string $email
    ): array {
        if ($totalAmount <= 0) {
            throw new InvalidArgumentException('El monto total debe ser mayor a cero.');
        }

        $date = date('Ymd');
        $time = date('His');
        $transactionId = $this->generateTransactionId($normalizedRut, $documentIds);
        $numCliente = $this->resolveNumCliente($documentIds);

        $padded = $this->padFields([
            'IdComercio' => $this->companyCode,
            'IdTransaccion' => $transactionId,
            'Fecha' => $date,
            'Hora' => $time,
            'MontoTotal' => (string) $totalAmount,
        ]);

        $verificationCode = $this->encrypt(
            implode('', $padded),
            $this->verificationKey,
            $this->initializationVector
        );

        $xml = $this->buildXml([
            'IdComercio' => $this->companyCode,
            'IdTransaccion' => $transactionId,
            'Fecha' => $date,
            'Hora' => $time,
            'NumCliente' => $numCliente,
            'MontoTotal' => (string) $totalAmount,
            'MediosPago' => $this->paymentMethods,
            'CodigoVerificacion' => $verificationCode,
        ]);

        $encryptedXml = $this->encrypt($xml, $this->xmlKey, $this->initializationVector);
        $redirectUrl = $this->buildRedirectUrl($encryptedXml);

        return [
            'endpoint' => $this->endpointUrl,
            'redirect_url' => $redirectUrl,
            'encrypted_xml' => $encryptedXml,
            'xml' => $xml,
            'transaction' => [
                'id' => $transactionId,
                'date' => $date,
                'time' => $time,
                'num_cliente' => $numCliente,
                'verification_code' => $verificationCode,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function resolveEndpointUrl(array $config): string
    {
        $environment = strtolower((string) ($config['environment'] ?? 'production'));
        $urls = (array) ($config['urls'] ?? []);

        $candidates = [
            $environment,
            $environment === 'production' ? 'prod' : null,
            $environment === 'certification' ? 'qa' : null,
            'production',
            'certification',
        ];

        foreach ($candidates as $key) {
            if ($key !== null && isset($urls[$key])) {
                $url = trim((string) $urls[$key]);
                if ($url !== '') {
                    return $this->stripQueryString($url);
                }
            }
        }

        throw new InvalidArgumentException('La configuración de Zumpago requiere una URL válida para el entorno seleccionado.');
    }

    /**
     * @param string[] $documentIds
     */
    private function generateTransactionId(string $normalizedRut, array $documentIds): string
    {
        $cleanIds = [];
        foreach ($documentIds as $documentId) {
            $documentId = trim((string) $documentId);
            if ($documentId !== '') {
                $cleanIds[] = $documentId;
            }
        }

        if (count($cleanIds) > 1) {
            return $this->generateProviderTransactionId();
        }

        if (!empty($cleanIds)) {
            if (strlen($cleanIds[0]) > 13) {
                return $this->generateProviderTransactionId();
            }

            return implode(',', $cleanIds);
        }

        $formattedRut = $this->formatRut($normalizedRut);
        if ($formattedRut !== '') {
            return $formattedRut;
        }

        return trim($normalizedRut);
    }

    private function generateProviderTransactionId(): string
    {
        $milliseconds = (string) ((int) floor(microtime(true) * 1000));
        if (strlen($milliseconds) >= 13) {
            return substr($milliseconds, -13);
        }

        return str_pad($milliseconds, 13, '0', STR_PAD_LEFT);
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,string>
     */
    private function padFields(array $fields): array
    {
        return [
            'IdComercio' => str_pad($fields['IdComercio'], 6, '0', STR_PAD_LEFT),
            'IdTransaccion' => str_pad($fields['IdTransaccion'], 13, '0', STR_PAD_LEFT),
            'Fecha' => str_pad($fields['Fecha'], 8, '0', STR_PAD_LEFT),
            'Hora' => str_pad($fields['Hora'], 6, '0', STR_PAD_LEFT),
            'MontoTotal' => str_pad($fields['MontoTotal'], 12, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * @param array<string,string> $elements
     */
    private function buildXml(array $elements): string
    {
        $xmlParts = [
            '<?xml version="1.0" encoding="ISO-8859-1"?>',
            '<Envio>',
        ];

        foreach ($elements as $tag => $value) {
            if ($value === '') {
                continue;
            }

            $xmlParts[] = sprintf(
                '<%1$s>%2$s</%1$s>',
                $tag,
                $this->escapeXml($value)
            );
        }

        $xmlParts[] = '</Envio>';

        return implode('', $xmlParts);
    }

    private function buildRedirectUrl(string $encryptedXml): string
    {
        $separator = str_contains($this->endpointUrl, '?') ? '&' : '?';

        return $this->endpointUrl . $separator . 'xml=' . urlencode($encryptedXml);
    }

    private function encrypt(string $data, string $key, string $iv): string
    {
        $cipher = 'DES-EDE3';

        $payload = openssl_encrypt(
            $data,
            $cipher,
            $key,
            0,
            $iv
        );

        if ($payload === false) {
            throw new RuntimeException('No fue posible encriptar la información para Zumpago.');
        }

        return $payload;
    }

    private function stripQueryString(string $url): string
    {
        $position = strpos($url, '?');

        if ($position === false) {
            return $url;
        }

        return substr($url, 0, $position);
    }

    private function requireValue(array $config, string $key): string
    {
        $environment = strtolower(trim((string) ($config['environment'] ?? 'production')));
        $rawValue = $config[$key] ?? '';

        if (is_array($rawValue)) {
            $candidates = [
                $environment,
                $environment === 'production' ? 'prod' : null,
                $environment === 'certification' ? 'qa' : null,
                'production',
                'certification',
            ];

            $resolved = '';
            foreach ($candidates as $candidate) {
                if ($candidate === null || !array_key_exists($candidate, $rawValue)) {
                    continue;
                }

                $resolved = trim((string) $rawValue[$candidate]);
                if ($resolved !== '') {
                    break;
                }
            }

            $value = $resolved;
        } else {
            $value = trim((string) $rawValue);
        }

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('La configuración de Zumpago requiere el parámetro "%s".', $key));
        }

        return $value;
    }

    private function formatRut(string $rut): string
    {
        $rut = strtoupper(trim($rut));
        $clean = preg_replace('/[^0-9K]/i', '', $rut) ?? '';

        if ($clean === '') {
            return '';
        }

        $dv = '';
        if (strlen($clean) > 1) {
            $dv = substr($clean, -1);
            $body = substr($clean, 0, -1);
        } else {
            $body = $clean;
        }

        $body = ltrim($body, '0');
        if ($body === '') {
            return $dv !== '' ? '0-' . $dv : '0';
        }

        $chunks = [];
        while (strlen($body) > 3) {
            $chunks[] = substr($body, -3);
            $body = substr($body, 0, -3);
        }
        $chunks[] = $body;

        $formattedBody = implode('.', array_reverse($chunks));

        if ($dv !== '') {
            return $formattedBody . '-' . $dv;
        }

        return $formattedBody;
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES, 'ISO-8859-1');
    }

    /**
     * NumCliente es opcional. Para pagos múltiples usamos un marcador fijo
     * acordado para no perder compatibilidad con el flujo actual.
     *
     * @param string[] $documentIds
     */
    private function resolveNumCliente(array $documentIds): string
    {
        $cleanIds = [];

        foreach ($documentIds as $documentId) {
            $documentId = trim((string) $documentId);
            if ($documentId === '') {
                continue;
            }

            $normalized = preg_replace('/[^0-9A-Za-z]/', '', $documentId) ?? '';
            if ($normalized === '') {
                continue;
            }

            $cleanIds[] = $normalized;
        }

        if (count($cleanIds) > 1) {
            return 'pagoMultiple';
        }

        if (count($cleanIds) === 0) {
            return '';
        }

        return substr($cleanIds[0], 0, 12);
    }
}
