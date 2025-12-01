<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\BancoEstadoJwt;

$jwt = trim((string) ($_GET['id'] ?? ''));

if ($jwt === '') {
    header('Location: index.php');
    exit;
}

$config = (array) config_value('bancoestado', []);
$secret = (string) ($config['jwt_secret'] ?? '');
$payload = null;
$errorMessage = null;

try {
    $decoder = new BancoEstadoJwt();
    $payload = $decoder->decode($jwt, $secret);
} catch (Throwable $exception) {
    $errorMessage = 'No fue posible validar la información de tu pago.';
    error_log('[BancoEstado][result] ' . $exception->getMessage());
}

if ($payload === null) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Resumen de pago BancoEstado';
$bodyClass = 'hnet';
$items = $payload['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}

$items = array_map(
    static function ($item): array {
        if (!is_array($item)) {
            return [];
        }

        if (!isset($item['servicio']) && isset($item['nombre'])) {
            $parts = explode('-', (string) $item['nombre'], 4);
            if (count($parts) === 4) {
                $item['idcliente'] = $parts[0];
                $item['servicio'] = $parts[1];
                $item['mes'] = $parts[2];
                $item['ano'] = $parts[3];
            }
        }

        return $item;
    },
    $items
);

view('layout/header', compact('pageTitle', 'bodyClass'));
view('bancoestado/result', [
    'payload' => $payload,
    'items' => $items,
    'errorMessage' => $errorMessage,
]);
view('layout/footer');
