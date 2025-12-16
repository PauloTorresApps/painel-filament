<?php

namespace App\Services;

use SoapClient;
use SoapFault;
use Exception;
use Illuminate\Support\Facades\Log;

class EprocService
{
    protected $client;
    protected $usuario;
    protected $senha;
    protected $urlBase;

    public function __construct(?string $usuario = null, ?string $senhaPlainText = null)
    {
        $this->usuario = $usuario ?? config('services.eproc.user');
        // Gera o hash SHA256 da senha (equivalente ao Python: sha256("senha".encode('utf-8')).hexdigest())
        $senhaParaHash = $senhaPlainText ?? config('services.eproc.password');
        // Hash deve ser em minúsculas
        $this->senha = hash('sha256', $senhaParaHash);
        $this->urlBase = config('services.eproc.url_base');

        Log::info('EprocService inicializado', [
            'usuario' => $this->usuario,
            'senha_hash' => $this->senha,
            'tamanho_hash' => strlen($this->senha),
            'url_base' => $this->urlBase
        ]);

        try {
            $wsdlUrl = config('services.eproc.wsdl_url');

            // Contexto com opções SSL mais permissivas para ambientes de desenvolvimento
            $contextOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT
                ],
                'http' => [
                    'user_agent' => 'PHP SOAP Client',
                    'timeout' => 60
                ]
            ];

            $context = stream_context_create($contextOptions);

            // Opções do SoapClient
            $soapOptions = [
                'trace' => 1,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'stream_context' => $context,
                'connection_timeout' => 60,
                'user_agent' => 'PHP SOAP Client',
                'keep_alive' => false,
                // Endpoint correto para o controlador do webservice
                'location' => $this->urlBase . '/ws/controlador_ws.php?srv=intercomunicacao3.0'
            ];

            $this->client = new SoapClient($wsdlUrl, $soapOptions);

            // Log das funções disponíveis para debug
            Log::info('Cliente SOAP criado com sucesso', [
                'wsdl_url' => $wsdlUrl
            ]);
            Log::info('Funções SOAP disponíveis: ' . json_encode($this->client->__getFunctions()));
        } catch (SoapFault $e) {
            Log::error('Erro ao criar cliente SOAP: ' . $e->getMessage());
            Log::error('WSDL URL: ' . $wsdlUrl);

            // Tenta baixar o WSDL manualmente para diagnóstico
            $this->diagnosticarWSDL($wsdlUrl);

            throw new Exception('Erro ao conectar com o webservice: ' . $e->getMessage());
        }
    }

    /**
     * Tenta diagnosticar problemas com o WSDL
     */
    private function diagnosticarWSDL($wsdlUrl)
    {
        try {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ],
                'http' => [
                    'timeout' => 10
                ]
            ]);

            $wsdlContent = @file_get_contents($wsdlUrl, false, $context);

            if ($wsdlContent === false) {
                $error = error_get_last();
                Log::error('Não foi possível baixar o WSDL: ' . ($error['message'] ?? 'Erro desconhecido'));
                Log::error('Verifique se a URL está acessível: ' . $wsdlUrl);
            } else {
                Log::info('WSDL baixado com sucesso (' . strlen($wsdlContent) . ' bytes)');
            }
        } catch (Exception $e) {
            Log::error('Erro ao diagnosticar WSDL: ' . $e->getMessage());
        }
    }

    /**
     * Lista todas as funções disponíveis no WSDL
     */
    public function listarFuncoes()
    {
        return $this->client->__getFunctions();
    }

    /**
     * Lista todos os tipos disponíveis no WSDL
     */
    public function listarTipos()
    {
        return $this->client->__getTypes();
    }

    /**
     * Consulta um processo pelo número
     */
    public function consultarProcesso(
        string $numeroProcesso,
        string $dataInicial = null,
        string $dataFinal = null,
        bool $incluirCabecalho = true,
        bool $incluirPartes = true,
        bool $incluirEnderecos = false,
        bool $incluirMovimentos = true,
        bool $incluirDocumentos = true
    ) {
        try {
            // Remove a máscara do número do processo (pontos e traços)
            $numeroProcessoLimpo = $this->limparNumeroProcesso($numeroProcesso);

            $params = [
                'consultante' => [
                    'autenticacaoSimples' => [
                        'usuario' => $this->usuario,
                        'senha' => $this->senha
                    ]
                ],
                'numeroProcesso' => $numeroProcessoLimpo,
                'incluirCabecalho' => $incluirCabecalho,
                'incluirPartes' => $incluirPartes,
                'incluirEnderecos' => $incluirEnderecos,
                'incluirMovimentos' => $incluirMovimentos,
                'incluirDocumentos' => $incluirDocumentos
            ];

            if ($dataInicial) {
                $params['dataInicial'] = $dataInicial;
            }

            if ($dataFinal) {
                $params['dataFinal'] = $dataFinal;
            }

            // Log dos parâmetros sendo enviados (sem a senha completa por segurança)
            Log::info('Enviando requisição consultarProcesso', [
                'usuario' => $this->usuario,
                'numeroProcesso' => $numeroProcessoLimpo,
                'hash_length' => strlen($this->senha),
                'hash_first_chars' => substr($this->senha, 0, 8) . '...'
            ]);

            $response = $this->client->consultarProcesso($params);

            return $this->processarResposta($response);

        } catch (SoapFault $e) {
            Log::error('Erro SOAP ao consultar processo: ' . $e->getMessage());
            Log::error('Request: ' . $this->client->__getLastRequest());
            Log::error('Response: ' . $this->client->__getLastResponse());

            throw new Exception('Erro ao consultar processo: ' . $e->getMessage());
        }
    }

    /**
     * Remove a máscara do número do processo (pontos, traços e espaços)
     */
    protected function limparNumeroProcesso(string $numeroProcesso): string
    {
        // Remove pontos, traços e espaços, deixando apenas números
        return preg_replace('/[.\-\s]/', '', $numeroProcesso);
    }

    /**
     * Consulta documentos de um processo com conteúdo em base64
     * Usa requisição HTTP manual para suportar MTOM/XOP
     */
    public function consultarDocumentosProcesso(string $numeroProcesso, array $idsDocumentos)
    {
        try {
            // Remove a máscara do número do processo
            $numeroProcessoLimpo = $this->limparNumeroProcesso($numeroProcesso);

            Log::info('Consultando documentos com conteúdo via HTTP manual', [
                'numeroProcesso' => $numeroProcessoLimpo,
                'idsDocumentos' => $idsDocumentos,
                'quantidade' => count($idsDocumentos)
            ]);

            // Monta o envelope SOAP manualmente
            $soapEnvelope = $this->montarEnvelopeConsultarDocumentos($numeroProcessoLimpo, $idsDocumentos);

            // Faz requisição HTTP manual com cURL
            $endpoint = $this->urlBase . '/ws/controlador_ws.php?srv=intercomunicacao3.0';
            $responseRaw = $this->fazerRequisicaoSOAPManual($endpoint, $soapEnvelope, 'requisicaoConsultarDocumentosProcesso');

            Log::info('Resposta HTTP recebida', [
                'tamanho' => strlen($responseRaw),
                'e_multipart' => strpos($responseRaw, 'multipart/related') !== false
            ]);

            // Extrai o XML da resposta multipart
            $xmlResponse = $this->extrairXMLDeMultipart($responseRaw);

            Log::info('XML extraído do multipart', [
                'tamanho' => strlen($xmlResponse),
                'primeiros_500_chars' => substr($xmlResponse, 0, 500)
            ]);

            // Remove namespaces para facilitar o parsing
            $xmlLimpo = $this->removerNamespacesXML($xmlResponse);

            // Processa o XML
            $xml = simplexml_load_string($xmlLimpo, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml === false) {
                $errors = libxml_get_errors();
                libxml_clear_errors();
                Log::error('Erro ao fazer parse do XML', ['errors' => $errors]);
                throw new Exception('Erro ao processar XML da resposta');
            }

            // Converte para array
            $resultado = json_decode(json_encode($xml), true);

            Log::info('Estrutura da resposta XML parseada', [
                'keys' => array_keys($resultado),
                'resultado_completo' => json_encode($resultado, JSON_PRETTY_PRINT)
            ]);

            // Extrai anexos MTOM
            $anexos = $this->extrairAnexosMTOM($responseRaw);

            Log::info('Anexos MTOM extraídos', [
                'quantidade' => count($anexos),
                'cids' => array_keys($anexos)
            ]);

            // Vincula anexos aos documentos
            $resultado = $this->vincularAnexosADocumentos($resultado, $anexos);

            return $this->processarResposta($resultado);

        } catch (Exception $e) {
            Log::error('Erro ao consultar documentos: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Monta envelope SOAP para consultar documentos
     */
    protected function montarEnvelopeConsultarDocumentos(string $numeroProcesso, array $idsDocumentos): string
    {
        $idsXML = '';
        foreach ($idsDocumentos as $id) {
            $idsXML .= "<idDocumento>{$id}</idDocumento>\n";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:ns1="http://www.cnj.jus.br/mni/v300/">
    <soap:Body>
        <ns1:requisicaoConsultarDocumentosProcesso>
            <consultante>
                <autenticacaoSimples>
                    <usuario>{$this->usuario}</usuario>
                    <senha>{$this->senha}</senha>
                </autenticacaoSimples>
            </consultante>
            <numeroProcesso>{$numeroProcesso}</numeroProcesso>
            {$idsXML}
        </ns1:requisicaoConsultarDocumentosProcesso>
    </soap:Body>
</soap:Envelope>
XML;
    }

    /**
     * Faz requisição SOAP manual usando cURL
     */
    protected function fazerRequisicaoSOAPManual(string $endpoint, string $soapEnvelope, string $soapAction): string
    {
        $ch = curl_init($endpoint);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $soapEnvelope,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $soapAction . '"',
                'Content-Length: ' . strlen($soapEnvelope)
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new Exception('Erro cURL: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception('HTTP Error ' . $httpCode);
        }

        return $response;
    }

    /**
     * Extrai XML da primeira parte de uma resposta multipart
     */
    protected function extrairXMLDeMultipart(string $responseRaw): string
    {
        // Separa headers do body
        $headerBodySplit = explode("\r\n\r\n", $responseRaw, 2);
        if (count($headerBodySplit) < 2) {
            $headerBodySplit = explode("\n\n", $responseRaw, 2);
        }

        $body = $headerBodySplit[1] ?? $responseRaw;

        // Extrai o boundary
        if (!preg_match('/boundary="([^"]+)"/', $responseRaw, $matches)) {
            // Tenta sem aspas
            if (!preg_match('/boundary=([^\s;]+)/', $responseRaw, $matches)) {
                throw new Exception('Boundary não encontrado na resposta');
            }
        }

        $boundary = $matches[1];

        // Divide em partes
        $parts = explode('--' . $boundary, $body);

        // A primeira parte após o boundary inicial contém o XML
        foreach ($parts as $part) {
            if (trim($part) === '' || trim($part) === '--') {
                continue;
            }

            // Procura pela parte que contém XML
            if (strpos($part, '<?xml') !== false || strpos($part, '<SOAP-ENV:Envelope') !== false) {
                // Extrai apenas o XML (remove headers da parte)
                $partHeaderBodySplit = explode("\r\n\r\n", $part, 2);
                if (count($partHeaderBodySplit) < 2) {
                    $partHeaderBodySplit = explode("\n\n", $part, 2);
                }

                $xml = trim($partHeaderBodySplit[1] ?? $part);

                // Remove possível lixo no final
                if (($pos = strpos($xml, '</SOAP-ENV:Envelope>')) !== false) {
                    $xml = substr($xml, 0, $pos + 20); // +20 para incluir a tag de fechamento
                }

                return $xml;
            }
        }

        throw new Exception('XML não encontrado na resposta multipart');
    }

    /**
     * Remove namespaces do XML para facilitar o parsing
     */
    protected function removerNamespacesXML(string $xml): string
    {
        // Remove declarações de namespace das tags
        $xml = preg_replace('/<([a-zA-Z0-9_-]+):/', '<', $xml);
        $xml = preg_replace('/<\/([a-zA-Z0-9_-]+):/', '</', $xml);

        // Remove atributos xmlns
        $xml = preg_replace('/\s+xmlns[^=]*="[^"]*"/i', '', $xml);

        return $xml;
    }

    /**
     * Extrai anexos binários de uma resposta MTOM/XOP multipart
     */
    protected function extrairAnexosMTOM(string $responseRaw): array
    {
        $anexos = [];

        try {
            // Extrai o boundary da resposta
            if (!preg_match('/boundary="([^"]+)"/', $responseRaw, $matches)) {
                Log::warning('Boundary não encontrado na resposta MTOM');
                return $anexos;
            }

            $boundary = $matches[1];

            // Divide a resposta em partes usando o boundary
            $parts = explode('--' . $boundary, $responseRaw);

            foreach ($parts as $part) {
                // Pula partes vazias ou de fechamento
                if (trim($part) === '' || trim($part) === '--') {
                    continue;
                }

                // Extrai o Content-ID
                if (preg_match('/Content-ID:\s*<([^>]+)>/i', $part, $cidMatches)) {
                    $contentId = $cidMatches[1];

                    // Extrai o Content-Type
                    $contentType = 'application/octet-stream';
                    if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $part, $typeMatches)) {
                        $contentType = trim($typeMatches[1]);
                    }

                    // Extrai o conteúdo binário (tudo após os headers)
                    $headerEnd = strpos($part, "\r\n\r\n");
                    if ($headerEnd === false) {
                        $headerEnd = strpos($part, "\n\n");
                    }

                    if ($headerEnd !== false) {
                        $content = substr($part, $headerEnd + 4); // +4 para pular \r\n\r\n ou \n\n

                        $anexos[$contentId] = [
                            'contentId' => $contentId,
                            'contentType' => $contentType,
                            'content' => $content,
                            'base64' => base64_encode($content),
                            'size' => strlen($content)
                        ];

                        Log::info('Anexo MTOM extraído', [
                            'contentId' => $contentId,
                            'size' => strlen($content),
                            'type' => $contentType
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            Log::error('Erro ao extrair anexos MTOM: ' . $e->getMessage());
        }

        return $anexos;
    }

    /**
     * Vincula anexos MTOM aos documentos na resposta
     */
    protected function vincularAnexosADocumentos(array $resultado, array $anexos): array
    {
        if (empty($anexos)) {
            Log::warning('Nenhum anexo MTOM para vincular');
            return $resultado;
        }

        // A estrutura vem como Body > respostaConsultarDocumentosProcesso > documentos
        if (isset($resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'])) {
            $documentos = &$resultado['Body']['respostaConsultarDocumentosProcesso']['documentos'];

            // Garante que é array
            if (!is_array($documentos)) {
                Log::warning('Documentos não é array');
                return $resultado;
            }

            // Se for um único documento (tem idDocumento diretamente)
            if (isset($documentos['idDocumento'])) {
                $this->vincularAnexoAoDocumento($documentos, $anexos);
            } else {
                // É um array de documentos
                foreach ($documentos as &$doc) {
                    $this->vincularAnexoAoDocumento($doc, $anexos);
                }
            }

            Log::info('Documentos após vinculação', [
                'tem_conteudo' => isset($documentos['conteudo']['conteudo']) || isset($documentos[0]['conteudo']['conteudo'])
            ]);
        }

        return $resultado;
    }

    /**
     * Vincula anexo a um documento específico
     */
    protected function vincularAnexoAoDocumento(array &$documento, array $anexos): void
    {
        // Procura referência XOP no conteúdo
        // Estrutura: conteudo > Include > @attributes > href
        if (isset($documento['conteudo']['Include']['@attributes']['href'])) {
            $href = $documento['conteudo']['Include']['@attributes']['href'];
            $cid = str_replace('cid:', '', $href);

            Log::info('Tentando vincular anexo', [
                'cid' => $cid,
                'anexo_existe' => isset($anexos[$cid])
            ]);

            if (isset($anexos[$cid])) {
                // Substitui a estrutura Include pelo conteúdo base64
                $documento['conteudo'] = [
                    'conteudo' => $anexos[$cid]['base64'],
                    'contentType' => $anexos[$cid]['contentType'],
                    'size' => $anexos[$cid]['size']
                ];

                Log::info('Anexo vinculado com sucesso', [
                    'cid' => $cid,
                    'tamanho_base64' => strlen($anexos[$cid]['base64'])
                ]);
            } else {
                Log::warning('Anexo não encontrado', [
                    'cid' => $cid,
                    'anexos_disponiveis' => array_keys($anexos)
                ]);
            }
        }
    }

    /**
     * Procura recursivamente por referências XOP e vincula anexos
     */
    protected function procurarVincularAnexo(&$data, array $anexos): void
    {
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => &$value) {
            // Procura por href (referência XOP)
            if ($key === 'href' && is_string($value)) {
                // Remove prefixo 'cid:' se existir
                $cid = str_replace('cid:', '', $value);

                // Procura o anexo correspondente
                if (isset($anexos[$cid])) {
                    // Substitui a referência XOP pelo conteúdo real
                    $data['conteudo'] = $anexos[$cid]['base64'];
                    $data['contentType'] = $anexos[$cid]['contentType'];
                    $data['size'] = $anexos[$cid]['size'];

                    Log::info('Anexo vinculado ao documento', ['cid' => $cid]);
                }
            } elseif (is_array($value)) {
                $this->procurarVincularAnexo($value, $anexos);
            }
        }
    }

    /**
     * Processa a resposta do webservice
     */
    protected function processarResposta($response)
    {
        if (is_object($response)) {
            $response = json_decode(json_encode($response), true);
        }

        // Verifica se há erro no recibo
        if (isset($response['recibo'])) {
            $this->verificarErroRecibo($response['recibo']);
        }

        // Normaliza arrays que podem vir como string única ou array
        $response = $this->normalizarArraysSOAP($response);

        // Extrai atributos XML para campos do mesmo nível
        $response = $this->extrairAtributosXML($response);

        return $response;
    }

    /**
     * Extrai atributos XML (@attributes) e os move para o nível principal do array
     * Exemplo: ['polo' => ['@attributes' => ['polo' => 'AT']]] vira ['polo' => 'AT']
     */
    protected function extrairAtributosXML($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => &$value) {
            // Se for um array com @attributes
            if (is_array($value) && isset($value['@attributes'])) {
                // Mescla os atributos no nível principal
                $attributes = $value['@attributes'];
                unset($value['@attributes']);

                // Para cada atributo, adiciona no nível principal se ainda não existir
                foreach ($attributes as $attrKey => $attrValue) {
                    // Se a chave do atributo for igual à chave atual, substitui o valor
                    if ($attrKey === $key) {
                        $value = $attrValue;
                    } elseif (!isset($value[$attrKey])) {
                        $value[$attrKey] = $attrValue;
                    }
                }
            }

            // Recursivamente processa arrays aninhados
            if (is_array($value)) {
                $value = $this->extrairAtributosXML($value);
            }
        }

        return $data;
    }

    /**
     * Normaliza campos que podem vir como string única ou array do SOAP
     * O SOAP tem o comportamento de retornar string quando há 1 item e array quando há múltiplos
     */
    protected function normalizarArraysSOAP($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        // Campos que devem ser sempre arrays
        $camposParaNormalizar = [
            'movimento',
            'documento',
            'idDocumentoVinculado',
            'polo',
            'parte',
            'mensagens',
            'endereco',
            'advogado'
        ];

        foreach ($data as $key => &$value) {
            // Se for um dos campos que deve ser array
            if (in_array($key, $camposParaNormalizar)) {
                // Se não for array, transforma em array
                if (!is_array($value)) {
                    $value = [$value];
                }
                // Se for array associativo (objeto único), transforma em array de objetos
                elseif ($this->isAssociativeArray($value)) {
                    $value = [$value];
                }
            }

            // Recursivamente normaliza arrays aninhados
            if (is_array($value)) {
                $value = $this->normalizarArraysSOAP($value);
            }
        }

        return $data;
    }

    /**
     * Verifica se é um array associativo (objeto único) vs array de objetos
     */
    protected function isAssociativeArray($array): bool
    {
        if (!is_array($array)) {
            return false;
        }

        if (empty($array)) {
            return false;
        }

        // Se as chaves não são sequenciais (0, 1, 2...), é associativo
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Verifica se há erro no recibo da resposta e lança exceção com mensagem amigável
     */
    protected function verificarErroRecibo(array $recibo)
    {
        // Verifica se a operação falhou
        if (isset($recibo['sucesso']) && $recibo['sucesso'] === false) {
            $mensagem = 'Erro ao processar solicitação';

            // Extrai a mensagem de erro
            if (isset($recibo['mensagens'])) {
                $mensagens = $recibo['mensagens'];

                // Se mensagens for um array direto (um único erro)
                if (isset($mensagens['descritivo'])) {
                    $mensagem = $this->formatarMensagemErro($mensagens);
                }
                // Se mensagens for array de erros
                elseif (is_array($mensagens)) {
                    $erros = [];
                    foreach ($mensagens as $msg) {
                        if (isset($msg['descritivo'])) {
                            $erros[] = $this->formatarMensagemErro($msg);
                        }
                    }
                    if (!empty($erros)) {
                        $mensagem = implode(' | ', $erros);
                    }
                }
            }

            throw new Exception($mensagem);
        }
    }

    /**
     * Formata a mensagem de erro removendo caracteres especiais e prefixos técnicos
     */
    protected function formatarMensagemErro(array $mensagem): string
    {
        $descritivo = $mensagem['descritivo'] ?? 'Erro desconhecido';

        // Log da mensagem original para debug
        Log::warning('Erro do webservice eProc', [
            'mensagem_original' => $mensagem,
            'descritivo' => $descritivo
        ]);

        // Remove o prefixo "->" que aparece nas mensagens
        $descritivo = ltrim($descritivo, '->');

        // Remove caracteres de encoding quebrados
        $descritivo = preg_replace('/[^\x20-\x7E\xA0-\xFF]/u', '', $descritivo);

        // Trata mensagens específicas para deixá-las mais amigáveis
        if (stripos($descritivo, 'autentica') !== false && stripos($descritivo, 'inv') !== false) {
            return 'Falha na autenticação: credenciais inválidas. Verifique o usuário e senha configurados no sistema.';
        }

        if (stripos($descritivo, 'processo n') !== false && stripos($descritivo, 'o encontrado') !== false) {
            return 'Processo não encontrado. Verifique se o número do processo está correto.';
        }

        if (stripos($descritivo, 'acesso negado') !== false) {
            return 'Acesso negado ao processo. Você pode não ter permissão para visualizar este processo.';
        }

        if (stripos($descritivo, 'valor num') !== false && stripos($descritivo, 'rico inv') !== false) {
            return 'Formato de número de processo inválido. Verifique se o número está correto.';
        }

        // Retorna a mensagem limpa
        return trim($descritivo);
    }

    /**
     * Substitui [servico] na URL do serviço pela URL base configurada
     */
    protected function ajustarUrlServico(string $url): string
    {
        return str_replace('[servico]', $this->urlBase, $url);
    }

    /**
     * Obtém a URL do documento para visualização
     */
    public function getUrlDocumento(string $idDocumento): string
    {
        return $this->ajustarUrlServico($idDocumento);
    }
}
