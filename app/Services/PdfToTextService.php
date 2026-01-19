<?php

namespace App\Services;

use Spatie\PdfToText\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PdfToTextService
{
    /**
     * Converte um arquivo PDF em texto
     *
     * @param string $pdfContent Conteúdo do PDF em base64
     * @param string|null $tempFileName Nome personalizado para o arquivo temporário
     * @return string Texto extraído do PDF
     * @throws \Exception
     */
    public function extractText(string $pdfContent, ?string $tempFileName = null): string
    {
        $tempPath = null;

        try {
            // Decodifica o base64
            $decodedContent = base64_decode($pdfContent);

            if ($decodedContent === false) {
                throw new \Exception('Falha ao decodificar conteúdo base64');
            }

            // Cria arquivo temporário
            $tempPath = $this->createTempFile($decodedContent, $tempFileName);

            // Extrai texto usando pdftotext
            $text = $this->extractTextFromFile($tempPath);

            // Limpa e normaliza o texto
            $text = $this->normalizeText($text);

            return $text;

        } catch (\Exception $e) {
            Log::error('Erro ao extrair texto do PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            // Sempre limpa o arquivo temporário
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Cria um arquivo temporário com o conteúdo do PDF
     */
    private function createTempFile(string $content, ?string $fileName = null): string
    {
        $fileName = $fileName ?? 'pdf_' . uniqid() . '.pdf';
        $tempPath = sys_get_temp_dir() . '/' . $fileName;

        if (file_put_contents($tempPath, $content) === false) {
            throw new \Exception('Falha ao criar arquivo temporário');
        }

        return $tempPath;
    }

    /**
     * Extrai texto de um arquivo PDF diretamente pelo caminho
     *
     * @param string $filePath Caminho completo do arquivo PDF
     * @return string Texto extraído do PDF
     * @throws \Exception
     */
    public function extractTextFromPath(string $filePath): string
    {
        try {
            if (!file_exists($filePath)) {
                throw new \Exception("Arquivo não encontrado: {$filePath}");
            }

            // Extrai texto usando pdftotext
            $text = $this->extractTextFromFile($filePath);

            // Limpa e normaliza o texto
            return $this->normalizeText($text);

        } catch (\Exception $e) {
            Log::error('Erro ao extrair texto do PDF', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Extrai texto do arquivo PDF usando pdftotext
     */
    private function extractTextFromFile(string $filePath): string
    {
        try {
            // Tenta usar pdftotext do sistema
            $pdf = new Pdf();
            return $pdf->setPdf($filePath)->text();
        } catch (\Exception $e) {
            // Se pdftotext não estiver disponível, lança erro mais descritivo
            throw new \Exception(
                'pdftotext não está disponível. Instale com: sudo apt-get install poppler-utils'
            );
        }
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

    /**
     * Verifica se o pdftotext está instalado no sistema
     */
    public function isPdfToTextInstalled(): bool
    {
        try {
            $pdf = new Pdf();
            $pdf->setBinary('/usr/bin/pdftotext'); // Caminho padrão no Linux
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

}
