<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * O backend fala directamente com a AGT (porta do servico_agt/servidor.js do legado para PHP):
 * autenticação Basic com as credenciais do produtor, softwareInfo assinado com a chave do produtor e
 * documentos/pedidos assinados com a chave do contribuinte (JWS RS256).
 */
final class ClienteAgtDireto implements ClienteAgt
{
    private const TIPOS_DOC = ['FA', 'FT', 'FR', 'FG', 'GF', 'AC', 'AR', 'TV', 'RC', 'RG', 'RE', 'ND', 'NC', 'AF', 'RP', 'RA', 'CS', 'LD'];

    public function __construct(private readonly ChavesAgt $chaves) {}

    public function registar(string $nif, array $documentos, bool $simular = false): array
    {
        if (! $documentos || count($documentos) > config('erp.agt.max_documentos')) {
            throw new ErroNegocio('Cada pedido leva de 1 a '.config('erp.agt.max_documentos').' documentos.', 'LOTE_INVALIDO', 422);
        }
        $chave = $this->chaves->contribuinte($nif);
        $assinados = [];
        foreach (array_values($documentos) as $i => $d) {
            $this->validarDocumento($d, $i);
            $conteudo = [
                'documentNo' => $d['documentNo'], 'taxRegistrationNumber' => $nif, 'documentType' => $d['documentType'],
                'documentDate' => $d['documentDate'], 'customerTaxID' => $d['customerTaxID'], 'customerCountry' => $d['customerCountry'],
                'companyName' => $d['companyName'],
                'documentTotals' => ['taxPayable' => $d['documentTotals']['taxPayable'], 'netTotal' => $d['documentTotals']['netTotal'], 'grossTotal' => $d['documentTotals']['grossTotal']],
            ];
            $assinados[] = ['documentNo' => $d['documentNo'], 'documentStatus' => $d['documentStatus'],
                'jwsDocumentSignature' => AssinadorJws::assinar($conteudo, $chave, $this->typ())] + $d;
        }
        $pedido = $this->base($nif) + ['numberOfEntries' => count($assinados), 'documents' => $assinados];

        return $simular ? ['simulado' => true, 'servico' => 'registarFactura', 'pedido' => $pedido]
            : $this->chamar('registarFactura', $pedido, $nif) + ['submissionUUID' => $pedido['submissionUUID']];
    }

    public function estado(string $nif, string $requestId): array
    {
        $chave = $this->chaves->contribuinte($nif);
        $pedido = $this->base($nif) + ['requestID' => $requestId,
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'requestID' => $requestId], $chave, $this->typ())];

        return $this->chamar('obterEstado', $pedido, $nif);
    }

    public function consultar(string $nif, string $documentNo): array
    {
        $chave = $this->chaves->contribuinte($nif);
        $pedido = $this->base($nif) + ['invoiceNo' => $documentNo,
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'documentNo' => $documentNo], $chave, $this->typ())];

        return $this->chamar('consultarFactura', $pedido, $nif);
    }

    public function solicitarSerie(string $nif, int $ano, string $tipo, string $estabelecimento, bool $contingencia): array
    {
        if (! in_array($tipo, self::TIPOS_DOC, true)) {
            throw new ErroNegocio("Tipo de documento AGT inválido ({$tipo}).", 'TIPO_INVALIDO', 422);
        }
        $chave = $this->chaves->contribuinte($nif);
        $pedido = $this->base($nif) + [
            'seriesYear' => (string) $ano, 'documentType' => $tipo, 'establishmentNumber' => $estabelecimento,
            'seriesContingencyIndicator' => $contingencia ? 'C' : 'N',
            'jwsSignature' => AssinadorJws::assinar(['taxRegistrationNumber' => $nif, 'establishmentNumber' => $estabelecimento,
                'seriesYear' => (string) $ano, 'documentType' => $tipo], $chave, $this->typ()),
        ];

        return $this->chamar('solicitarSerie', $pedido, $nif);
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
        $credenciais = (bool) (config('erp.agt.utilizador') && config('erp.agt.palavra_passe'));
        $software = (bool) ($s['productId'] && $s['productVersion'] && $s['softwareValidationNumber']);

        return [
            'driver' => 'direto', 'ambiente' => config('erp.agt.ambiente'), 'url_agt' => $this->url(),
            'credenciais_configuradas' => $credenciais, 'chave_produtor' => $produtor, 'erro_chave_produtor' => $erroProdutor,
            'software' => array_intersect_key($s, array_flip(['productId', 'productVersion', 'softwareValidationNumber'])),
            'software_configurado' => $software, 'contribuintes' => $this->chaves->nifsComChave(), 'jws_typ' => $this->typ(),
            'pronto_para_enviar' => $credenciais && $produtor && $software,
        ];
    }

    private function base(string $nif): array
    {
        $s = config('erp.agt.software');
        if (! $s['productId'] || ! $s['productVersion'] || ! $s['softwareValidationNumber']) {
            throw new ErroNegocio('Faltam os dados do software (productId, productVersion, softwareValidationNumber) na configuração do servidor.', 'SOFTWARE_POR_CONFIGURAR', 503);
        }
        $detalhe = ['productId' => $s['productId'], 'productVersion' => $s['productVersion'], 'softwareValidationNumber' => $s['softwareValidationNumber']];

        return [
            'schemaVersion' => '2.0', 'submissionUUID' => (string) Str::uuid(), 'taxRegistrationNumber' => $nif,
            'submissionTimeStamp' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'softwareInfo' => ['softwareInfoDetail' => $detalhe, 'jwsSoftwareSignature' => AssinadorJws::assinar($detalhe, $this->chaves->produtor(), $this->typ())],
        ];
    }

    private function chamar(string $servico, array $pedido, string $nif): array
    {
        if (! config('erp.agt.utilizador') || ! config('erp.agt.palavra_passe')) {
            throw new ErroNegocio('As credenciais do produtor de software (AGT) não estão configuradas no servidor.', 'SEM_CREDENCIAIS', 503);
        }
        $inicio = microtime(true);
        try {
            $r = Http::withBasicAuth(config('erp.agt.utilizador'), config('erp.agt.palavra_passe'))
                ->acceptJson()->asJson()->timeout(config('erp.agt.timeout'))->post($this->url().$servico, $pedido);
        } catch (ConnectionException $e) {
            throw new ErroNegocio('Não foi possível contactar a AGT ('.$e->getMessage().').', 'AGT_INDISPONIVEL', 502);
        }
        $json = $r->json();
        if (! is_array($json)) {
            $json = ['respostaNaoJSON' => mb_substr($r->body(), 0, 2000)];
        }
        Log::channel(config('logging.default'))->info("AGT {$servico} nif={$nif} http={$r->status()}".(isset($json['requestID']) ? " requestID={$json['requestID']}" : '')
            .' '.round((microtime(true) - $inicio) * 1000).'ms');

        return ['http' => $r->status(), 'resposta' => $json];
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

    private function url(): string
    {
        $url = rtrim(config('erp.agt.url_base') ?: config('erp.agt.urls.'.config('erp.agt.ambiente')), '/').'/';
        if (! str_starts_with($url, 'https://')) {
            throw new ErroNegocio('O endereço da AGT tem de usar HTTPS.', 'CONFIG_AGT_INVALIDA', 503);
        }

        return $url;
    }

    private function typ(): string
    {
        return (string) config('erp.agt.jws_typ', 'JWT');
    }
}
