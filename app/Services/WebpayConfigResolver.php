<?php

declare(strict_types=1);

namespace App\Services;

class WebpayConfigResolver
{
    /** @var array<string, mixed> */
    private array $sharedConfig = [];

    /** @var array<string, array<string, mixed>> */
    private array $profilesByCompanyId = [];

    /** @var array<string|int, array<string, mixed>> */
    private array $profiles = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->sharedConfig = $this->extractSharedConfig($config);

        $companies = (array) ($config['companies'] ?? []);
        foreach ($companies as $id => $profileConfig) {
            if (!is_array($profileConfig)) {
                continue;
            }

            $profile = $this->buildProfile((string) $id, $profileConfig);
            $companyId = $profile['company_id'] ?? null;

            if (is_string($companyId) && $companyId !== '') {
                $this->profilesByCompanyId[$companyId] = $profile;
            }

            $this->storeProfile($profile);
        }

    }

    /**
     * @return array<string, mixed>
     */
    public function resolveByCompanyId(?string $companyId): array
    {
        $normalizedId = $this->normalizeCompanyId($companyId);
        if ($normalizedId !== '' && isset($this->profilesByCompanyId[$normalizedId])) {
            return $this->profilesByCompanyId[$normalizedId];
        }

        return $this->getDefaultProfile();
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaultProfile(): array
    {
        return $this->sharedConfig;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function extractSharedConfig(array $config): array
    {
        unset($config['companies'], $config['default_company_id']);
        return $config;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private function buildProfile(string $key, array $profile): array
    {
        $companyId = $this->normalizeCompanyId($profile['company_id'] ?? $profile['idempresa'] ?? $key);
        $label = trim((string) ($profile['label'] ?? ''));

        $profile['company_id'] = $companyId;
        $profile['idempresa'] = $companyId;

        if ($label === '' && $companyId !== '') {
            $profile['label'] = $companyId;
        } else {
            $profile['label'] = $label;
        }

        return array_replace($this->sharedConfig, $profile);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function storeProfile(array $profile): void
    {
        $companyId = $profile['company_id'] ?? null;
        if (is_string($companyId) && $companyId !== '') {
            $this->profiles[$companyId] = $profile;
            return;
        }

        $this->profiles[] = $profile;
    }

    private function normalizeCompanyId(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $normalized = strtoupper(preg_replace('/[^0-9K]/i', '', $value) ?? '');

        return $normalized;
    }
}
