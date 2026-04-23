<?php

namespace App\Services;

class OpenRouterResponseHandler
{
    /**
     * @return array{
     *   text:string,
     *   total_tokens:int,
     *   usage:array<string,mixed>|null,
     *   model:string|null,
     *   generation_id:string|null,
     *   annotations:array<mixed>|null
     * }
     */
    public function parse(array $data, string $callType, bool $useReasoning): array
    {
        $usageData = $this->extractUsage($data['usage'] ?? null);
        $textData = $this->extractText($data, $callType, $useReasoning);

        return [
            'text' => $textData['text'],
            'total_tokens' => $usageData['total_tokens'],
            'usage' => $usageData['usage'],
            'model' => $data['model'] ?? null,
            'generation_id' => $data['id'] ?? null,
            'annotations' => $data['annotations'] ?? null,
        ];
    }

    /**
     * @return array{usage:array<string,mixed>|null,total_tokens:int}
     */
    private function extractUsage(mixed $usage): array
    {
        if (!is_array($usage)) {
            return ['usage' => null, 'total_tokens' => 0];
        }

        $usageArray = [
            'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
            'completion_tokens' => $usage['completion_tokens'] ?? 0,
            'total_tokens' => ($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0),
        ];

        if (!empty($usage['completion_tokens_details']['reasoning_tokens'])) {
            $usageArray['completion_tokens_details'] = [
                'reasoning_tokens' => $usage['completion_tokens_details']['reasoning_tokens'],
            ];
        }

        return [
            'usage' => $usageArray,
            'total_tokens' => (int) $usageArray['total_tokens'],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{text:string}
     */
    private function extractText(array $data, string $callType, bool $useReasoning): array
    {
        $text = null;
        $reasoningContent = null;

        if (!empty($data['choices']) && is_array($data['choices'])) {
            $choice = $data['choices'][0] ?? null;
            $message = is_array($choice) ? ($choice['message'] ?? null) : null;

            if (is_array($message)) {
                if ($useReasoning) {
                    $this->logInfo("OpenRouter - Estrutura da mensagem ({$callType})", [
                        'message_keys' => array_keys($message),
                        'content_length' => mb_strlen((string) ($message['content'] ?? '')),
                        'has_reasoning' => isset($message['reasoning']),
                    ]);
                }

                $text = $message['content'] ?? null;

                if (is_array($text)) {
                    $textParts = array_filter($text, fn ($part) => is_array($part) && (($part['type'] ?? '') === 'text'));
                    $text = implode("\n", array_map(fn ($part) => $part['text'] ?? '', $textParts));
                }

                $reasoningContent = $message['reasoning'] ?? null;

                if (empty($text) && !empty($reasoningContent)) {
                    $this->logInfo("OpenRouter - Content vazio, usando reasoning ({$callType})", [
                        'reasoning_length' => mb_strlen($reasoningContent),
                    ]);
                    $text = $reasoningContent;
                    $reasoningContent = null;
                }
            }
        }

        if ($reasoningContent) {
            $this->logInfo("OpenRouter - Reasoning separado recebido ({$callType})", [
                'reasoning_length' => mb_strlen($reasoningContent),
            ]);
        }

        if (empty($text)) {
            $this->logError("OpenRouter retornou resposta vazia ({$callType})", [
                'response_id' => $data['id'] ?? 'N/A',
                'model' => $data['model'] ?? null,
                'has_reasoning' => !empty($reasoningContent),
                'choices_count' => count($data['choices'] ?? []),
            ]);

            throw new \Exception("A API OpenRouter retornou uma resposta vazia para {$callType}. Tente novamente em alguns instantes.");
        }

        return [
            'text' => (string) $text,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logInfo(string $message, array $context = []): void
    {
        try {
            \Illuminate\Support\Facades\Log::info($message, $context);
        } catch (\Throwable) {
            // Unit tests isolados podem não ter facades inicializadas.
        }
    }

    /**
     * @param array<string,mixed> $context
     */
    private function logError(string $message, array $context = []): void
    {
        try {
            \Illuminate\Support\Facades\Log::error($message, $context);
        } catch (\Throwable) {
            // Unit tests isolados podem não ter facades inicializadas.
        }
    }
}
