<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise de Documento - {{ $analysis->numero_processo }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .header {
            border-bottom: 3px solid #4F46E5;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        h1 {
            color: #4F46E5;
            font-size: 22pt;
            margin: 0 0 10px 0;
        }

        .subtitle {
            color: #666;
            font-size: 10pt;
            margin: 5px 0;
        }

        .section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #E5E7EB;
            border-radius: 5px;
            background-color: #F9FAFB;
        }

        .section-title {
            font-size: 14pt;
            font-weight: bold;
            color: #1F2937;
            margin: 0 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #E5E7EB;
        }

        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }

        .info-row {
            display: table-row;
        }

        .info-label {
            display: table-cell;
            font-weight: bold;
            color: #374151;
            padding: 8px 15px 8px 0;
            width: 35%;
            vertical-align: top;
        }

        .info-value {
            display: table-cell;
            color: #1F2937;
            padding: 8px 0;
            vertical-align: top;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 9pt;
            font-weight: 600;
            background-color: #10B981;
            color: white;
        }

        .analysis-content {
            font-size: 11pt;
            line-height: 1.8;
            color: #1F2937;
            text-align: justify;
            padding: 15px;
            background-color: white;
            border: 1px solid #E5E7EB;
            border-radius: 5px;
        }

        .analysis-content h1,
        .analysis-content h2,
        .analysis-content h3 {
            color: #1F2937;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .analysis-content h1 {
            font-size: 16pt;
            border-bottom: 2px solid #E5E7EB;
            padding-bottom: 5px;
        }

        .analysis-content h2 {
            font-size: 14pt;
        }

        .analysis-content h3 {
            font-size: 12pt;
        }

        .analysis-content p {
            margin: 10px 0;
        }

        .analysis-content ul, .analysis-content ol {
            margin: 10px 0;
            padding-left: 25px;
        }

        .analysis-content li {
            margin: 5px 0;
        }

        .analysis-content strong {
            color: #1F2937;
            font-weight: 600;
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 2px solid #E5E7EB;
            text-align: center;
            font-size: 9pt;
            color: #6B7280;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>RELATÓRIO DE ANÁLISE PROCESSUAL</h1>
        <p class="subtitle">Processo: <strong>{{ $analysis->numero_processo }}</strong></p>
        <p class="subtitle">Documento: {{ $analysis->descricao_documento ?? 'Não especificado' }}</p>
        <p class="subtitle">Data da Análise: {{ $analysis->created_at->format('d/m/Y H:i:s') }}</p>
    </div>

    <div class="section" style="padding: 10px 15px;">
        <h2 class="section-title" style="margin-bottom: 10px;">Informações do Processo</h2>

        <div class="info-grid">
            <div class="info-row">
                <div class="info-label" style="padding: 5px 15px 5px 0;">Processo:</div>
                <div class="info-value" style="padding: 5px 0;">{{ $analysis->numero_processo }}</div>
            </div>

            @if($analysis->classe_processual)
            <div class="info-row">
                <div class="info-label" style="padding: 5px 15px 5px 0;">Classe:</div>
                <div class="info-value" style="padding: 5px 0;">{{ $analysis->classe_processual }}</div>
            </div>
            @endif

            @if($analysis->assuntos)
            <div class="info-row">
                <div class="info-label" style="padding: 5px 15px 5px 0;">Assuntos:</div>
                <div class="info-value" style="padding: 5px 0;">{{ $analysis->assuntos }}</div>
            </div>
            @endif
        </div>
    </div>

    <div class="page-break"></div>

    <div class="section">
        <h2 class="section-title">Análise da IA</h2>

        <div class="analysis-content">
            {!! \Illuminate\Support\Str::markdown($analysis->ai_analysis) !!}
        </div>
    </div>

    <div class="footer">
        <p>Documento gerado automaticamente pelo Sistema de Análise de Processos</p>
        <p>{{ now()->format('d/m/Y H:i:s') }}</p>
    </div>
</body>
</html>
