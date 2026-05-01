<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Análise Contratual - {{ $analysis->id }}/{{ date('Y') }}</title>
    <style>
        @page {
            margin: 2.5cm 2cm 2cm 2.5cm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333333;
            text-align: justify;
            background: #ffffff;
            margin-top: 2cm;
            margin-left: 2.5cm;
            margin-right: 2cm;
            margin-bottom: 2cm;
            padding: 0;
        }

        .header-institucional {
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 25px;
            border-bottom: 3px solid #4F46E5;
        }

        .header-titulo {
            font-size: 18pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: #4F46E5;
            margin-bottom: 5px;
        }

        .header-subtitulo {
            font-size: 10pt;
            font-weight: normal;
            color: #666666;
            letter-spacing: 1px;
        }

        .numero-analise {
            text-align: right;
            font-weight: bold;
            font-size: 11pt;
            margin-bottom: 25px;
            letter-spacing: 0.5px;
            color: #4F46E5;
        }

        .info-box {
            margin-bottom: 25px;
            padding: 15px;
            background-color: #F9FAFB;
            border: 1px solid #E5E7EB;
            border-radius: 5px;
        }

        .info-item {
            margin-bottom: 8px;
            font-size: 11pt;
        }

        .info-label {
            font-weight: bold;
            color: #374151;
            display: inline-block;
            min-width: 150px;
        }

        .info-valor {
            color: #1F2937;
        }

        .content {
            text-align: justify;
            margin-top: 20px;
        }

        .content h1 {
            font-size: 14pt;
            font-weight: bold;
            color: #1F2937;
            margin-top: 25px;
            margin-bottom: 15px;
            text-align: left;
            page-break-after: avoid;
            border-bottom: 2px solid #E5E7EB;
            padding-bottom: 5px;
        }

        .content h2 {
            font-size: 12pt;
            font-weight: bold;
            color: #374151;
            margin-top: 20px;
            margin-bottom: 12px;
            text-align: left;
            page-break-after: avoid;
        }

        .content h3 {
            font-size: 11pt;
            font-weight: bold;
            color: #4B5563;
            margin-top: 18px;
            margin-bottom: 10px;
            text-align: left;
            page-break-after: avoid;
        }

        .content h4,
        .content h5,
        .content h6 {
            font-size: 11pt;
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 10px;
            text-align: left;
        }

        .content p {
            margin-bottom: 12pt;
            text-align: justify;
            orphans: 3;
            widows: 3;
        }

        .content ul,
        .content ol {
            margin-left: 1cm;
            margin-bottom: 12pt;
            padding-left: 0.5cm;
        }

        .content li {
            margin-bottom: 6pt;
            text-align: justify;
        }

        .content li p {
            margin-bottom: 6pt;
        }

        .content strong {
            font-weight: bold;
            color: #1F2937;
        }

        .content em {
            font-style: italic;
        }

        .content blockquote {
            margin: 15pt 0 15pt 2cm;
            padding: 10pt 15pt;
            font-size: 10pt;
            line-height: 1.4;
            text-align: justify;
            border-left: 3px solid #4F46E5;
            background-color: #F9FAFB;
        }

        .content blockquote p {
            margin-bottom: 8pt;
            font-size: 10pt;
        }

        .content blockquote p:last-child {
            margin-bottom: 0;
        }

        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 15pt 0;
            font-size: 10pt;
            page-break-inside: avoid;
        }

        .content table th,
        .content table td {
            border: 1px solid #E5E7EB;
            padding: 8pt 10pt;
            text-align: left;
            vertical-align: top;
        }

        .content table th {
            background-color: #F3F4F6;
            font-weight: bold;
            color: #374151;
        }

        .content hr {
            border: none;
            border-top: 1px solid #E5E7EB;
            margin: 20pt 0;
        }

        .content code {
            font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
            font-size: 9pt;
            background-color: #F3F4F6;
            padding: 1pt 4pt;
            border-radius: 3px;
        }

        .content pre {
            font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
            font-size: 9pt;
            background-color: #F3F4F6;
            padding: 10pt;
            margin: 15pt 0;
            border: 1px solid #E5E7EB;
            border-radius: 5px;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        .advertencia {
            margin-top: 40pt;
            padding: 15pt 20pt;
            border: 1px solid #FCD34D;
            border-left: 4px solid #F59E0B;
            background-color: #FFFBEB;
            font-size: 9pt;
            line-height: 1.5;
        }

        .advertencia-titulo {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9pt;
            letter-spacing: 1px;
            margin-bottom: 10pt;
            color: #92400E;
            border-bottom: 1px solid #FCD34D;
            padding-bottom: 6pt;
        }

        .advertencia p {
            margin: 0;
            color: #92400E;
            text-align: justify;
        }

        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #6B7280;
            border-top: 1px solid #E5E7EB;
            padding-top: 5pt;
            padding-bottom: 25pt;
            background: #ffffff;
        }

        .content-wrapper {
            padding-bottom: 80pt;
        }

        .page-break {
            page-break-after: always;
        }

        .no-break {
            page-break-inside: avoid;
        }

        h1, h2, h3, h4, h5, h6 {
            page-break-after: avoid;
        }

        table, blockquote, .advertencia {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <div class="content-wrapper">
    <div class="header-institucional">
        <div class="header-titulo">Análise Contratual</div>
        <div class="header-subtitulo">Relatório de Análise Automatizada</div>
    </div>

    <div class="numero-analise">
        ANÁLISE N.º {{ str_pad($analysis->id, 3, '0', STR_PAD_LEFT) }}/{{ date('Y') }}
    </div>

    <div class="info-box">
        <div class="info-item">
            <span class="info-label">Arquivo:</span>
            <span class="info-valor">{{ $fileName }}</span>
        </div>
        @if($interestedParty)
        <div class="info-item">
            <span class="info-label">Parte Interessada:</span>
            <span class="info-valor">{{ $interestedParty }}</span>
        </div>
        @endif
        <div class="info-item">
            <span class="info-label">Data da Análise:</span>
            <span class="info-valor">{{ $generatedAt }}</span>
        </div>
        @if($aiProvider)
        <div class="info-item">
            <span class="info-label">IA Utilizada:</span>
            <span class="info-valor">{{ $aiProvider }}</span>
        </div>
        @endif
        @if($processingTime)
        <div class="info-item">
            <span class="info-label">Tempo de Processamento:</span>
            <span class="info-valor">{{ $processingTime }}</span>
        </div>
        @endif
    </div>

    <div class="content">
        {!! \Illuminate\Support\Str::markdown($content) !!}
    </div>

    <div class="advertencia no-break">
        <div class="advertencia-titulo">Aviso Importante</div>
        <p>
            Esta análise foi elaborada com auxílio de sistema de inteligência artificial,
            tendo por base a análise automatizada do instrumento contratual submetido.
            As informações aqui apresentadas possuem caráter meramente informativo e orientativo,
            não substituindo a análise de profissional qualificado.
            Recomenda-se a validação das informações por advogado ou especialista competente
            antes de qualquer tomada de decisão.
        </p>
    </div>
    </div>

    <div class="footer">
        Análise n.º {{ str_pad($analysis->id, 3, '0', STR_PAD_LEFT) }}/{{ date('Y') }} |
        Emitido em {{ $generatedAt }}
    </div>

    <script type="text/php">
        if (isset($pdf)) {
            $text = "Página {PAGE_NUM} de {PAGE_COUNT}";
            $size = 9;
            $font = $fontMetrics->getFont("DejaVu Sans");
            $width = $fontMetrics->get_text_width($text, $font, $size) / 2;
            $x = ($pdf->get_width() - $width) / 2;
            $y = $pdf->get_height() - 28;
            $pdf->page_text($x, $y, $text, $font, $size, array(0.4, 0.4, 0.4));
        }
    </script>
</body>
</html>
