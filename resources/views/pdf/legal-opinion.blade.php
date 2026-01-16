<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Parecer Jurídico</title>
    <style>
        @page {
            margin: 2cm 2.5cm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
        }

        /* Cabeçalho */
        .header {
            text-align: center;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .header h1 {
            font-size: 18pt;
            color: #1e40af;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .header .subtitle {
            font-size: 10pt;
            color: #6b7280;
        }

        /* Informações do documento */
        .doc-info {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 25px;
        }

        .doc-info table {
            width: 100%;
            border-collapse: collapse;
        }

        .doc-info td {
            padding: 5px 10px;
            vertical-align: top;
        }

        .doc-info .label {
            font-weight: bold;
            color: #4b5563;
            width: 150px;
        }

        .doc-info .value {
            color: #1f2937;
        }

        /* Conteúdo principal */
        .content {
            text-align: justify;
        }

        .content h1, .content h2, .content h3, .content h4, .content h5, .content h6 {
            color: #1e40af;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .content h1 {
            font-size: 16pt;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 5px;
        }

        .content h2 {
            font-size: 14pt;
        }

        .content h3 {
            font-size: 12pt;
        }

        .content p {
            margin-bottom: 12px;
            text-indent: 2em;
        }

        .content ul, .content ol {
            margin-left: 2em;
            margin-bottom: 12px;
        }

        .content li {
            margin-bottom: 5px;
        }

        .content strong {
            color: #1f2937;
        }

        .content blockquote {
            border-left: 3px solid #2563eb;
            padding-left: 15px;
            margin: 15px 0;
            color: #4b5563;
            font-style: italic;
        }

        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .content table th,
        .content table td {
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
            text-align: left;
        }

        .content table th {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #374151;
        }

        .content table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        /* Rodapé */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #9ca3af;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
        }

        .page-number:after {
            content: counter(page);
        }

        /* Quebra de página */
        .page-break {
            page-break-after: always;
        }

        /* Avisos e observações */
        .notice {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 5px;
            padding: 10px 15px;
            margin: 15px 0;
            font-size: 10pt;
        }

        .notice strong {
            color: #92400e;
        }
    </style>
</head>
<body>
    <!-- Cabeçalho -->
    <div class="header">
        <h1>Parecer Jurídico</h1>
        <div class="subtitle">Análise Contratual Automatizada</div>
    </div>

    <!-- Informações do documento -->
    <div class="doc-info">
        <table>
            <tr>
                <td class="label">Documento Analisado:</td>
                <td class="value">{{ $fileName }}</td>
            </tr>
            @if($interestedParty)
            <tr>
                <td class="label">Parte Interessada:</td>
                <td class="value">{{ $interestedParty }}</td>
            </tr>
            @endif
            <tr>
                <td class="label">Data de Geração:</td>
                <td class="value">{{ $generatedAt }}</td>
            </tr>
        </table>
    </div>

    <!-- Conteúdo do parecer -->
    <div class="content">
        {!! \Illuminate\Support\Str::markdown($content) !!}
    </div>

    <!-- Aviso -->
    <div class="notice">
        <strong>Aviso:</strong> Este parecer foi gerado automaticamente por inteligência artificial com base na análise do contrato fornecido. Recomenda-se a revisão por um profissional jurídico qualificado antes de tomar qualquer decisão baseada neste documento.
    </div>

    <!-- Rodapé -->
    <div class="footer">
        Parecer gerado em {{ $generatedAt }} | Página <span class="page-number"></span>
    </div>
</body>
</html>
