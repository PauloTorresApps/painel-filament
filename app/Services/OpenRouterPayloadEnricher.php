<?php

namespace App\Services;

class OpenRouterPayloadEnricher
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function enrich(array $payload, bool $useReasoning, ?float $temperatureOverride = null): array
    {
        if (!$useReasoning && !isset($payload['temperature'])) {
            $payload['temperature'] = $temperatureOverride ?? (float) $this->configValue('temperature', 0.3);
        }

        if ($useReasoning && !isset($payload['reasoning'])) {
            $payload['reasoning'] = [
                'effort' => 'high',
                'exclude' => true,
            ];
        }

        if (!isset($payload['provider'])) {
            $providerRouting = $this->buildProviderRouting();
            if ($providerRouting !== null) {
                $payload['provider'] = $providerRouting;
            }
        }

        if (!isset($payload['transforms'])) {
            $transforms = $this->buildTransforms();
            if ($transforms !== null) {
                $payload['transforms'] = $transforms;
            }
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildProviderRouting(): ?array
    {
        $provider = [];

        $order = $this->configValue('provider_order');
        if (is_string($order) && $order !== '') {
            $provider['order'] = array_map('trim', explode(',', $order));
        }

        $provider['allow_fallbacks'] = (bool) $this->configValue('allow_fallbacks', true);

        if ((bool) $this->configValue('require_parameters', true)) {
            $provider['require_parameters'] = true;
        }

        $sort = $this->configValue('provider_sort');
        if ($sort) {
            $provider['sort'] = $sort;
        }

        $maxPricePrompt = $this->configValue('max_price_prompt');
        $maxPriceCompletion = $this->configValue('max_price_completion');
        if ($maxPricePrompt || $maxPriceCompletion) {
            $maxPrice = [];
            if ($maxPricePrompt) {
                $maxPrice['prompt'] = (float) $maxPricePrompt;
            }
            if ($maxPriceCompletion) {
                $maxPrice['completion'] = (float) $maxPriceCompletion;
            }
            $provider['max_price'] = $maxPrice;
        }

        return !empty($provider) ? $provider : null;
    }

    /**
     * @return array<int,string>|null
     */
    private function buildTransforms(): ?array
    {
        $transforms = $this->configValue('transforms');

        if ($transforms === '' || $transforms === null || $transforms === false) {
            return null;
        }

        if (is_array($transforms)) {
            return array_map(static fn ($item) => trim((string) $item), $transforms);
        }

        return array_map('trim', explode(',', (string) $transforms));
    }

    private function configValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
