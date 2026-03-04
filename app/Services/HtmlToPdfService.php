<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Serviço para converter HTML em PDF usando wkhtmltopdf.
 *
 * Renderiza o HTML completo (CSS, imagens, layout) gerando um PDF
 * que representa a visualização real do documento.
 *
 * Usado para processar documentos HTML do e-Proc que são wrappers
 * de documentos escaneados ou conteúdo visual.
 */
class HtmlToPdfService
{
    /**
     * Timeout padrão para a conversão (em segundos).
     */
    private int $timeout;

    /**
     * Caminho do binário wkhtmltopdf.
     */
    private string $binary;

    public function __construct()
    {
        $this->timeout = (int) config('services.wkhtmltopdf.timeout', 30);
        $this->binary = config('services.wkhtmltopdf.binary', '/usr/bin/wkhtmltopdf');
    }

    /**
     * Verifica se o wkhtmltopdf está disponível no sistema.
     */
    public function isAvailable(): bool
    {
        return file_exists($this->binary) && is_executable($this->binary);
    }

    /**
     * Converte conteúdo HTML (base64) em PDF.
     *
     * @param string $base64Content HTML codificado em base64
     * @param string $identifier Identificador para logs e nomes de arquivo temporários
     * @return string|null Conteúdo do PDF em base64, ou null em caso de falha
     */
    public function convertFromBase64(string $base64Content, string $identifier = ''): ?string
    {
        $htmlContent = base64_decode($base64Content);

        if ($htmlContent === false) {
            Log::warning('HtmlToPdfService: Falha ao decodificar base64', [
                'identifier' => $identifier,
            ]);
            return null;
        }

        return $this->convert($htmlContent, $identifier);
    }

    /**
     * Converte conteúdo HTML bruto em PDF.
     *
     * @param string $htmlContent Conteúdo HTML bruto
     * @param string $identifier Identificador para logs
     * @return string|null Conteúdo do PDF em base64, ou null em caso de falha
     */
    public function convert(string $htmlContent, string $identifier = ''): ?string
    {
        if (!$this->isAvailable()) {
            Log::error('HtmlToPdfService: wkhtmltopdf não está disponível', [
                'binary' => $this->binary,
                'identifier' => $identifier,
            ]);
            return null;
        }

        $tmpDir = sys_get_temp_dir();
        $htmlFile = $tmpDir . '/html2pdf_' . uniqid() . '.html';
        $pdfFile = $tmpDir . '/html2pdf_' . uniqid() . '.pdf';

        try {
            // Salva o HTML em arquivo temporário
            file_put_contents($htmlFile, $htmlContent);

            // Monta o comando wkhtmltopdf
            $command = [
                'xvfb-run', '--auto-servernum', '--server-args=-screen 0 1024x768x24',
                $this->binary,
                '--quiet',
                '--no-stop-slow-scripts',
                '--disable-smart-shrinking',
                '--print-media-type',
                '--encoding', 'UTF-8',
                '--page-size', 'A4',
                '--margin-top', '10mm',
                '--margin-bottom', '10mm',
                '--margin-left', '10mm',
                '--margin-right', '10mm',
                '--load-error-handling', 'ignore',
                '--load-media-error-handling', 'ignore',
                $htmlFile,
                $pdfFile,
            ];

            $process = new Process($command);
            $process->setTimeout($this->timeout);
            $process->run();

            if (!file_exists($pdfFile) || filesize($pdfFile) === 0) {
                Log::warning('HtmlToPdfService: PDF não foi gerado ou está vazio', [
                    'identifier' => $identifier,
                    'exit_code' => $process->getExitCode(),
                    'stderr' => substr($process->getErrorOutput(), 0, 500),
                ]);
                return null;
            }

            $pdfContent = file_get_contents($pdfFile);
            $pdfBase64 = base64_encode($pdfContent);

            Log::info('HtmlToPdfService: HTML convertido para PDF com sucesso', [
                'identifier' => $identifier,
                'html_size' => strlen($htmlContent),
                'pdf_size' => strlen($pdfContent),
            ]);

            return $pdfBase64;

        } catch (\Exception $e) {
            Log::error('HtmlToPdfService: Erro na conversão', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
            return null;

        } finally {
            // Limpa arquivos temporários
            @unlink($htmlFile);
            @unlink($pdfFile);
        }
    }
}
