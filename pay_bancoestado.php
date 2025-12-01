<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\BancoEstadoPaymentService;
use App\Services\BancoEstadoTransactionStorage;
use App\Services\DebtService;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['message' => 'Método no permitido.']);
}

$requestData = getRequestData();
$rutInput = trim((string) ($requestData['rut'] ?? ''));
$email = trim((string) ($requestData['email'] ?? ''));
$selectedIds = normalizeSelection($requestData['idcliente'] ?? null);
$errors = [];

$normalizedRut = normalize_rut($rutInput);

if ($normalizedRut === '' || !is_valid_rut($normalizedRut)) {
    $errors[] = 'El RUT recibido no es válido.';
}

if (empty($selectedIds)) {
    $errors[] = 'Debe seleccionar al menos una deuda.';
}

if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $errors[] = 'Debe ingresar un correo electrónico válido.';
}

if (!empty($errors)) {
    respond(422, ['errors' => $errors]);
}

$availableDebts = getSnapshotOrFetch($normalizedRut);

if (empty($availableDebts)) {
    respond(404, ['errors' => ['No fue posible obtener las deudas del cliente.']]);
}

[$selectedDebts, $selectionErrors] = filterSelectedDebts($selectedIds, $availableDebts);

if (!empty($selectionErrors)) {
    respond(422, ['errors' => $selectionErrors]);
}

$totalAmount = calculateTotalAmount($selectedDebts);

if ($totalAmount <= 0) {
    respond(422, ['errors' => ['El monto total a pagar debe ser mayor a cero.']]);
}

$preparedItems = prepareBancoEstadoItems($selectedDebts);
$apiItems = array_map(
    static fn (array $item): array => [
        'nombre' => $item['nombre'],
        'valor' => $item['valor'],
    ],
    $preparedItems
);

$config = (array) config_value('bancoestado', []);

try {
    $storage = new BancoEstadoTransactionStorage(__DIR__ . '/app/storage/bancoestado');
    $record = $storage->create([
        'rut' => $normalizedRut,
        'email' => $email,
        'selected_ids' => $selectedIds,
        'debts' => $selectedDebts,
        'amount' => $totalAmount,
        'items' => $preparedItems,
        'status' => 'initiated',
    ]);

    $orderId = (string) $record['order_id'];
    $service = new BancoEstadoPaymentService($config);
    $intent = $service->createIntent($orderId, $totalAmount, $apiItems);

    $storage->merge($orderId, [
        'intent' => $intent,
    ]);

    $storage->appendHistory($orderId, [
        'event' => 'intent-created',
        'details' => $intent['data'] ?? [],
    ]);

    logBancoEstado('intent-created', [
        'order_id' => $orderId,
        'rut' => $normalizedRut,
        'amount' => $totalAmount,
        'selected_ids' => $selectedIds,
    ]);

    respond(200, [
        'ok' => true,
        'order_id' => $orderId,
        'intent_payload' => $intent['raw'],
        'intent' => $intent['data'],
    ]);
} catch (Throwable $exception) {
    logBancoEstado('intent-error', [
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
        'selected_ids' => $selectedIds,
        'rut' => $normalizedRut,
    ]);
    respond(500, ['message' => 'No fue posible iniciar el pago con BancoEstado.']);
}

/**
 * @return array<string, mixed>
 */
function getRequestData(): array
{
    $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));

    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/**
 * @param mixed $selection
 * @return array<int, string>
 */
function normalizeSelection(mixed $selection): array
{
    $values = [];

    if (is_array($selection)) {
        foreach ($selection as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    if (is_string($selection) || is_numeric($selection)) {
        $value = trim((string) $selection);
        if ($value !== '') {
            return [$value];
        }
    }

    return [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function getSnapshotOrFetch(string $rut): array
{
    $snapshot = get_debt_snapshot($rut);

    if ($snapshot !== null) {
        return $snapshot;
    }

    try {
        $service = new DebtService(
            (string) config_value('services.debt_wsdl'),
            (string) config_value('services.debt_wsdl_fallback')
        );
        $debts = $service->fetchDebts($rut);
        store_debt_snapshot($rut, $debts);

        return $debts;
    } catch (Throwable $exception) {
        error_log('[BancoEstado][debt-fetch] ' . $exception->getMessage());
    }

    return [];
}

/**
 * @param array<int, string> $selectedIds
 * @param array<int, array<string, mixed>> $available
 * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
 */
function filterSelectedDebts(array $selectedIds, array $available): array
{
    $selected = [];
    $errors = [];

    foreach ($selectedIds as $idCliente) {
        $match = null;
        foreach ($available as $debt) {
            if ((string) ($debt['idcliente'] ?? '') === $idCliente) {
                $match = $debt;
                break;
            }
        }

        if ($match === null) {
            $errors[] = sprintf('No se encontró la deuda %s. Vuelve a consultar e inténtalo nuevamente.', $idCliente);
        } else {
            $selected[] = $match;
        }
    }

    return [$selected, $errors];
}

/**
 * @param array<int, array<string, mixed>> $debts
 */
function calculateTotalAmount(array $debts): int
{
    $total = 0;

    foreach ($debts as $debt) {
        $amount = (int) ($debt['amount'] ?? $debt['deuda'] ?? 0);
        $total += max(0, $amount);
    }

    return $total;
}

/**
 * @param array<int, array<string, mixed>> $debts
 * @return array<int, array<string, mixed>>
 */
function prepareBancoEstadoItems(array $debts): array
{
    $items = [];

    foreach ($debts as $debt) {
        $idCliente = (string) ($debt['idcliente'] ?? '');
        if ($idCliente === '') {
            continue;
        }

        $amount = (int) ($debt['amount'] ?? $debt['deuda'] ?? 0);
        if ($amount <= 0) {
            continue;
        }

        $servicio = resolveServiceName($debt);
        $mes = formatTwoDigits($debt['mes'] ?? '');
        $ano = formatYear($debt['ano'] ?? '');

        $items[] = [
            'idcliente' => $idCliente,
            'servicio' => $servicio,
            'mes' => $mes,
            'ano' => $ano,
            'monto' => $amount,
            'valor' => $amount,
            'nombre' => sprintf(
                '%s-%s-%s-%s',
                $idCliente,
                $servicio !== '' ? str_replace('-', ' ', $servicio) : 'SERVICIO',
                $mes !== '' ? $mes : '0',
                $ano !== '' ? $ano : '0'
            ),
        ];
    }

    return $items;
}

/**
 * @param array<string, mixed> $debt
 */
function resolveServiceName(array $debt): string
{
    $value = $debt['servicio'] ?? null;

    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if (isset($debt['mes'])) {
        return 'SERVICIOS DEL MES';
    }

    return 'SERVICIOS';
}

function formatTwoDigits(mixed $value): string
{
    $digits = preg_replace('/\\D/', '', (string) $value);
    if ($digits === null || $digits === '') {
        return '';
    }

    $number = (int) $digits;

    return $number > 0 ? str_pad((string) $number, 2, '0', STR_PAD_LEFT) : '';
}

function formatYear(mixed $value): string
{
    $digits = preg_replace('/\\D/', '', (string) $value);
    if ($digits === null || $digits === '') {
        return '';
    }

    if (strlen($digits) === 2) {
        $digits = '20' . $digits;
    }

    return $digits;
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
function logBancoEstado(string $event, array $context = []): void
{
    $entry = [
        'timestamp' => date('c'),
        'event' => $event,
        'context' => $context,
    ];

    error_log(
        '[BancoEstado][pay] ' . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        3,
        __DIR__ . '/app/logs/bancoestado.log'
    );
}
