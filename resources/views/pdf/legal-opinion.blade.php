<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Parecer Jurídico</title>
    <style>
        @page {
            margin: 3cm 2.5cm 2.5cm 3cm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Serif', 'Times New Roman', serif;
            font-size: 12pt;
            line-height: 1.8;
            color: #1a1a1a;
            text-align: justify;
        }

        /* Cabeçalho do documento */
        .document-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 3px double #1a365d;
        }

        .document-header .title {
            font-size: 16pt;
            font-weight: bold;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 8px;
        }

        .document-header .subtitle {
            font-size: 11pt;
            color: #4a5568;
            font-style: italic;
        }

        /* Número do parecer */
        .parecer-number {
            text-align: right;
            font-size: 11pt;
            color: #4a5568;
            margin-bottom: 30px;
        }

        /* Bloco de identificação */
        .identification-block {
            margin-bottom: 35px;
            padding: 20px 25px;
            background-color: #f7fafc;
            border-left: 4px solid #1a365d;
        }

        .identification-block table {
            width: 100%;
            border-collapse: collapse;
        }

        .identification-block td {
            padding: 6px 0;
            vertical-align: top;
            font-size: 11pt;
        }

        .identification-block .field-label {
            font-weight: bold;
            color: #1a365d;
            width: 180px;
            text-transform: uppercase;
            font-size: 10pt;
            letter-spacing: 0.5px;
        }

        .identification-block .field-value {
            color: #2d3748;
        }

        /* Linha separadora decorativa */
        .separator {
            text-align: center;
            margin: 30px 0;
            color: #1a365d;
            font-size: 14pt;
            letter-spacing: 10px;
        }

        /* Conteúdo principal */
        .content {
            text-align: justify;
            hyphens: auto;
        }

        .content h1 {
            font-size: 14pt;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 30px;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 1px solid #cbd5e0;
        }

        .content h2 {
            font-size: 13pt;
            color: #1a365d;
            margin-top: 25px;
            margin-bottom: 12px;
            font-weight: bold;
        }

        .content h3 {
            font-size: 12pt;
            color: #2d3748;
            margin-top: 20px;
            margin-bottom: 10px;
            font-weight: bold;
            font-style: italic;
        }

        .content h4, .content h5, .content h6 {
            font-size: 12pt;
            color: #2d3748;
            margin-top: 15px;
            margin-bottom: 8px;
        }

        .content p {
            margin-bottom: 14px;
            text-indent: 2.5cm;
            text-align: justify;
        }

        .content p:first-of-type {
            text-indent: 0;
        }

        .content ul, .content ol {
            margin-left: 1.5cm;
            margin-bottom: 14px;
            padding-left: 0.5cm;
        }

        .content li {
            margin-bottom: 8px;
            text-align: justify;
        }

        .content li p {
            text-indent: 0;
            margin-bottom: 6px;
        }

        .content strong {
            color: #1a202c;
        }

        .content em {
            font-style: italic;
        }

        .content blockquote {
            margin: 20px 0;
            margin-left: 1cm;
            padding: 15px 20px;
            background-color: #f7fafc;
            border-left: 4px solid #1a365d;
            font-style: italic;
            color: #4a5568;
        }

        .content blockquote p {
            text-indent: 0;
            margin-bottom: 8px;
        }

        .content blockquote p:last-child {
            margin-bottom: 0;
        }

        /* Tabelas */
        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 11pt;
        }

        .content table th,
        .content table td {
            border: 1px solid #cbd5e0;
            padding: 10px 12px;
            text-align: left;
            vertical-align: top;
        }

        .content table th {
            background-color: #1a365d;
            color: white;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10pt;
            letter-spacing: 0.5px;
        }

        .content table tr:nth-child(even) {
            background-color: #f7fafc;
        }

        .content table tr:hover {
            background-color: #edf2f7;
        }

        /* Código/destaque */
        .content code {
            background-color: #edf2f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 10pt;
        }

        .content pre {
            background-color: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            overflow-x: auto;
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 10pt;
        }

        /* Linha horizontal */
        .content hr {
            border: none;
            border-top: 1px solid #cbd5e0;
            margin: 25px 0;
        }

        /* Aviso legal */
        .legal-notice {
            margin-top: 40px;
            padding: 20px;
            background-color: #fffbeb;
            border: 1px solid #f59e0b;
            border-radius: 0;
            font-size: 10pt;
            line-height: 1.6;
        }

        .legal-notice .notice-title {
            font-weight: bold;
            color: #92400e;
            text-transform: uppercase;
            font-size: 9pt;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .legal-notice p {
            text-indent: 0;
            margin: 0;
            color: #78350f;
        }

        /* Assinatura */
        .signature-block {
            margin-top: 50px;
            text-align: center;
        }

        .signature-line {
            width: 250px;
            border-top: 1px solid #1a365d;
            margin: 0 auto 10px auto;
            padding-top: 10px;
        }

        .signature-name {
            font-weight: bold;
            color: #1a365d;
        }

        .signature-title {
            font-size: 10pt;
            color: #4a5568;
            font-style: italic;
        }

        /* Rodapé */
        .footer {
            position: fixed;
            bottom: -1.5cm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #718096;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }

        .footer .page-info {
            margin-bottom: 3px;
        }

        /* Quebra de página */
        .page-break {
            page-break-after: always;
        }

        /* Evitar quebras indesejadas */
        h1, h2, h3, h4, h5, h6 {
            page-break-after: avoid;
        }

        table, blockquote {
            page-break-inside: avoid;
        }
    </style>
</head>
<body>
    <!-- Cabeçalho do documento -->
    <div class="document-header">
        <div class="title">Parecer Jurídico</div>
        <div class="subtitle">Análise Contratual</div>
    </div>

    <!-- Número/Referência -->
    <div class="parecer-number">
        Ref.: {{ $analysis->id }}/{{ date('Y') }}
    </div>

    <!-- Bloco de identificação -->
    <div class="identification-block">
        <table>
            <tr>
                <td class="field-label">Documento:</td>
                <td class="field-value">{{ $fileName }}</td>
            </tr>
            @if($interestedParty)
            <tr>
                <td class="field-label">Interessado:</td>
                <td class="field-value">{{ $interestedParty }}</td>
            </tr>
            @endif
            <tr>
                <td class="field-label">Data de Emissão:</td>
                <td class="field-value">{{ $generatedAt }}</td>
            </tr>
            <tr>
                <td class="field-label">Análise Realizada em:</td>
                <td class="field-value">{{ $analysis->created_at->format('d/m/Y H:i') }}</td>
            </tr>
        </table>
    </div>

    <!-- Separador -->
    <div class="separator">* * *</div>

    <!-- Conteúdo do parecer -->
    <div class="content">
        {!! \Illuminate\Support\Str::markdown($content) !!}
    </div>

    <!-- Aviso legal -->
    <div class="legal-notice">
        <div class="notice-title">Aviso Importante</div>
        <p>
            Este parecer foi elaborado com auxílio de inteligência artificial, tendo como base a análise automatizada do documento contratual fornecido. As informações e recomendações aqui contidas têm caráter orientativo e não substituem a consulta a um advogado devidamente habilitado. Recomenda-se a revisão por profissional jurídico qualificado antes de qualquer tomada de decisão baseada neste documento.
        </p>
    </div>

    <!-- Rodapé -->
    <div class="footer">
        <div class="page-info">
            Parecer Jurídico - Ref. {{ $analysis->id }}/{{ date('Y') }} - Página <span class="page-number"></span>
        </div>
        Documento gerado automaticamente em {{ $generatedAt }}
    </div>

    <script type="text/php">
        if (isset($pdf)) {
            $text = "Página {PAGE_NUM} de {PAGE_COUNT}";
            $size = 9;
            $font = $fontMetrics->getFont("DejaVu Sans");
            $width = $fontMetrics->get_text_width($text, $font, $size) / 2;
            $x = ($pdf->get_width() - $width) / 2;
            $y = $pdf->get_height() - 35;
            $pdf->page_text($x, $y, $text, $font, $size, array(0.44, 0.5, 0.56));
        }
    </script>
</body>
</html>
