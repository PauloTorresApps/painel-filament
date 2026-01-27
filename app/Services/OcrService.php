<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class OcrService
{
    /**
     * Extrai texto de uma imagem usando Tesseract OCR
     *
     * @param string $imageContent Conteúdo da imagem em base64
     * @param string $mimetype Tipo MIME da imagem
     * @param string|null $tempFileName Nome personalizado para o arquivo temporário
     * @return string Texto extraído da imagem
     * @throws \Exception
     */
    public function extractText(string $imageContent, string $mimetype, ?string $tempFileName = null): string
    {
        $tempPath = null;

        try {
            // Decodifica o base64
            $decodedContent = base64_decode($imageContent);

            if ($decodedContent === false) {
                throw new \Exception('Falha ao decodificar conteúdo base64 da imagem');
            }

            // Determina a extensão baseada no mimetype
            $extension = $this->getExtensionFromMimetype($mimetype);

            // Cria arquivo temporário
            $tempPath = $this->createTempFile($decodedContent, $extension, $tempFileName);

            // Extrai texto usando Tesseract
            $text = $this->runTesseract($tempPath);

            // Limpa e normaliza o texto
            $text = $this->normalizeText($text);

            Log::info('OcrService: Texto extraído com sucesso', [
                'mimetype' => $mimetype,
                'chars_extracted' => mb_strlen($text),
            ]);

            return $text;

        } catch (\Exception $e) {
            Log::error('OcrService: Erro ao extrair texto da imagem', [
                'error' => $e->getMessage(),
                'mimetype' => $mimetype,
            ]);
            throw $e;
        } finally {
            // Sempre limpa o arquivo temporário
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Verifica se o Tesseract está instalado
     */
    public function isAvailable(): bool
    {
        $output = [];
        $returnCode = 0;
        exec('which tesseract 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && !empty($output);
    }

    /**
     * Retorna a versão do Tesseract
     */
    public function getVersion(): ?string
    {
        $output = [];
        $returnCode = 0;
        exec('tesseract --version 2>&1', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return $output[0] ?? null;
        }

        return null;
    }

    /**
     * Executa o Tesseract OCR no arquivo
     */
    private function runTesseract(string $filePath): string
    {
        // Verifica se o Tesseract está disponível
        if (!$this->isAvailable()) {
            throw new \Exception('Tesseract OCR não está instalado. Instale com: apt-get install tesseract-ocr tesseract-ocr-por');
        }

        // Executa Tesseract com suporte a português e inglês
        $outputFile = sys_get_temp_dir() . '/ocr_output_' . uniqid();
        $command = sprintf(
            'tesseract %s %s -l por+eng --psm 3 2>/dev/null',
            escapeshellarg($filePath),
            escapeshellarg($outputFile)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        $textFile = $outputFile . '.txt';

        if (!file_exists($textFile)) {
            throw new \Exception('Tesseract falhou ao gerar arquivo de saída');
        }

        $text = file_get_contents($textFile);

        // Limpa arquivo de saída
        @unlink($textFile);

        if ($text === false) {
            throw new \Exception('Falha ao ler arquivo de saída do Tesseract');
        }

        return $text;
    }

    /**
     * Determina a extensão do arquivo baseada no mimetype
     */
    private function getExtensionFromMimetype(string $mimetype): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
            'image/tiff' => 'tiff',
            'image/webp' => 'webp',
        ];

        return $map[strtolower($mimetype)] ?? 'png';
    }

    /**
     * Cria um arquivo temporário com o conteúdo da imagem
     */
    private function createTempFile(string $content, string $extension, ?string $fileName = null): string
    {
        $fileName = $fileName ?? 'ocr_' . uniqid();
        $tempPath = sys_get_temp_dir() . '/' . $fileName . '.' . $extension;

        if (file_put_contents($tempPath, $content) === false) {
            throw new \Exception('Falha ao criar arquivo temporário para OCR');
        }

        return $tempPath;
    }

    /**
     * Normaliza o texto extraído
     */
    private function normalizeText(string $text): string
    {
        // Remove caracteres de controle
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // Normaliza quebras de linha
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove linhas em branco excessivas (mais de 2 seguidas)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Remove espaços no início e fim
        $text = trim($text);

        return $text;
    }

    /**
     * Remove arquivo temporário
     */
    private function cleanupTempFile(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
}
