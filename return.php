<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Services\IngresarPagoService;
use App\Services\PaymentLoggerService;
use App\Services\WebpayIngresarPagoReporter;
use App\Services\WebpayNormalService;
use App\Services\WebpayTransactionStorage;

$pageTitle = 'Resultado del Pago';
$bodyClass = 'hnet';

$message = '';
$errors = [];
$formAction = '';
$tokenWs = '';
$cancelledMessage = '';
$cancelledDetails = [];

$valueFromRequest = static function (string $key): string {
    foreach ([$_POST, $_GET] as $source) {
        if (!isset($source[$key])) {
            continue;
        }

        $value = $source[$key];

        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }
    }

    return '';
};

$tokenWs = $valueFromRequest('token_ws');
$tbkToken = $valueFromRequest('TBK_TOKEN');
$tbkOrder = $valueFromRequest('TBK_ORDEN_COMPRA');
$tbkSessionId = $valueFromRequest('TBK_ID_SESION');

if ($tokenWs !== '') {

    try {
        $webpay = new WebpayNormalService((array) config_value('webpay', []));
        $result = $webpay->getTransactionResult($tokenWs);
        $output = $result->detailOutput;

        if (is_array($output)) {
            // Normal transaction should return a single object, take the first item if needed.
            $output = $output[0] ?? null;
        }

        $responseCode = null;
        $paymentTypeCode = null;
        $authorizationCode = null;
        $sharesNumber = null;
        $amountValue = null;

        if (is_object($output)) {
            $responseCode = isset($output->responseCode) ? (int) $output->responseCode : null;
            $paymentTypeCode = isset($output->paymentTypeCode) ? (string) $output->paymentTypeCode : null;
            $authorizationCode = isset($output->authorizationCode) ? (string) $output->authorizationCode : null;
            $sharesNumber = isset($output->sharesNumber) ? $output->sharesNumber : null;
            $amountValue = isset($output->amount) ? $output->amount : null;
        }

        if ($responseCode === 0) {
            $formAction = $result->urlRedirection;
            $message = 'Pago procesado correctamente. Estamos generando tu comprobante.';

            try {
                $logger = new PaymentLoggerService((string) config_value('services.payment_logger_wsdl'));
                $logger->log([
                    'BuyOrder' => $result->buyOrder,
                    'CardNumber' => $result->cardDetail->cardNumber ?? '',
                    'AutorizacionCode' => $output->authorizationCode ?? '',
                    'PaymentTypeCode' => $output->paymentTypeCode ?? '',
                    'ResponseCode' => $output->responseCode ?? '',
                    'SharesNumber' => $output->sharesNumber ?? 0,
                    'Monto' => $output->amount ?? 0,
                    'CodigoComercio' => $output->commerceCode ?? '',
                    'TransactionDate' => $result->transactionDate ?? '',
                ]);
            } catch (Throwable $e) {
                // Continuamos el flujo aun cuando no es posible registrar el resultado.
            }
        } else {
            $errors[] = 'El pago fue rechazado por Transbank.';
            if ($responseCode !== null) {
                $errors[] = 'Código de respuesta: ' . $responseCode;
            }
        }

        try {
            $storage = new WebpayTransactionStorage(__DIR__ . '/app/storage/webpay');
            $rawResult = json_decode(json_encode($result, JSON_UNESCAPED_UNICODE), true);
            $rawOutput = json_decode(json_encode($output, JSON_UNESCAPED_UNICODE), true);

            $transactionRecord = $storage->appendResponse($tokenWs, [
                'received_at' => time(),
                'status' => $responseCode === 0 ? 'success' : 'error',
                'detail' => [
                    'response_code' => $responseCode,
                    'authorization_code' => $authorizationCode,
                    'payment_type_code' => $paymentTypeCode,
                    'shares_number' => $sharesNumber,
                    'amount' => $amountValue,
                    'transaction_date' => $result->transactionDate ?? null,
                    'card_number' => $result->cardDetail->cardNumber ?? null,
                    'buy_order' => $result->buyOrder ?? null,
                    'session_id' => $result->sessionId ?? null,
                ],
                'raw' => [
                    'result' => $rawResult,
                    'detail' => $rawOutput,
                ],
            ]);

            if ($responseCode === 0) {
                $rutFromRecord = isset($transactionRecord['rut']) ? (string) $transactionRecord['rut'] : '';
                if ($rutFromRecord === '' && isset($_SESSION['webpay']['last_transaction']['rut'])) {
                    $rutFromRecord = (string) $_SESSION['webpay']['last_transaction']['rut'];
                }

                if ($rutFromRecord !== '') {
                    clear_debt_cache_for_rut($rutFromRecord);
                }
            }

            if ($responseCode === 0) {
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

                    $reporter = new WebpayIngresarPagoReporter(
                        new WebpayTransactionStorage(__DIR__ . '/app/storage/webpay'),
                        new IngresarPagoService($ingresarPagoWsdl),
                        __DIR__ . '/app/logs/webpay-ingresar-pago.log',
                        __DIR__ . '/app/logs/webpay-ingresar-pago-error.log',
                        'WEBPAY',
                        $endpointOverrides
                    );
                    $reporter->report($tokenWs);
                }
            }
        } catch (Throwable $storageException) {
            error_log(
                sprintf(
                    "[%s] [Webpay][storage-error] %s%s",
                    date('Y-m-d H:i:s'),
                    json_encode([
                        'token' => $tokenWs,
                        'context' => 'return',
                        'error' => $storageException->getMessage(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    PHP_EOL
                ),
                3,
                __DIR__ . '/app/logs/webpay.log'
            );
        }
    } catch (Throwable $exception) {
        $errors[] = 'Ocurrió un error al obtener el resultado de la transacción.';
    }
} elseif ($tbkToken !== '') {
    $cancelledMessage = 'El pago fue cancelado antes de completarse. No se realizó ningún cargo a tu tarjeta.';

    if ($tbkOrder !== '') {
        $cancelledDetails['Orden de compra'] = $tbkOrder;
    }
    if ($tbkSessionId !== '') {
        $cancelledDetails['ID de sesión'] = $tbkSessionId;
    }

    $abortResult = null;
    $abortOutput = null;

    try {
        $webpay = new WebpayNormalService((array) config_value('webpay', []));
        $abortResult = $webpay->getTransactionResult($tbkToken);
        $abortOutput = $abortResult->detailOutput;

        if (is_array($abortOutput)) {
            $abortOutput = $abortOutput[0] ?? null;
        }
    } catch (Throwable $exception) {
        error_log(
            sprintf(
                "[%s] [Webpay][return][abort] %s%s",
                date('Y-m-d H:i:s'),
                json_encode([
                    'message' => 'No fue posible consultar el estado de la transacción abortada.',
                    'error' => $exception->getMessage(),
                    'token' => $tbkToken,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            ),
            3,
            __DIR__ . '/app/logs/webpay.log'
        );
    }

    $abortResponseCode = null;
    $abortPaymentType = null;
    $abortAuthorizationCode = null;
    $abortSharesNumber = null;
    $abortAmountValue = null;

    if (is_object($abortOutput)) {
        $abortResponseCode = isset($abortOutput->responseCode) ? (int) $abortOutput->responseCode : null;
        $abortPaymentType = isset($abortOutput->paymentTypeCode) ? (string) $abortOutput->paymentTypeCode : null;
        $abortAuthorizationCode = isset($abortOutput->authorizationCode) ? (string) $abortOutput->authorizationCode : null;
        $abortSharesNumber = $abortOutput->sharesNumber ?? null;
        $abortAmountValue = $abortOutput->amount ?? null;
    }

    $rawAbortResult = $abortResult !== null
        ? json_decode(json_encode($abortResult, JSON_UNESCAPED_UNICODE), true)
        : null;
    $rawAbortOutput = $abortOutput !== null
        ? json_decode(json_encode($abortOutput, JSON_UNESCAPED_UNICODE), true)
        : null;

    $abortTransactionDate = $rawAbortResult['transactionDate'] ?? null;
    $abortCardNumber = $rawAbortResult['cardDetail']['cardNumber'] ?? null;
    $abortBuyOrder = $rawAbortResult['buyOrder'] ?? ($tbkOrder !== '' ? $tbkOrder : null);
    $abortSessionId = $rawAbortResult['sessionId'] ?? ($tbkSessionId !== '' ? $tbkSessionId : null);

    try {
        $storage = new WebpayTransactionStorage(__DIR__ . '/app/storage/webpay');
        $storage->appendResponse($tbkToken, [
            'received_at' => time(),
            'status' => 'aborted',
            'detail' => [
                'response_code' => $abortResponseCode,
                'authorization_code' => $abortAuthorizationCode,
                'payment_type_code' => $abortPaymentType,
                'shares_number' => $abortSharesNumber,
                'amount' => $abortAmountValue,
                'transaction_date' => $abortTransactionDate,
                'card_number' => $abortCardNumber,
                'buy_order' => $abortBuyOrder,
                'session_id' => $abortSessionId,
            ],
            'raw' => [
                'result' => $rawAbortResult,
                'detail' => $rawAbortOutput,
            ],
            'tbk' => [
                'token' => $tbkToken,
                'order' => $tbkOrder !== '' ? $tbkOrder : null,
                'session_id' => $tbkSessionId !== '' ? $tbkSessionId : null,
            ],
        ]);
    } catch (Throwable $storageException) {
        error_log(
            sprintf(
                "[%s] [Webpay][storage-error] %s%s",
                date('Y-m-d H:i:s'),
                json_encode([
                    'token' => $tbkToken,
                    'context' => 'return-abort',
                    'error' => $storageException->getMessage(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                PHP_EOL
            ),
            3,
            __DIR__ . '/app/logs/webpay.log'
        );
    }
} else {
    $errors[] = 'No se recibió un token válido para procesar el pago.';
}

view('layout/header', compact('pageTitle', 'bodyClass'));
?>
    <?php if ($cancelledMessage !== ''): ?>
        <div class="alert alert-warning" role="alert">
            <p class="mb-2"><?= h($cancelledMessage); ?></p>
            <?php if (!empty($cancelledDetails)): ?>
                <ul class="mb-0">
                    <?php foreach ($cancelledDetails as $label => $value): ?>
                        <li><strong><?= h($label); ?>:</strong> <?= h($value); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a href="index.php" class="btn btn-outline-primary mt-3">Volver al inicio</a>
        </div>
    <?php elseif (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <p class="mb-2">No fue posible procesar tu pago.</p>
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= h($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <a href="index.php" class="btn btn-outline-primary mt-3">Volver al inicio</a>
        </div>
    <?php elseif ($formAction !== ''): ?>
        <div class="alert alert-info text-center" role="alert">
            <?= h($message); ?>
        </div>
        <div class="text-center">
            <form id="webpayVoucherForm" action="<?= h($formAction); ?>" method="POST">
                <input type="hidden" name="token_ws" value="<?= h($tokenWs); ?>">
                <button type="submit" class="btn btn-primary">Ver comprobante</button>
            </form>
        </div>
        <script>
            window.addEventListener('load', function () {
                document.getElementById('webpayVoucherForm').submit();
            });
        </script>
    <?php endif; ?>
<?php view('layout/footer'); ?>
