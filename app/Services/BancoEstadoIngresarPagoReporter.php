<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

class BancoEstadoIngresarPagoReporter
{
    public function __construct(
        private readonly string $wsdl
    ) {
        if (trim($this->wsdl) === '') {
            throw new RuntimeException('Debe configurar el WSDL para InBtnPago de BancoEstado.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $metadata
     * @return array<int, array<string, mixed>>
     */
    public function report(array $items, array $metadata): array
    {
        if (empty($items)) {
            return [];
        }

        $client = $this->buildClient();
        $results = [];

        foreach ($items as $item) {
            $contractId = (int) ($item['idcliente'] ?? 0);
            $mes = (int) ($item['mes'] ?? 0);
            $ano = (int) ($item['ano'] ?? 0);
            $monto = (int) ($item['monto'] ?? 0);

            if ($contractId === 0 || $mes === 0 || $ano === 0 || $monto === 0) {
                continue;
            }

            $params = [
                $contractId,
                (string) ($metadata['rut'] ?? ''),
                $mes,
                $ano,
                $monto,
                (string) ($metadata['fecha'] ?? ''),
                (string) ($metadata['hora'] ?? ''),
                (string) ($metadata['code'] ?? ''),
                (string) ($metadata['resultado'] ?? ''),
                (string) ($metadata['modo_pago'] ?? ''),
                (string) ($metadata['marca_tarjeta'] ?? ''),
                (string) ($metadata['numero_cuenta'] ?? ''),
                (string) ($metadata['tipo_tarjeta'] ?? ''),
                (string) ($metadata['emisor'] ?? ''),
                (string) ($metadata['iat'] ?? ''),
                (string) ($metadata['token'] ?? ''),
                (string) ($metadata['jwt'] ?? ''),
            ];

            try {
                $response = $client->__soapCall('InBtnPago', $params);
            } catch (\Throwable $exception) {
                $results[] = [
                    'idcliente' => $contractId,
                    'status' => 'error',
                    'message' => $exception->getMessage(),
                ];
                continue;
            }

            $results[] = [
                'idcliente' => $contractId,
                'status' => 'ok',
                'response' => $response,
            ];
        }

        return $results;
    }

    private function buildClient(): \SoapClient
    {
        try {
            return new \SoapClient($this->wsdl, [
                'trace' => false,
                'exceptions' => true,
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('No fue posible iniciar el cliente SOAP de BancoEstado: ' . $exception->getMessage());
        }
    }
}
