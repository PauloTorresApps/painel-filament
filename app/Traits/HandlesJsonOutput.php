<?php

namespace App\Traits;

trait HandlesJsonOutput
{
    /**
     * JSON encode seguro com flags consistentes.
     *
     * @throws \JsonException
     */
    protected function jsonEncode(mixed $data, bool $prettyPrint = false): string
    {
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;

        if ($prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return json_encode($data, $flags);
    }

    /**
     * JSON decode seguro com JSON_THROW_ON_ERROR.
     *
     * @throws \JsonException
     */
    protected function jsonDecode(string $json, bool $associative = true, int $depth = 512): mixed
    {
        return json_decode($json, $associative, $depth, JSON_THROW_ON_ERROR);
    }

    /**
     * Formata dados como JSON pretty-printed para debug.
     * Retorna 'null' se os dados estiverem vazios.
     */
    protected function formatJsonForDebug(mixed $data): string
    {
        if (empty($data)) {
            return 'null';
        }

        try {
            return $this->jsonEncode($data, prettyPrint: true);
        } catch (\JsonException) {
            return 'null';
        }
    }

    /**
     * Estima contagem de tokens usando heurística baseada em palavras.
     * Lê método e multiplicador de config/analysis.php.
     * Fallback para chars/4 se config indisponível.
     */
    protected function estimateTokenCount(string $text): int
    {
        $method = config('analysis.token_estimation.method', 'word_count');

        if ($method === 'word_count') {
            $multiplier = config('analysis.token_estimation.word_multiplier', 1.3);
            $wordCount = str_word_count($text);
            return (int) ceil($wordCount * $multiplier);
        }

        // Fallback legado: chars / 4
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Trunca texto para exibição com indicador de tamanho total.
     */
    protected function truncateText(?string $text, int $maxLength): string
    {
        if (empty($text)) {
            return '(vazio)';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength) . "\n\n... [TRUNCADO - Total: " . mb_strlen($text) . " caracteres]";
    }
}
