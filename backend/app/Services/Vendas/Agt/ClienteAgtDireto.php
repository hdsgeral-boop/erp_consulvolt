<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;

/**
 * O backend fala directamente com a AGT (porta do servico_agt/servidor.js do legado para PHP), com a ligação da
 * empresa activa (ServicoLigacaoAgt: credenciais e chave do contribuinte cifradas na base) ou, sem ela, a do servidor:
 *   - autenticação Basic (doc-agt «api.html»);
 *   - softwareInfo {productId, productVersion, softwareValidationNumber, signatureVersion} assinado com a chave do
 *     produtor (jwsSoftwareSignature);
 *   - documentos (jwsDocumentSignature) e pedidos (jwsSignature) assinados com a chave do contribuinte — JWS compacto
 *     RS256 sobre o objecto JSON completo (doc-agt «estrutura.html»).
 * O transporte (TLS, tempos, retentativas, registo) é do ClienteHttpAgt.
 */
final class ClienteAgtDireto implements ClienteAgt
{
    public const TIPOS_DOC = ['FA', 'FT', 'FR', 'FG', 'GF', 'AC', 'AR', 'TV', 'RC', 'RG', 'RE', 'ND', 'NC', 'AF', 'RP', 'RA', 'CS', 'LD'];

    public function __construct(
        private readonly ChavesAgt $chaves,
        private readonly ServicoLigacaoAgt $ligacoes,
        private readonly ClienteHttpAgt $http,
    ) {}

    public function registar(string $nif, array $documentos, bool $simular = false, ?string $submissionUuid = null): array
    {
        if (! $documentos || count($documentos) > config('erp.agt.max_documentos')) {
            throw new ErroNegocio('Cada pedido leva de 1 a '.config('erp.agt.max_documentos').' documentos.', 'LOTE_INVALIDO', 422);
        }
        $ligacao = $this->ligacao();
        $chave = $this->chaveContribuinte($ligacao, $nif);
        $assinados = [];
        foreach (array_values($documentos) as $i => $d) {
            $this->validarDocumento($d, $i);
            $assinados[] = self::prepararDocumento($d, $nif, $chave, $this->typ());
        }
        $pedido = $this->base($nif, $submissionUuid) + ['numberOfEntries' => count($assinados), 'documents' => $assinados];
        if ($simular) {
            return ['simulado' => true, 'servico' => 'registarFactura', 'pedido' => $pedido];
        }

        return $this->http->post($ligacao, 'registarFactura', $pedido, ['submission_uuid' => $pedido['submissionUUID'], 'documentos' => array_column($assinados, 'documentNo')])
            + ['submissionUUID' => $pedido['submissionUUID']];
    }

    /**
     * Documento conforme a especificação (doc-agt «registar.html»): ordem dos campos, settlementAmount em todas as linhas
     * (obrigatório; 0 sem desconto), rejectedDocumentNo nas correcções (documentStatus «C») e a assinatura do emissor
     * sobre {documentNo, taxRegistrationNumber, documentType, documentDate, customerTaxID, customerCountry, companyName,
     * documentTotals{taxPayable, netTotal, grossTotal}}.
     */
    public static function prepararDocumento(array $d, string $nif, OpenSSLAsymmetricKey $chave, string $typ = 'JWT'): array
    {
        $conteudo = [
            'documentNo' => $d['documentNo'], 'taxRegistrationNumber' => $nif, 'documentType' => $d['documentType'],
            'documentDate' => $d['documentDate'], 'customerTaxID' => $d['customerTaxID'], 'customerCountry' => $d['customerCountry'],
            'companyName' => $d['companyName'],
            'documentTotals' => ['taxPayable' => $d['documentTotals']['taxPayable'], 'netTotal' => $d['documentTotals']['netTotal'], 'grossTotal' => $d['documentTotals']['grossTotal']],
        ];
        $status = $d['documentStatus'] ?? 'N';
        $saida = ['documentNo' => $d['documentNo'], 'documentStatus' => $status];
        if ($status === 'C') {
            $saida['rejectedDocumentNo'] = (string) ($d['rejectedDocumentNo'] ?? throw new ErroNegocio("{$d['documentNo']}: a correcção (C) exige o n.º do documento rejeitado.", 'DOCUMENTO_INVALIDO', 422));
        }
        $saida['jwsDocumentSignature'] = AssinadorJws::assinar($conteudo, $chave, $typ);
        $resto = array_diff_key($d, array_flip(['documentNo', 'documentStatus', 'rejectedDocumentNo', 'jwsDocumentSignature']));
        if (isset($resto['lines']) && is_array($resto['lines'])) {
            $resto['lines'] = array_map(fn ($l) => is_array($l) ? $l + ['settlementAmount' => 0] : $l, $resto['lines']);
        }

        return $saida + $resto;
    }

    public function estado(string $nif, string $requestId): array
    {
        $ligacao = $this->ligacao();
        $chave = $this->chaveContribuinte($ligacao, $nif);
        $pedido = $this->base($nif) + ['requestID' => $requestId,
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'requestID' => $requestId], $chave, $this->typ())];

        return $this->http->post($ligacao, 'obterEstado', $pedido, ['request_id' => $requestId]);
    }

    public function consultar(string $nif, string $documentNo): array
    {
        $ligacao = $this->ligacao();
        $chave = $this->chaveContribuinte($ligacao, $nif);
        // a tabela de parâmetros diz «documentNo» e o exemplo «invoiceNo» (doc-agt «consultar_fatura.html») — configurável
        $campo = (string) config('erp.agt.campo_consulta_documento', 'documentNo');
        $pedido = $this->base($nif) + [$campo => $documentNo,
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'documentNo' => $documentNo], $chave, $this->typ())];

        return $this->http->post($ligacao, 'consultarFactura', $pedido, ['documentos' => [$documentNo]]);
    }

    public function solicitarSerie(string $nif, int $ano, string $tipo, string $estabelecimento, bool $contingencia): array
    {
        if (! in_array($tipo, self::TIPOS_DOC, true)) {
            throw new ErroNegocio("Tipo de documento AGT inválido ({$tipo}).", 'TIPO_INVALIDO', 422);
        }
        $ligacao = $this->ligacao();
        $chave = $this->chaveContribuinte($ligacao, $nif);
        $pedido = $this->base($nif) + [
            'seriesYear' => $ano, 'documentType' => $tipo, 'establishmentNumber' => $estabelecimento,
            'seriesContingencyIndicator' => $contingencia ? 'C' : 'N',
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'establishmentNumber' => $estabelecimento,
                'seriesYear' => $ano, 'documentType' => $tipo], $chave, $this->typ()),
        ];

        return $this->http->post($ligacao, 'solicitarSerie', $pedido);
    }

    /** listarSeries (só leitura): serve de teste da ligação — autenticação, assinaturas e NIF (doc-agt «listar.html»). */
    public function listarSeries(string $nif, string $estabelecimento): array
    {
        $ligacao = $this->ligacao();
        $chave = $this->chaveContribuinte($ligacao, $nif);
        $pedido = $this->base($nif) + ['establishmentNumber' => $estabelecimento,
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'establishmentNumber' => $estabelecimento], $chave, $this->typ())];

        return $this->http->post($ligacao, 'listarSeries', $pedido);
    }

    public function saude(): array
    {
        $s = config('erp.agt.software');
        $produtor = false;
        $erroProdutor = null;
        try {
            $produtor = $this->chaves->temProdutor();
        } catch (ErroNegocio $e) {
            $erroProdutor = $e->getMessage();
        }
        $ligacao = null;
        $erroLigacao = null;
        try {
            $ligacao = $this->ligacao();
        } catch (ErroNegocio $e) {
            $erroLigacao = $e->getMessage();
        }
        $credenciais = (bool) $ligacao?->temCredenciais();
        $software = (bool) ($s['productId'] && $s['productVersion'] && $s['softwareValidationNumber']);

        return [
            'driver' => 'direto', 'origem' => $ligacao?->origem, 'ambiente' => $ligacao?->ambiente ?? config('erp.agt.ambiente'), 'url_agt' => $ligacao?->url,
            'erro_ligacao' => $erroLigacao, 'credenciais_configuradas' => $credenciais, 'chave_produtor' => $produtor, 'erro_chave_produtor' => $erroProdutor,
            'chave_contribuinte_empresa' => (bool) $ligacao?->chaveContribuinte,
            'software' => array_intersect_key($s, array_flip(['productId', 'productVersion', 'softwareValidationNumber', 'signatureVersion'])),
            'software_configurado' => $software, 'contribuintes' => $this->chaves->nifsComChave(), 'jws_typ' => $this->typ(),
            'pronto_para_enviar' => $credenciais && $produtor && $software,
        ];
    }

    private function ligacao(): LigacaoAgt
    {
        return $this->ligacoes->resolverDaEmpresa() ?? $this->ligacoes->resolverDoServidor();
    }

    private function chaveContribuinte(LigacaoAgt $ligacao, string $nif): OpenSSLAsymmetricKey
    {
        return $ligacao->chaveContribuinte ?? $this->chaves->contribuinte($nif);
    }

    private function base(string $nif, ?string $submissionUuid = null): array
    {
        $s = config('erp.agt.software');
        if (! $s['productId'] || ! $s['productVersion'] || ! $s['softwareValidationNumber']) {
            throw new ErroNegocio('Faltam os dados do software (productId, productVersion, softwareValidationNumber) na configuração do servidor.', 'SOFTWARE_POR_CONFIGURAR', 503);
        }
        $detalhe = ['productId' => $s['productId'], 'productVersion' => $s['productVersion'], 'softwareValidationNumber' => $s['softwareValidationNumber']];
        // «signatureVersion — Número de assinatura a aplicar» (obrigatório no registarFactura, doc-agt «registar.html»)
        if (($s['signatureVersion'] ?? null) !== null && $s['signatureVersion'] !== '') {
            $detalhe['signatureVersion'] = (int) $s['signatureVersion'];
        }
        if ($submissionUuid !== null && ! Str::isUuid($submissionUuid)) {
            throw new ErroNegocio('submissionUUID inválido.', 'LOTE_INVALIDO', 422);
        }

        return [
            'schemaVersion' => '2.0', 'submissionUUID' => $submissionUuid ?? (string) Str::uuid(), 'taxRegistrationNumber' => $nif,
            'submissionTimeStamp' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => ['softwareInfoDetail' => $detalhe, 'jwsSoftwareSignature' => AssinadorJws::assinar($detalhe, $this->chaves->produtor(), $this->typ())],
        ];
    }

    private function validarDocumento(array $d, int $i): void
    {
        foreach (['documentNo', 'documentStatus', 'documentDate', 'documentType', 'systemEntryDate', 'customerTaxID', 'customerCountry', 'companyName', 'documentTotals'] as $campo) {
            if (! isset($d[$campo]) || $d[$campo] === '') {
                throw new ErroNegocio("documents[{$i}]: falta {$campo}.", 'DOCUMENTO_INVALIDO', 422);
            }
        }
        if (! in_array($d['documentType'], self::TIPOS_DOC, true) || ! in_array($d['documentStatus'], ['N', 'C'], true)) {
            throw new ErroNegocio("documents[{$i}]: tipo ou estado do documento inválido.", 'DOCUMENTO_INVALIDO', 422);
        }
    }

    private function typ(): string
    {
        return (string) config('erp.agt.jws_typ', 'JWT');
    }
}
