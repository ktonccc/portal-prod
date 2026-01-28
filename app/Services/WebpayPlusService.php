<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Transbank\Webpay\Options;
use Transbank\Webpay\WebpayPlus\Transaction;
use Transbank\Webpay\WebpayPlus\Responses\TransactionCommitResponse;
use Transbank\Webpay\WebpayPlus\Responses\TransactionCreateResponse;
use Transbank\Webpay\WebpayPlus\Responses\TransactionStatusResponse;

class WebpayPlusService
{
    private readonly Transaction $transaction;
    private readonly array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->transaction = $this->buildTransaction($config);
    }

    /**
     * @return array{url: string, token: string}
     */
    public function createTransaction(
        int $amount,
        string $buyOrder,
        string $sessionId,
        string $returnUrl
    ): array {
        $response = $this->transaction->create($buyOrder, $sessionId, $amount, $returnUrl);

        $logPath = __DIR__ . '/../../app/logs/webpay.log';
        $rawDump = print_r($response, true);
        error_log('[debug] WebpayPlus create raw response ' . $rawDump . PHP_EOL, 3, $logPath);

        return [
            'url' => (string) $response->getUrl(),
            'token' => (string) $response->getToken(),
        ];
    }

    public function commitTransaction(string $token): TransactionCommitResponse
    {
        $response = $this->transaction->commit($token);

        $logPath = __DIR__ . '/../../app/logs/webpay.log';
        $rawDump = print_r($response, true);
        error_log('[debug] WebpayPlus commit raw response ' . $rawDump . PHP_EOL, 3, $logPath);

        return $response;
    }

    public function getTransactionStatus(string $token): TransactionStatusResponse
    {
        $response = $this->transaction->status($token);

        $logPath = __DIR__ . '/../../app/logs/webpay.log';
        $rawDump = print_r($response, true);
        error_log('[debug] WebpayPlus status raw response ' . $rawDump . PHP_EOL, 3, $logPath);

        return $response;
    }

    private function buildTransaction(array $config): Transaction
    {
        $environment = strtoupper((string) ($config['environment'] ?? ''));
        $commerceCode = trim((string) ($config['commerce_code'] ?? ''));
        $apiKey = trim((string) ($config['api_key'] ?? ''));

        if ($commerceCode === '') {
            throw new RuntimeException('Debe configurar el código de comercio de Webpay Plus.');
        }

        if ($apiKey === '') {
            throw new RuntimeException('Debe configurar la apiKey de Webpay Plus.');
        }

        $useIntegration = in_array($environment, ['INTEGRACION', 'INTEGRATION', 'TEST'], true);

        if ($useIntegration) {
            return Transaction::buildForIntegration($apiKey, $commerceCode);
        }

        return Transaction::buildForProduction($apiKey, $commerceCode);
    }
}
