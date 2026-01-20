<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Parecer Jurídico - {{ $analysis->id }}/{{ date('Y') }}</title>
    <style>
        /* ============================================
           CONFIGURAÇÕES DE PÁGINA - PADRÃO ABNT
           Margens: superior 3cm, inferior 2cm,
           esquerda 3cm, direita 2cm
        ============================================ */
        @page {
            margin: 2.5cm 2cm 2cm 2.5cm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* ============================================
           TIPOGRAFIA - Padrão jurídico brasileiro
           Fonte: Times New Roman ou similar serifada
           Tamanho: 12pt, espaçamento 1.5
        ============================================ */
        body {
            font-family: 'DejaVu Serif', 'Times New Roman', Georgia, serif;
            font-size: 12pt;
            line-height: 1.5;
            color: #000000;
            text-align: justify;
            background: #ffffff;
            margin: 0;
            padding: 0;
        }

        /* ============================================
           CABEÇALHO INSTITUCIONAL
        ============================================ */
        .header-institucional {
            text-align: center;
            padding-bottom: 25px;
            margin-bottom: 30px;
            border-bottom: 2px solid #000000;
        }

        .header-titulo {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 4px;
            color: #000000;
            margin-bottom: 5px;
        }

        .header-subtitulo {
            font-size: 11pt;
            font-weight: normal;
            color: #333333;
            letter-spacing: 2px;
        }

        /* ============================================
           EPÍGRAFE / IDENTIFICAÇÃO
        ============================================ */
        .epigrafe {
            margin-bottom: 30px;
        }

        .epigrafe-item {
            margin-bottom: 8px;
            font-size: 12pt;
        }

        .epigrafe-label {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10pt;
            letter-spacing: 0.5px;
            display: inline-block;
            min-width: 100px;
        }

        .epigrafe-valor {
            margin-left: 15px;
            display: inline;
        }

        .numero-parecer {
            text-align: right;
            font-weight: bold;
            font-size: 12pt;
            margin-bottom: 35px;
            letter-spacing: 0.5px;
        }

        /* ============================================
           EMENTA
        ============================================ */
        .ementa {
            margin: 30px 0 30px 6cm;
            padding: 12pt;
            font-size: 11pt;
            line-height: 1.4;
            text-align: justify;
            border-left: 2px solid #333333;
            background-color: #fafafa;
        }

        .ementa-label {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10pt;
            display: block;
            margin-bottom: 6pt;
        }

        /* ============================================
           CORPO DO PARECER
        ============================================ */
        .content {
            text-align: justify;
            margin-top: 30px;
        }

        /* Títulos de seção - numeração romana */
        .content h1 {
            font-size: 12pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-top: 25px;
            margin-bottom: 15px;
            text-align: left;
            page-break-after: avoid;
        }

        /* Subtítulos */
        .content h2 {
            font-size: 12pt;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 12px;
            text-align: left;
            page-break-after: avoid;
        }

        .content h3 {
            font-size: 12pt;
            font-weight: bold;
            font-style: italic;
            margin-top: 18px;
            margin-bottom: 10px;
            text-align: left;
            page-break-after: avoid;
        }

        .content h4,
        .content h5,
        .content h6 {
            font-size: 12pt;
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 10px;
            text-align: left;
        }

        /* Parágrafos com recuo ABNT */
        .content p {
            margin-bottom: 12pt;
            text-indent: 1.25cm;
            text-align: justify;
            orphans: 3;
            widows: 3;
        }

        /* Primeiro parágrafo após título sem recuo */
        .content h1 + p,
        .content h2 + p,
        .content h3 + p,
        .content h4 + p {
            text-indent: 1.25cm;
        }

        /* Listas */
        .content ul,
        .content ol {
            margin-left: 1.25cm;
            margin-bottom: 12pt;
            padding-left: 0.5cm;
        }

        .content li {
            margin-bottom: 6pt;
            text-align: justify;
        }

        .content li p {
            text-indent: 0;
            margin-bottom: 6pt;
        }

        /* Negrito e itálico */
        .content strong {
            font-weight: bold;
        }

        .content em {
            font-style: italic;
        }

        /* ============================================
           CITAÇÕES - Padrão ABNT
           Citações longas: recuo 4cm, fonte 10pt
        ============================================ */
        .content blockquote {
            margin: 15pt 0 15pt 4cm;
            padding: 0;
            font-size: 10pt;
            line-height: 1.0;
            text-align: justify;
            font-style: normal;
            border: none;
            background: none;
        }

        .content blockquote p {
            text-indent: 0;
            margin-bottom: 10pt;
            font-size: 10pt;
        }

        .content blockquote p:last-child {
            margin-bottom: 0;
        }

        /* ============================================
           TABELAS
        ============================================ */
        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 15pt 0;
            font-size: 11pt;
            page-break-inside: avoid;
        }

        .content table th,
        .content table td {
            border: 1px solid #000000;
            padding: 8pt 10pt;
            text-align: left;
            vertical-align: top;
        }

        .content table th {
            background-color: #f0f0f0;
            font-weight: bold;
            text-align: center;
        }

        .content table caption {
            font-size: 10pt;
            text-align: center;
            margin-bottom: 8pt;
            font-weight: bold;
        }

        /* ============================================
           LINHA HORIZONTAL
        ============================================ */
        .content hr {
            border: none;
            border-top: 1px solid #000000;
            margin: 20pt 0;
        }

        /* ============================================
           CONCLUSÃO / DISPOSITIVO
        ============================================ */
        .dispositivo {
            margin-top: 30pt;
            padding-top: 15pt;
            border-top: 1px solid #000000;
        }

        /* ============================================
           FECHO
        ============================================ */
        .fecho {
            margin-top: 30pt;
            text-align: justify;
        }

        .fecho p {
            text-indent: 1.25cm;
        }

        /* ============================================
           LOCAL E DATA
        ============================================ */
        .local-data {
            margin-top: 30pt;
            text-align: right;
            font-size: 12pt;
        }

        /* ============================================
           ASSINATURA
        ============================================ */
        .assinatura {
            margin-top: 50pt;
            margin-bottom: 20pt;
            text-align: center;
        }

        .assinatura-linha {
            width: 50%;
            margin: 0 auto;
            border-top: 1px solid #000000;
            padding-top: 10pt;
        }

        .assinatura-texto {
            font-size: 10pt;
            font-style: italic;
            color: #444444;
            margin-bottom: 3pt;
        }

        .assinatura-texto:last-child {
            font-style: normal;
            font-size: 9pt;
            color: #666666;
        }

        /* ============================================
           NOTA DE RODAPÉ / ADVERTÊNCIA
        ============================================ */
        .advertencia {
            margin-top: 50pt;
            padding: 15pt 20pt;
            border: 1px solid #999999;
            border-left: 4px solid #666666;
            background-color: #f8f8f8;
            font-size: 9pt;
            line-height: 1.5;
        }

        .advertencia-titulo {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9pt;
            letter-spacing: 1px;
            margin-bottom: 10pt;
            color: #333333;
            border-bottom: 1px solid #cccccc;
            padding-bottom: 6pt;
        }

        .advertencia p {
            text-indent: 0;
            margin: 0;
            color: #333333;
            text-align: justify;
        }

        /* ============================================
           RODAPÉ DE PÁGINA
        ============================================ */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #666666;
            border-top: 0.5pt solid #cccccc;
            padding-top: 5pt;
            padding-bottom: 25pt;
            background: #ffffff;
        }

        /* Espaço inferior para evitar sobreposição com rodapé */
        .content-wrapper {
            padding-bottom: 80pt;
        }

        /* ============================================
           CONTROLE DE QUEBRA DE PÁGINA
        ============================================ */
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

        /* ============================================
           AJUSTES PARA CÓDIGO (se houver)
        ============================================ */
        .content code {
            font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
            font-size: 10pt;
            background-color: #f5f5f5;
            padding: 1pt 4pt;
        }

        .content pre {
            font-family: 'DejaVu Sans Mono', 'Courier New', monospace;
            font-size: 9pt;
            background-color: #f5f5f5;
            padding: 10pt;
            margin: 15pt 0;
            border: 1px solid #dddddd;
            overflow-x: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="content-wrapper">
    {{-- ============================================
         CABEÇALHO DO DOCUMENTO
    ============================================ --}}
    <div class="header-institucional">
        <div class="header-titulo">Parecer Jurídico</div>
        <div class="header-subtitulo">Consultoria em Direito Contratual</div>
    </div>

    {{-- ============================================
         NÚMERO DO PARECER
    ============================================ --}}
    <div class="numero-parecer">
        PARECER N.º {{ str_pad($analysis->id, 3, '0', STR_PAD_LEFT) }}/{{ date('Y') }}
    </div>

    {{-- ============================================
         EPÍGRAFE / IDENTIFICAÇÃO
    ============================================ --}}
    <div class="epigrafe">
        <div class="epigrafe-item">
            <span class="epigrafe-label">Interessado:</span>
            <span class="epigrafe-valor">{{ $interestedParty ?: 'Não informado' }}</span>
        </div>
        <div class="epigrafe-item">
            <span class="epigrafe-label">Assunto:</span>
            <span class="epigrafe-valor">Análise de instrumento contratual - {{ $fileName }}</span>
        </div>
        <div class="epigrafe-item">
            <span class="epigrafe-label">Referência:</span>
            <span class="epigrafe-valor">Protocolo n.º {{ $analysis->id }}/{{ date('Y') }}</span>
        </div>
    </div>

    {{-- ============================================
         EMENTA
    ============================================ --}}
    <div class="ementa">
        <span class="ementa-label">Ementa:</span>
        Direito Contratual. Direito do Consumidor. Análise de cláusulas contratuais.
        Verificação de conformidade legal. Identificação de riscos jurídicos.
        Recomendações para adequação contratual.
    </div>

    {{-- ============================================
         CORPO DO PARECER
    ============================================ --}}
    <div class="content">
        {!! \Illuminate\Support\Str::markdown($content) !!}
    </div>

    {{-- ============================================
         FECHO
    ============================================ --}}
    <div class="fecho">
        <p>
            É o parecer, salvo melhor juízo.
        </p>
    </div>

    {{-- ============================================
         LOCAL E DATA
    ============================================ --}}
    <div class="local-data">
        {{ $generatedAt }}
    </div>

    {{-- ============================================
         ASSINATURA
    ============================================ --}}
    <div class="assinatura">
        <div class="assinatura-linha">
            <div class="assinatura-texto">Assinatura Eletrônica</div>
            <div class="assinatura-texto">Sistema de Análise Contratual</div>
        </div>
    </div>

    {{-- ============================================
         ADVERTÊNCIA LEGAL
    ============================================ --}}
    <div class="advertencia no-break">
        <div class="advertencia-titulo">Nota de Esclarecimento</div>
        <p>
            O presente parecer foi elaborado com auxílio de sistema de inteligência artificial,
            tendo por base a análise automatizada do instrumento contratual submetido.
            As informações e recomendações aqui consignadas possuem caráter meramente orientativo,
            não substituindo, em hipótese alguma, a consulta a advogado regularmente inscrito
            nos quadros da Ordem dos Advogados do Brasil.
            Recomenda-se a submissão deste documento à apreciação de profissional habilitado
            antes de qualquer tomada de decisão fundamentada em seu conteúdo.
        </p>
    </div>
    </div><!-- fim content-wrapper -->

    {{-- ============================================
         RODAPÉ
    ============================================ --}}
    <div class="footer">
        Parecer n.º {{ str_pad($analysis->id, 3, '0', STR_PAD_LEFT) }}/{{ date('Y') }} |
        Emitido em {{ $generatedAt }}
    </div>

    {{-- ============================================
         NUMERAÇÃO DE PÁGINAS (via DomPDF)
    ============================================ --}}
    <script type="text/php">
        if (isset($pdf)) {
            $text = "Página {PAGE_NUM} de {PAGE_COUNT}";
            $size = 9;
            $font = $fontMetrics->getFont("DejaVu Serif");
            $width = $fontMetrics->get_text_width($text, $font, $size) / 2;
            $x = ($pdf->get_width() - $width) / 2;
            $y = $pdf->get_height() - 28;
            $pdf->page_text($x, $y, $text, $font, $size, array(0.4, 0.4, 0.4));
        }
    </script>
</body>
</html>
