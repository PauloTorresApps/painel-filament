<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Serviço para extrair texto limpo de arquivos HTML.
 *
 * Remove tags HTML, scripts, estilos e outros elementos não textuais,
 * retornando apenas o conteúdo textual do documento.
 */
class HtmlToTextService
{
    /**
     * Extrai texto de conteúdo HTML em base64
     *
     * @param string $base64Content Conteúdo HTML codificado em base64
     * @param string $identifier Identificador para logs
     * @return string Texto extraído
     */
    public function extractText(string $base64Content, string $identifier = ''): string
    {
        try {
            // Decodifica o base64
            $htmlContent = base64_decode($base64Content);

            if ($htmlContent === false) {
                Log::warning('HtmlToTextService: Falha ao decodificar base64', [
                    'identifier' => $identifier
                ]);
                return '';
            }

            return $this->extractTextFromHtml($htmlContent, $identifier);

        } catch (\Exception $e) {
            Log::error('HtmlToTextService: Erro ao extrair texto', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Extrai texto de uma string HTML
     *
     * @param string $htmlContent Conteúdo HTML bruto
     * @param string $identifier Identificador para logs
     * @return string Texto extraído
     */
    public function extractTextFromHtml(string $htmlContent, string $identifier = ''): string
    {
        try {
            // Detecta e converte encoding se necessário
            $htmlContent = $this->normalizeEncoding($htmlContent);

            // Remove scripts e styles primeiro (incluindo seu conteúdo)
            $htmlContent = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $htmlContent);
            $htmlContent = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $htmlContent);

            // Remove comentários HTML
            $htmlContent = preg_replace('/<!--.*?-->/s', '', $htmlContent);

            // Remove tags de cabeçalho que não contêm texto útil
            $htmlContent = preg_replace('/<head\b[^>]*>(.*?)<\/head>/is', '', $htmlContent);

            // Remove tags de formulário (inputs, selects, etc) mas mantém labels
            $htmlContent = preg_replace('/<input\b[^>]*>/i', '', $htmlContent);
            $htmlContent = preg_replace('/<select\b[^>]*>(.*?)<\/select>/is', '', $htmlContent);
            $htmlContent = preg_replace('/<textarea\b[^>]*>(.*?)<\/textarea>/is', '', $htmlContent);
            $htmlContent = preg_replace('/<button\b[^>]*>(.*?)<\/button>/is', '', $htmlContent);

            // Substitui tags de bloco por quebras de linha para preservar estrutura
            $blockTags = ['div', 'p', 'br', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th', 'article', 'section', 'header', 'footer', 'aside', 'nav', 'blockquote', 'pre'];
            foreach ($blockTags as $tag) {
                $htmlContent = preg_replace("/<\/?{$tag}\b[^>]*>/i", "\n", $htmlContent);
            }

            // Remove todas as tags HTML restantes
            $text = strip_tags($htmlContent);

            // Decodifica entidades HTML
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Normaliza o texto
            $text = $this->normalizeText($text);

            Log::info('HtmlToTextService: Texto extraído com sucesso', [
                'identifier' => $identifier,
                'chars_extracted' => mb_strlen($text)
            ]);

            return $text;

        } catch (\Exception $e) {
            Log::error('HtmlToTextService: Erro ao processar HTML', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    /**
     * Normaliza o encoding do HTML para UTF-8
     */
    private function normalizeEncoding(string $content): string
    {
        // Tenta detectar o encoding pelo meta charset
        if (preg_match('/<meta[^>]+charset=["\']?([^"\'\s>]+)/i', $content, $matches)) {
            $charset = strtoupper(trim($matches[1]));
            if ($charset !== 'UTF-8' && $charset !== 'UTF8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $content);
                if ($converted !== false) {
                    return $converted;
                }
            }
        }

        // Tenta detectar encoding automaticamente
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $content;
    }

    /**
     * Normaliza o texto extraído
     */
    private function normalizeText(string $text): string
    {
        // Remove caracteres de controle (exceto newline e tab)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Normaliza quebras de linha
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove múltiplas quebras de linha consecutivas (máximo 2)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Remove espaços/tabs múltiplos
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Remove espaços no início e fim de cada linha
        $text = preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);

        // Remove linhas vazias no início e fim
        $text = trim($text);

        return $text;
    }
}
