<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análise Contratual Concluída</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f5f7; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f5f7;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background: linear-gradient(135deg, #D97706 0%, #B45309 100%); padding: 30px 40px; text-align: center;">
                            <h1 style="color: #ffffff; font-size: 22px; margin: 0 0 6px 0; font-weight: 700; letter-spacing: -0.3px;">OLHADINHA</h1>
                            <p style="color: #FDE68A; font-size: 13px; margin: 0; font-weight: 400;">Análises Jurídicas com Inteligência Artificial</p>
                        </td>
                    </tr>

                    {{-- Faixa de status --}}
                    <tr>
                        <td style="background-color: #10B981; padding: 12px 40px; text-align: center;">
                            <p style="color: #ffffff; font-size: 14px; margin: 0; font-weight: 600;">Análise Contratual Concluída</p>
                        </td>
                    </tr>

                    {{-- Conteúdo --}}
                    <tr>
                        <td style="padding: 35px 40px;">
                            <p style="color: #1F2937; font-size: 15px; line-height: 1.6; margin: 0 0 20px 0;">
                                Olá, <strong>{{ $userName }}</strong>,
                            </p>

                            <p style="color: #374151; font-size: 14px; line-height: 1.7; margin: 0 0 25px 0;">
                                A análise do documento contratual foi concluída com sucesso. O relatório completo está disponível em anexo no formato PDF.
                            </p>

                            {{-- Card com dados do contrato --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px; margin-bottom: 25px;">
                                <tr>
                                    <td style="padding: 20px 24px;">
                                        <p style="color: #92400E; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 14px 0; font-weight: 600;">Dados do Contrato</p>

                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 6px 0; color: #92400E; font-size: 13px; width: 140px; vertical-align: top;">Arquivo:</td>
                                                <td style="padding: 6px 0; color: #1F2937; font-size: 13px; font-weight: 600;">{{ $fileName }}</td>
                                            </tr>
                                            @if($interestedParty)
                                            <tr>
                                                <td style="padding: 6px 0; color: #92400E; font-size: 13px; vertical-align: top;">Parte Interessada:</td>
                                                <td style="padding: 6px 0; color: #1F2937; font-size: 13px;">{{ $interestedParty }}</td>
                                            </tr>
                                            @endif
                                            @if($processingTime)
                                            <tr>
                                                <td style="padding: 6px 0; color: #92400E; font-size: 13px; vertical-align: top;">Tempo de Processamento:</td>
                                                <td style="padding: 6px 0; color: #1F2937; font-size: 13px;">{{ $processingTime }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding: 6px 0; color: #92400E; font-size: 13px; vertical-align: top;">Concluída em:</td>
                                                <td style="padding: 6px 0; color: #1F2937; font-size: 13px;">{{ $completedAt }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            {{-- Nota sobre anexo --}}
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #FFFBEB; border-left: 4px solid #D97706; border-radius: 0 6px 6px 0; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 14px 18px;">
                                        <p style="color: #92400E; font-size: 13px; margin: 0; line-height: 1.5;">
                                            <strong>PDF em anexo:</strong> O relatório completo da análise contratual está anexado a este e-mail.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color: #F9FAFB; border-top: 1px solid #E5E7EB; padding: 24px 40px; text-align: center;">
                            <p style="color: #9CA3AF; font-size: 12px; margin: 0 0 6px 0;">
                                Este e-mail foi enviado automaticamente pelo sistema <strong style="color: #6B7280;">OLHADINHA</strong>.
                            </p>
                            <p style="color: #9CA3AF; font-size: 11px; margin: 0;">
                                Você recebeu este e-mail porque habilitou as notificações por e-mail nas suas configurações.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
