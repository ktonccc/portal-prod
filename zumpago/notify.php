<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\IngresarPagoService;
use App\Services\ZumpagoConfigResolver;
use App\Services\ZumpagoIngresarPagoReporter;
use App\Services\ZumpagoResponseService;
use App\Services\ZumpagoTransactionStorage;

header('Content-Type: text/plain');

$rawBody = file_get_contents('php://input') ?: '';
$rawBodyTrimmed = trim($rawBody);
$headers = function_exists('getallheaders') ? getallheaders() : [];
$logPath = __DIR__ . '/../app/logs/zumpago.log';

// Conservamos el log plano para no perder trazabilidad histórica.
$legacyLogEntry = [
    'timestamp' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'query' => $_GET,
    'body' => $_POST,
    'headers' => $headers,
    'raw' => $rawBody,
];

try {
    $json = json_encode($legacyLogEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        file_put_contents($logPath, $json . PHP_EOL, FILE_APPEND);
    }
} catch (Throwable) {
    // No interrumpimos la respuesta si el log tradicional falla.
}

$encryptedXmlParam = (string) ($_REQUEST['xml'] ?? '');

if ($encryptedXmlParam === '' && $rawBodyTrimmed !== '') {
    $parsedBody = [];
    parse_str($rawBodyTrimmed, $parsedBody);
    if (isset($parsedBody['xml'])) {
        $encryptedXmlParam = (string) $parsedBody['xml'];
    } else {
        $encryptedXmlParam = $rawBodyTrimmed;
    }
}

$encryptedXmlParam = trim($encryptedXmlParam);
if ($encryptedXmlParam !== '' && preg_match('/%[0-9A-Fa-f]{2}/', $encryptedXmlParam) === 1) {
    $encryptedXmlParam = urldecode($encryptedXmlParam);
}

$notifyLog = [
    'received_at' => gmdate('c'),
    'request' => [
        'get' => $_GET,
        'post' => $_POST,
        'headers' => $headers,
    ],
    'raw_body' => $rawBodyTrimmed !== '' ? $rawBodyTrimmed : null,
    'xml_present' => $encryptedXmlParam !== '',
    'errors' => [],
];

if ($encryptedXmlParam !== '') {
    try {
        $config = (array) config_value('zumpago', []);
        $resolver = new ZumpagoConfigResolver($config);
        $profilesToTry = $resolver->getProfiles();
        if (empty($profilesToTry)) {
            throw new RuntimeException('No hay perfiles configurados para Zumpago.');
        }

        $parsedResponse = null;
        $activeProfile = null;
        $lastParseException = null;

        foreach ($profilesToTry as $profileCandidate) {
            try {
                $service = new ZumpagoResponseService($profileCandidate);
                $parsedResponse = $service->parseResponse($encryptedXmlParam);
                $activeProfile = $profileCandidate;
                break;
            } catch (Throwable $parseException) {
                $lastParseException = $parseException;
            }
        }

        if ($parsedResponse === null || $activeProfile === null) {
            if ($lastParseException instanceof Throwable) {
                throw $lastParseException;
            }

            throw new RuntimeException('No fue posible interpretar la notificación de Zumpago.');
        }

        $idComercio = trim((string) ($parsedResponse['data']['IdComercio'] ?? ''));
        if ($idComercio !== '') {
            $profileByCommerce = $resolver->resolveByCommerceCode($idComercio);
            $currentKey = ($activeProfile['company_id'] ?? '') . '|' . ($activeProfile['company_code'] ?? '');
            $resolvedKey = ($profileByCommerce['company_id'] ?? '') . '|' . ($profileByCommerce['company_code'] ?? '');

            if ($currentKey !== $resolvedKey) {
                $activeProfile = $profileByCommerce;
                $parsedResponse = (new ZumpagoResponseService($activeProfile))->parseResponse($encryptedXmlParam);
            }
        }

        $activeCompanyId = (string) ($activeProfile['company_id'] ?? '');
        $notifyLog['company_id'] = $activeCompanyId !== '' ? $activeCompanyId : null;

        $parsedResponseData = $parsedResponse['data'] ?? [];
        $transactionId = trim((string) ($parsedResponseData['IdTransaccion'] ?? ''));
        if ($transactionId === '') {
            throw new RuntimeException('La notificación no incluye un IdTransaccion válido.');
        }

        $responseCode = str_pad(
            trim((string) ($parsedResponseData['CodigoRespuesta'] ?? '')),
            3,
            '0',
            STR_PAD_LEFT
        );
        $responseDescription = trim((string) ($parsedResponseData['DescripcionRespuesta'] ?? ''));
        $processedAt = trim((string) ($parsedResponseData['FechaProcesamiento'] ?? ''));
        $amountValue = trim((string) ($parsedResponseData['MontoTotal'] ?? ''));
        $responseAmount = $amountValue !== '' ? (int) preg_replace('/\D/', '', $amountValue) : null;

        $notifyLog['transaction_id'] = $transactionId;
        $notifyLog['code'] = $responseCode !== '' ? $responseCode : null;
        $notifyLog['description'] = $responseDescription !== '' ? $responseDescription : null;

        $storage = new ZumpagoTransactionStorage(__DIR__ . '/../app/storage/zumpago');
        $storage->appendResponse($transactionId, [
            'received_at' => time(),
            'context' => 'notify',
            'status' => $responseCode === '000' ? 'success' : ($responseCode !== '' ? 'error' : null),
            'code' => $responseCode !== '' ? $responseCode : null,
            'description' => $responseDescription !== '' ? $responseDescription : null,
            'amount' => $responseAmount,
            'processed_at' => $processedAt !== '' ? $processedAt : null,
            'raw' => [
                'encrypted_xml' => $encryptedXmlParam,
                'decrypted_xml' => $parsedResponse['xml'],
                'parsed' => $parsedResponseData,
            ],
            'verification' => $parsedResponse['verification'],
            'errors' => [],
            'request' => [
                'get' => $_GET,
                'post' => $_POST,
            ],
        ]);

        $notifyLog['stored'] = true;

        $shouldNotifyIngresarPago = $responseCode === '000';
        if ($shouldNotifyIngresarPago) {
            try {
                $ingresarPagoWsdl = (string) config_value('services.ingresar_pago_wsdl', '');
                if (trim($ingresarPagoWsdl) !== '') {
                    $villarricaWsdl = trim((string) config_value('services.ingresar_pago_wsdl_villarrica', ''));
                    if ($villarricaWsdl === '') {
                        $villarricaWsdl = $ingresarPagoWsdl;
                    }

                    $gorbeaWsdl = trim((string) config_value('services.ingresar_pago_wsdl_gorbea', ''));
                    if ($gorbeaWsdl === '') {
                        $gorbeaWsdl = $ingresarPagoWsdl;
                    }

                    $endpointOverrides = [
                        '764430824' => $ingresarPagoWsdl,
                        '765316081' => $villarricaWsdl,
                        '76734662K' => $gorbeaWsdl,
                    ];

                    $reporter = new ZumpagoIngresarPagoReporter(
                        new ZumpagoTransactionStorage(__DIR__ . '/../app/storage/zumpago'),
                        new IngresarPagoService($ingresarPagoWsdl),
                        __DIR__ . '/../app/logs/zumpago-ingresar-pago.log',
                        __DIR__ . '/../app/logs/zumpago-ingresar-pago-error.log',
                        'ZUMPAGO',
                        $endpointOverrides
                    );
                    $reporter->report($transactionId);
                    $notifyLog['ingresar_pago_reported'] = true;
                }
            } catch (Throwable $reporterException) {
                $notifyLog['errors'][] = 'IngresarPago: ' . $reporterException->getMessage();
                error_log(
                    sprintf(
                        "[%s] [Zumpago][IngresarPago][notify-error] %s%s",
                        date('Y-m-d H:i:s'),
                        json_encode([
                            'transaction_id' => $transactionId,
                            'code' => $responseCode,
                            'error' => $reporterException->getMessage(),
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        PHP_EOL
                    ),
                    3,
                    __DIR__ . '/../app/logs/zumpago-ingresar-pago-error.log'
                );
            }
        }
    } catch (Throwable $exception) {
        $notifyLog['errors'][] = $exception->getMessage();
    }
} else {
    $notifyLog['errors'][] = 'La notificación no incluyó el parámetro "xml".';
}

$structuredLogMessage = sprintf(
    "[%s] [Zumpago][notify] %s%s",
    date('Y-m-d H:i:s'),
    json_encode($notifyLog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    PHP_EOL
);

error_log($structuredLogMessage, 3, $logPath);

http_response_code(200);
echo 'OK';
