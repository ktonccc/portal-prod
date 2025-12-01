<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\BancoEstadoIngresarPagoReporter;
use App\Services\BancoEstadoJwt;
use App\Services\BancoEstadoTransactionStorage;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['message' => 'Método no permitido.']);
}

$jwt = trim((string) ($_POST['JWT'] ?? readJsonField('JWT')));

if ($jwt === '') {
    respond(422, ['message' => 'Falta el JWT entregado por BancoEstado.']);
}

$config = (array) config_value('bancoestado', []);
$secret = (string) ($config['jwt_secret'] ?? '');

try {
    $decoder = new BancoEstadoJwt();
    $payload = $decoder->decode($jwt, $secret);
} catch (Throwable $exception) {
    logEvent('decode-error', ['error' => $exception->getMessage()]);
    respond(400, ['message' => 'No fue posible validar la información recibida.']);
}

$orderId = (string) ($payload['oc'] ?? '');
if ($orderId === '') {
    respond(422, ['message' => 'No se encontró el identificador de la orden en el JWT.']);
}

$storage = new BancoEstadoTransactionStorage(__DIR__ . '/app/storage/bancoestado');
$record = $storage->merge($orderId, [
    'status' => strtolower((string) ($payload['resultado'] ?? '')),
    'callback' => [
        'received_at' => time(),
        'jwt' => $jwt,
        'payload' => $payload,
    ],
]);

if ($record === null) {
    respond(404, ['message' => 'No se encontró la orden asociada al pago.']);
}

$metadata = collectMetadata($record, $payload, $jwt);
$ingresarPagoResults = [];

if ($metadata['resultado'] === 'ok') {
    try {
        $reporter = new BancoEstadoIngresarPagoReporter((string) ($config['wsdl'] ?? ''));
        $ingresarPagoResults = $reporter->report(
            resolveItemsForReport($record, $payload),
            $metadata
        );
    } catch (Throwable $exception) {
        logEvent('ingresar-pago-error', [
            'order_id' => $orderId,
            'error' => $exception->getMessage(),
        ]);
    }
}

$storage->appendHistory($orderId, [
    'event' => 'callback',
    'details' => [
        'payload' => $payload,
        'ingresar_pago' => $ingresarPagoResults,
    ],
]);

respond(200, ['msg' => 'ok']);

/**
 * @return array<string, mixed>
 */
function collectMetadata(array $record, array $payload, string $jwt): array
{
    $numeroCuenta = $payload['numero_cuenta'] ?? ($payload['numeroCuenta'] ?? '');

    return [
        'rut' => (string) ($record['rut'] ?? ''),
        'fecha' => (string) ($payload['fecha'] ?? ''),
        'hora' => (string) ($payload['hora'] ?? ''),
        'code' => (string) ($payload['code'] ?? ''),
        'resultado' => strtolower((string) ($payload['resultado'] ?? '')),
        'modo_pago' => (string) ($payload['modoPago'] ?? ''),
        'marca_tarjeta' => (string) ($payload['marcaTarjeta'] ?? ''),
        'numero_cuenta' => (string) $numeroCuenta,
        'tipo_tarjeta' => (string) ($payload['tipoTarjeta'] ?? ''),
        'emisor' => (string) ($payload['emisor'] ?? ''),
        'iat' => (string) ($payload['iat'] ?? ''),
        'token' => (string) ($payload['token'] ?? ''),
        'jwt' => $jwt,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function resolveItemsForReport(array $record, array $payload): array
{
    $items = [];
    $stored = [];

    if (isset($record['items']) && is_array($record['items'])) {
        foreach ($record['items'] as $item) {
            if (!isset($item['idcliente'])) {
                continue;
            }
            $stored[(string) $item['idcliente']] = $item;
        }
    }

    $payloadItems = $payload['items'] ?? [];
    if (!is_array($payloadItems)) {
        $payloadItems = [];
    }

    foreach ($payloadItems as $item) {
        $parsed = parseItemSignature((string) ($item['nombre'] ?? ''));
        $idCliente = $parsed['idcliente'] ?? null;

        if ($idCliente === null) {
            continue;
        }

        $fallback = $stored[$idCliente] ?? [];
        $items[] = [
            'idcliente' => $idCliente,
            'mes' => $parsed['mes'] ?? ($fallback['mes'] ?? ''),
            'ano' => $parsed['ano'] ?? ($fallback['ano'] ?? ''),
            'monto' => (int) ($item['valor'] ?? ($fallback['monto'] ?? 0)),
        ];
    }

    if (empty($items)) {
        foreach ($stored as $storedItem) {
            $items[] = [
                'idcliente' => (string) ($storedItem['idcliente'] ?? ''),
                'mes' => $storedItem['mes'] ?? '',
                'ano' => $storedItem['ano'] ?? '',
                'monto' => (int) ($storedItem['monto'] ?? 0),
            ];
        }
    }

    return $items;
}

/**
 * @return array<string, string>|array{}
 */
function parseItemSignature(string $signature): array
{
    if ($signature === '') {
        return [];
    }

    $parts = explode('-', $signature, 4);
    if (count($parts) < 4) {
        return [];
    }

    return [
        'idcliente' => $parts[0],
        'servicio' => $parts[1],
        'mes' => $parts[2],
        'ano' => $parts[3],
    ];
}

function readJsonField(string $key): ?string
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);

    if (!is_array($decoded)) {
        return null;
    }

    $value = $decoded[$key] ?? null;

    if ($value === null) {
        return null;
    }

    return trim((string) $value);
}

/**
 * @param array<string, mixed> $payload
 */
function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * @param array<string, mixed> $context
 */
function logEvent(string $event, array $context = []): void
{
    $entry = [
        'timestamp' => date('c'),
        'event' => $event,
        'context' => $context,
    ];

    error_log(
        '[BancoEstado][status] ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        3,
        __DIR__ . '/app/logs/bancoestado.log'
    );
}
