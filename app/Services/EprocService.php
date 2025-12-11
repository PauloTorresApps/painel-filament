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

    public function __construct()
    {
        $this->usuario = config('services.eproc.user');
        // Gera o hash SHA256 da senha (equivalente ao Python: sha256("senha".encode('utf-8')).hexdigest())
        $this->senha = hash('sha256', config('services.eproc.password'));
        $this->urlBase = config('services.eproc.url_base');

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
                // Define a URL do endpoint do serviço
                'location' => $this->urlBase . '/ws/controlador_ws.php?srv=intercomunicacao3.0'
            ];

            $this->client = new SoapClient($wsdlUrl, $soapOptions);

            // Log das funções disponíveis para debug
            Log::info('Cliente SOAP criado com sucesso');
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
     * Consulta documentos de um processo
     */
    public function consultarDocumentosProcesso(string $numeroProcesso, array $idsDocumentos)
    {
        try {
            // Remove a máscara do número do processo
            $numeroProcessoLimpo = $this->limparNumeroProcesso($numeroProcesso);

            $params = [
                'consultante' => [
                    'autenticacaoSimples' => [
                        'usuario' => $this->usuario,
                        'senha' => $this->senha
                    ]
                ],
                'numeroProcesso' => $numeroProcessoLimpo,
                'idDocumento' => $idsDocumentos
            ];

            $response = $this->client->consultarDocumentosProcesso($params);

            return $this->processarResposta($response);

        } catch (SoapFault $e) {
            Log::error('Erro SOAP ao consultar documentos: ' . $e->getMessage());
            Log::error('Request: ' . $this->client->__getLastRequest());
            Log::error('Response: ' . $this->client->__getLastResponse());

            throw new Exception('Erro ao consultar documentos: ' . $e->getMessage());
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

        return $response;
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
