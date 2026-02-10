<?php

namespace App\Services;

use App\Models\ContractAnalysis;
use App\Models\DocumentAnalysis;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Serviço base para geração de PDFs
 * 
 * Centraliza a configuração padrão de PDFs,
 * eliminando duplicação entre controllers.
 */
class PdfService
{
    /**
     * Gera PDF a partir de uma view com configurações padrão
     *
     * @param string $view Nome da view
     * @param array $data Dados para a view
     * @param string $font Fonte padrão
     * @return \Barryvdh\DomPDF\PDF
     */
    public static function generate(
        string $view,
        array $data,
        string $font = 'DejaVu Sans'
    ) {
        $pdf = Pdf::loadView($view, $data);

        self::configure($pdf, $font);

        return $pdf;
    }

    /**
     * Gera PDF específico para análise contratual
     */
    public static function generateContractAnalysisPdf(ContractAnalysis $analysis)
    {
        $aiProvider = match ($analysis->ai_provider) {
            'openrouter' => 'OpenRouter',
            default => $analysis->ai_provider ?? null
        };

        $processingTime = $analysis->processing_time_ms
            ? number_format($analysis->processing_time_ms / 1000, 1) . ' segundos'
            : null;

        $data = [
            'analysis' => $analysis,
            'content' => $analysis->analysis_result,
            'generatedAt' => $analysis->updated_at->format('d/m/Y H:i'),
            'interestedParty' => $analysis->interested_party_name,
            'fileName' => $analysis->file_name,
            'aiProvider' => $aiProvider,
            'processingTime' => $processingTime,
        ];

        return self::generate('pdf.contract-analysis', $data, 'DejaVu Sans');
    }

    /**
     * Gera PDF específico para parecer jurídico
     */
    public static function generateLegalOpinionPdf(ContractAnalysis $analysis)
    {
        $aiProvider = match ($analysis->legal_opinion_ai_provider) {
            'openrouter' => 'OpenRouter',
            default => $analysis->legal_opinion_ai_provider ?? 'OpenRouter'
        };

        $processingTime = $analysis->legal_opinion_processing_time_ms
            ? number_format($analysis->legal_opinion_processing_time_ms / 1000, 1) . ' segundos'
            : null;

        $data = [
            'analysis' => $analysis,
            'content' => $analysis->legal_opinion_result,
            'generatedAt' => $analysis->legal_opinion_completed_at->format('d/m/Y H:i'),
            'interestedParty' => $analysis->interested_party_name,
            'fileName' => $analysis->file_name,
            'aiProvider' => $aiProvider,
            'processingTime' => $processingTime,
        ];

        return self::generate('pdf.legal-opinion', $data, 'DejaVu Serif');
    }

    /**
     * Gera PDF específico para análise de processos (DocumentAnalysis)
     */
    public static function generateDocumentAnalysisPdf(DocumentAnalysis $analysis)
    {
        $data = [
            'analysis' => $analysis,
        ];

        return self::generate('pdf.document-analysis', $data, 'DejaVu Sans');
    }

    /**
     * Configura um PDF com as opções padrão
     *
     * @param \Barryvdh\DomPDF\PDF $pdf
     * @param string $font
     * @return void
     */
    public static function configure($pdf, string $font = 'DejaVu Sans'): void
    {
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('isPhpEnabled', true);
        $pdf->setOption('defaultFont', $font);
        $pdf->setOption('isFontSubsettingEnabled', true);
        $pdf->setOption('margin_top', 25);
        $pdf->setOption('margin_bottom', 20);
        $pdf->setOption('margin_left', 25);
        $pdf->setOption('margin_right', 20);
    }

    /**
     * Gera PDF para análise contratual
     */
    public static function generateContractAnalysis(array $data)
    {
        return self::generate('pdf.contract-analysis', $data, 'DejaVu Sans');
    }

    /**
     * Gera PDF para parecer jurídico
     */
    public static function generateLegalOpinion(array $data)
    {
        return self::generate('pdf.legal-opinion', $data, 'DejaVu Serif');
    }
}
