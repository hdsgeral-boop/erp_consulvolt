<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ComunicacaoAgt;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\Empresa;
use App\Models\SerieFaturacaoEletronica;
use App\Models\SubmissaoAgt;
use App\Models\Venda;
use App\Services\Vendas\Agt\ClienteAgt;
use App\Services\Vendas\Agt\ClienteHttpAgt;
use App\Services\Vendas\Agt\MaquinaEstadosAgt as E;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Envio dos documentos à AGT e consulta do estado (js/facturacao_agt_envio.js do legado), no servidor:
 *   POR_ENVIAR/ERRO/CONTINGENCIA → ENVIADO (requestID) → VALIDO | INVALIDO (erros da AGT) — ver MaquinaEstadosAgt.
 *
 * «Outbox» transaccional e idempotência (r4_agt): o documento fica POR_ENVIAR no mesmo COMMIT em que é emitido
 * (selagem) e o envio corre depois, na fila «agt». Antes de cada pedido registarFactura grava-se o lote em
 * agt_submissoes com um submissionUUID fixo; se o resultado ficar incerto (tempo esgotado, 5xx, 429) o lote é
 * reenviado com o MESMO submissionUUID e os mesmos documentos — a AGT devolve o requestID original a um pedido duplicado
 * (doc-agt «registar.html») e, se já tiver o documento, responde E09 (consulta-se o estado). Nunca há duplicados.
 *
 * Correcções (doc-agt «registar.html», E46/FE-RNG-073): um documento recusado pela AGT NÃO se reenvia com o mesmo
 * número — emite-se um documento de correcção com número novo, documentStatus «C» e rejectedDocumentNo
 * (ServicoCorrecaoAgt). Os documentos integrados de outro sistema (SourceBilling I/M) nunca vão para a AGT.
 *
 * Consulta com espera crescente (resultCode 8 em curso; 7/E97 prematuro; E98/HTTP 429 excesso; 9 cancelado). Um lock
 * Redis por empresa e operação impede envios simultâneos (duas abas, agendador e utilizador).
 */
final class ServicoEnvioAgt
{
    public const ESTADOS_PARA_ENVIAR = E::PARA_ENVIAR;

    /** Códigos de erro que significam «não chegou a ser tratado — tentar mais tarde» (não são rejeições do documento). */
    private const INDISPONIVEL = ['SERVICO_INDISPONIVEL', 'SEM_CREDENCIAIS', 'SEM_SERVICO', 'AGT_INDISPONIVEL', 'AGT_DESLIGADA'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ClienteAgt $agt,
        private readonly ServicoSelagemAgt $selagem,
    ) {}

    public static function estadoEnvio(Venda $v): ?string
    {
        return $v->fe_envio['estado'] ?? ($v->fe_regime && $v->fe_estado === 'PRONTO' ? E::POR_ENVIAR : null);
    }

    /** Documento integrado de outro sistema (SAF-T SourceBilling I/M): nunca é comunicado à facturação electrónica. */
    public static function importado(Venda $v): bool
    {
        return in_array($v->getAttribute('saft_source_billing'), ['I', 'M'], true);
    }

    /**
     * @param  list<int>|null  $ids
     * @param  bool  $forcar  ignora a espera entre tentativas (botão «Enviar pendentes»)
     */
    public function enviarPendentes(?array $ids = null, bool $forcar = false): array
    {
        return $this->comLock('envio', function () use ($ids, $forcar) {
            $resumo = ['enviados' => 0, 'rejeitados' => 0, 'erros' => 0, 'contingencia' => 0, 'mensagens' => []];
            $nif = $this->nif();
            $agora = now();
            $parar = false;

            // 1) lotes cujo resultado ficou incerto: reenvio idempotente (mesmo submissionUUID, mesmos documentos)
            $incertas = SubmissaoAgt::query()->whereIn('estado', [SubmissaoAgt::INCERTA, SubmissaoAgt::PENDENTE])
                ->when(! $forcar && ! $ids, fn ($q) => $q->where(fn ($w) => $w->whereNull('proxima_tentativa_em')->orWhere('proxima_tentativa_em', '<=', $agora)))
                ->orderBy('id')->get();
            foreach ($incertas as $s) {
                $lote = $this->pendentes()->whereIn('id', $s->venda_ids)->orderBy('fe_data_entrada_sistema')->orderBy('id')->get()
                    ->filter(fn (Venda $v) => ($v->fe_envio['submissao'] ?? null) === $s->submission_uuid)->values();
                if ($ids && ! $lote->pluck('id')->intersect($ids)->count()) {
                    continue;
                }
                if ($lote->count() !== count($s->venda_ids)) {
                    // documentos já tratados por outra via (ex.: consulta): o lote original não se pode reproduzir igual
                    $s->update(['estado' => SubmissaoAgt::RECUSADA, 'ultimo_erro' => 'Lote desfeito: documentos já tratados.']);
                    $lote->each(fn (Venda $v) => $this->atualizar($v, ['submissao' => null], 'enviar', 'lote incerto desfeito; volta à fila'));

                    continue;
                }
                if (! $this->processarLote($s, $lote, $nif, $resumo)) {
                    $parar = true;
                    break;
                }
            }

            // 2) documentos novos (ou em erro/contingência), em lotes de até 30
            if (! $parar) {
                $docs = $this->pendentes()->when($ids, fn ($q) => $q->whereIn('id', $ids))
                    ->orderBy('fe_data_entrada_sistema')->orderBy('id')->get()
                    ->filter(fn (Venda $v) => in_array($v->fe_envio['estado'] ?? null, E::PARA_ENVIAR, true) && empty($v->fe_envio['submissao']))
                    ->filter(fn (Venda $v) => $forcar || $ids || empty($v->fe_envio['proxima_tentativa']) || $v->fe_envio['proxima_tentativa'] <= $agora->toIso8601String())
                    ->values();
                foreach ($docs->chunk((int) config('erp.agt.max_documentos')) as $lote) {
                    $lote = $lote->values();
                    $s = DB::transaction(function () use ($lote) {
                        $s = SubmissaoAgt::create(['submission_uuid' => (string) Str::uuid(), 'venda_ids' => $lote->pluck('id')->all(),
                            'documentos' => $lote->pluck('numero_documento')->all(), 'estado' => SubmissaoAgt::PENDENTE, 'tentativas' => 0]);
                        $lote->each(fn (Venda $v) => $this->atualizar($v, ['submissao' => $s->submission_uuid], 'enviar', "lote {$s->submission_uuid}", false));

                        return $s;
                    });
                    if (! $this->processarLote($s, $lote, $nif, $resumo)) {
                        break;   // AGT/serviço indisponível: não insiste nos restantes lotes
                    }
                }
            }

            return $resumo;
        });
    }

    /** Envia um lote já registado na «outbox». Devolve false quando a AGT/serviço está indisponível (parar o ciclo). */
    private function processarLote(SubmissaoAgt $s, Collection $lote, string $nif, array &$resumo): bool
    {
        $documentos = $lote->map(fn (Venda $v) => $this->documentoParaEnvio($v))->values()->all();
        $s->update(['tentativas' => $s->tentativas + 1]);
        try {
            $r = $this->agt->registar($nif, $documentos, false, $s->submission_uuid);
        } catch (ErroNegocio $e) {
            $incerto = ! empty($e->detalhes['incerto']);
            $proxima = now()->addSeconds($this->espera($s->tentativas));
            $s->update(['estado' => $incerto ? SubmissaoAgt::INCERTA : SubmissaoAgt::RECUSADA, 'ultimo_erro' => mb_substr($e->getMessage(), 0, 1000),
                'proxima_tentativa_em' => $proxima]);
            foreach ($lote as $v) {
                $this->atualizar($v, [
                    'estado' => $incerto ? E::CONTINGENCIA : E::ERRO,
                    'erros' => [['codigo' => $e->codigo, 'mensagem' => $e->getMessage(), 'documentNo' => null]],
                    'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1,
                    'proxima_tentativa' => $proxima->toIso8601String(),
                    'contingencia_desde' => $incerto ? ($v->fe_envio['contingencia_desde'] ?? now()->toIso8601String()) : ($v->fe_envio['contingencia_desde'] ?? null),
                    'submissao' => $incerto ? $s->submission_uuid : null,
                ], 'enviar', $e->getMessage());
                $incerto ? $resumo['contingencia']++ : $resumo['erros']++;
            }
            $resumo['mensagens'][] = $e->getMessage();

            return false;
        }
        $resp = $r['resposta'] ?? [];
        $http = (int) ($r['http'] ?? 0);
        $erros = self::errosAgt($resp['errorList'] ?? []);
        if ($http === 200 && ! empty($resp['requestID'])) {
            $s->update(['estado' => SubmissaoAgt::ACEITE, 'request_id' => mb_substr((string) $resp['requestID'], 0, 40), 'ultimo_erro' => null, 'proxima_tentativa_em' => null]);
            foreach ($lote as $v) {
                $this->atualizar($v, ['estado' => E::ENVIADO, 'requestID' => (string) $resp['requestID'], 'submissionUUID' => $s->submission_uuid, 'submissao' => null,
                    'enviado_em' => now()->toIso8601String(), 'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1,
                    'erros' => array_values(array_filter($erros, fn ($e) => $e['documentNo'] === $v->numero_documento)), 'contingencia_desde' => null,
                    'proxima_consulta' => now()->addSeconds(60)->toIso8601String(), 'esperas' => 0, 'proxima_tentativa' => null], 'enviar', "requestID {$resp['requestID']}");
                $resumo['enviados']++;
            }

            return true;
        }
        if ($http === 429 || collect($erros)->contains('codigo', 'E98')) {
            // demasiados pedidos: não foi tratado — o mesmo lote volta mais tarde (idempotente)
            $proxima = now()->addSeconds(max(300, $this->espera($s->tentativas)));
            $s->update(['estado' => SubmissaoAgt::INCERTA, 'ultimo_erro' => 'E98/HTTP 429: demasiados pedidos', 'proxima_tentativa_em' => $proxima]);
            foreach ($lote as $v) {
                $this->atualizar($v, ['estado' => E::CONTINGENCIA, 'erros' => [['codigo' => 'E98', 'mensagem' => 'A AGT pediu para aguardar (demasiados pedidos).', 'documentNo' => null]],
                    'proxima_tentativa' => $proxima->toIso8601String(), 'contingencia_desde' => $v->fe_envio['contingencia_desde'] ?? now()->toIso8601String()],
                    'enviar', 'E98: demasiados pedidos');
                $resumo['contingencia']++;
            }

            return false;
        }
        $s->update(['estado' => SubmissaoAgt::RECUSADA, 'ultimo_erro' => mb_substr(implode(' | ', array_map(fn ($e) => trim("{$e['codigo']} {$e['mensagem']}"), $erros)) ?: "HTTP {$http}", 0, 1000)]);
        $semDocumento = array_values(array_filter($erros, fn ($e) => ! $e['documentNo']));
        foreach ($lote as $v) {
            $proprios = array_values(array_filter($erros, fn ($e) => $e['documentNo'] === $v->numero_documento));
            if (collect($proprios)->contains('codigo', 'E09')) {
                $this->atualizar($v, ['estado' => E::ENVIADO, 'requestID' => null, 'erros' => [], 'submissao' => null, 'contingencia_desde' => null,
                    'proxima_consulta' => now()->addSeconds(5)->toIso8601String(), 'esperas' => 0], 'enviar', 'E09: a AGT já tem o documento; vai consultar-se o estado');
                $resumo['enviados']++;
            } elseif ($proprios) {
                $this->atualizar($v, ['estado' => E::REJEITADO, 'erros' => $proprios, 'submissao' => null, 'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1],
                    'enviar', implode(', ', array_column($proprios, 'codigo')));
                $resumo['rejeitados']++;
            } else {
                $lista = $semDocumento ?: [['codigo' => "HTTP_{$http}", 'mensagem' => "A AGT recusou o pedido (HTTP {$http}).", 'documentNo' => null]];
                $tent = ($v->fe_envio['tentativas'] ?? 0) + 1;
                $this->atualizar($v, ['estado' => E::ERRO, 'erros' => $lista, 'submissao' => null, 'tentativas' => $tent,
                    'proxima_tentativa' => now()->addSeconds($this->espera($tent))->toIso8601String()],
                    'enviar', implode(', ', array_map(fn ($e) => $e['codigo'] ?: $e['mensagem'], $lista)));
                $resumo['erros']++;
            }
        }
        if ($semDocumento) {
            $resumo['mensagens'][] = implode(' | ', array_map(fn ($e) => "{$e['codigo']} {$e['mensagem']}", $semDocumento));
        }

        return true;
    }

    /** Documento electrónico selado + estado (N/C) + n.º rejeitado nas correcções. */
    private function documentoParaEnvio(Venda $v): array
    {
        $rejeitado = $v->fe_envio['documento_rejeitado'] ?? null;

        return ['documentStatus' => $rejeitado ? 'C' : 'N'] + ($rejeitado ? ['rejectedDocumentNo' => (string) $rejeitado] : [])
            + $v->fe_documento['documento'];
    }

    /** @return Builder<Venda> documentos do regime, prontos e não integrados de outro sistema */
    private function pendentes(): Builder
    {
        return Venda::query()->where('fe_regime', true)->where('fe_estado', 'PRONTO')->whereNotNull('fe_documento')
            ->where(fn ($q) => $q->whereNull('saft_source_billing')->orWhere('saft_source_billing', 'P'));
    }

    /** @param  list<int>|null  $ids */
    public function consultarEstados(?array $ids = null, bool $forcar = false): array
    {
        return $this->comLock('consulta', function () use ($ids, $forcar) {
            $resumo = ['validos' => 0, 'invalidos' => 0, 'aguardar' => 0, 'mensagens' => []];
            $nif = $this->nif();
            $docs = Venda::query()->where('fe_regime', true)->whereNotNull('fe_envio')->when($ids, fn ($q) => $q->whereIn('id', $ids))
                ->whereRaw("fe_envio->>'estado' = ?", [E::ENVIADO])->get()
                ->filter(fn (Venda $v) => $forcar || empty($v->fe_envio['proxima_consulta']) || $v->fe_envio['proxima_consulta'] <= now()->toIso8601String())->values();

            foreach ($docs->filter(fn ($v) => ! empty($v->fe_envio['requestID']))->groupBy(fn ($v) => $v->fe_envio['requestID']) as $requestId => $lista) {
                try {
                    $r = $this->agt->estado($nif, (string) $requestId);
                } catch (ErroNegocio $e) {
                    $resumo['mensagens'][] = $e->getMessage();
                    if (in_array($e->codigo, self::INDISPONIVEL, true)) {
                        break;
                    }

                    continue;
                }
                $resp = $r['resposta'] ?? [];
                $resp = is_array($resp['statusResult'] ?? null) ? $resp['statusResult'] + $resp : $resp;
                $errosPedido = self::errosAgt($resp['requestErrorList'] ?? $resp['errorList'] ?? []);
                $codigos = array_column($errosPedido, 'codigo');
                $resultado = (string) ($resp['resultCode'] ?? '');
                if (($r['http'] ?? 0) === 429 || in_array('E98', $codigos, true)) {
                    $this->adiar($lista, 300, 'E98: demasiados pedidos', $resumo);

                    continue;
                }
                if ($resultado === '7' || in_array('E97', $codigos, true)) {
                    $this->adiar($lista, 120, 'pedido prematuro', $resumo);

                    continue;
                }
                if ($resultado === '8' || (in_array('E96', $codigos, true) && ($r['http'] ?? 0) === 422)) {
                    $this->adiar($lista, min(600, 60 * (1 + ($lista->first()->fe_envio['esperas'] ?? 0))), 'em processamento', $resumo);

                    continue;
                }
                if ($resultado === '9') {
                    foreach ($lista as $v) {
                        $this->atualizar($v, ['estado' => E::ERRO, 'requestID' => null, 'proxima_tentativa' => null,
                            'erros' => [['codigo' => '9', 'mensagem' => 'A AGT cancelou o processamento do pedido; o documento vai ser reenviado.', 'documentNo' => null]]],
                            'consultar', 'cancelado');
                    }
                    $resumo['mensagens'][] = "Pedido {$requestId} cancelado pela AGT.";

                    continue;
                }
                $estados = is_array($resp['documentStatusList'] ?? null) ? $resp['documentStatusList'] : [];
                if (! in_array($resultado, ['0', '1', '2'], true) || ! $estados) {
                    if ($errosPedido) {
                        $resumo['mensagens'][] = implode(' | ', array_map(fn ($e) => "{$e['codigo']} {$e['mensagem']}", $errosPedido));
                    }
                    $this->adiar($lista, 180, 'resposta sem estados (HTTP '.($r['http'] ?? 0).')', $resumo);

                    continue;
                }
                foreach ($lista as $v) {
                    $e = collect($estados)->first(fn ($x) => is_array($x) && ($x['documentNo'] ?? null) === $v->numero_documento);
                    $e ? $this->aplicar($v, $e['documentStatus'] ?? null, $e['errorList'] ?? [], $resumo) : $this->adiar(collect([$v]), 180, 'documento ausente da resposta', $resumo);
                }
            }
            // Documentos que a AGT já tinha (E09, sem requestID): consulta directa (consultarFactura)
            foreach ($docs->filter(fn ($v) => empty($v->fe_envio['requestID'])) as $v) {
                try {
                    $r = $this->agt->consultar($nif, $v->numero_documento);
                    $resp = $r['resposta'] ?? [];
                    $resp = is_array($resp['statusFEResult'] ?? null) ? $resp['statusFEResult'] + $resp : $resp;
                    $this->aplicar($v, $resp['validationStatus'] ?? $resp['documentStatus'] ?? null, $resp['errorList'] ?? [], $resumo);
                } catch (ErroNegocio $e) {
                    $resumo['mensagens'][] = $e->getMessage();
                    break;
                }
            }

            return $resumo;
        });
    }

    /**
     * Revalida um documento com erros locais (ainda não comunicado) depois de corrigidos os dados de origem (cliente,
     * empresa, produto). Os campos fiscais continuam selados; só o documento electrónico é refeito. Um documento já
     * recusado pela AGT (INVALIDO/REJEITADO) não se reenvia com o mesmo número (E46): emite-se a correcção.
     */
    public function revalidarEReenviar(Venda $venda): array
    {
        $estado = self::estadoEnvio($venda);
        if ($venda->fe_regime && in_array($estado, E::PARA_CORRIGIR, true)) {
            throw new ErroNegocio('A AGT recusou este documento: a correcção faz-se com um novo número de documento (regra FE-RNG-073 / E46). Use «Emitir documento de correcção».',
                'CORRECCAO_EXIGE_NOVO_NUMERO', 422);
        }
        $comErrosLocais = $venda->fe_estado === 'COM_ERROS' && in_array($venda->fe_envio['estado'] ?? null, E::PARA_ENVIAR, true);
        if (! $venda->fe_regime || ! $comErrosLocais) {
            throw new ErroNegocio('Só documentos com erros locais, ainda não comunicados, podem ser revalidados.', 'REVALIDACAO_NAO_PERMITIDA', 422);
        }
        $referencia = $venda->tipo_documento === 'NC' ? DB::table('vendas_documentos_relacionados as r')->join('vendas as o', 'o.id', '=', 'r.venda_relacionada_id')
            ->where('r.empresa_id', $venda->empresa_id)->where('r.venda_id', $venda->id)->value('o.numero_documento') : null;

        $erros = $this->selagem->revalidar($venda, $venda->itensVenda()->orderBy('id')->get(), ConfigFaturacaoEletronica::query()->first(), $referencia);
        if ($erros) {
            throw new ErroNegocio('O documento ainda não cumpre as regras locais: '.implode(' | ', $erros), 'DOCUMENTO_COM_ERROS', 422, ['erros' => $erros]);
        }
        $this->atualizar($venda->refresh(), ['estado' => E::POR_ENVIAR, 'erros' => [], 'proxima_tentativa' => null], 'corrigir', 'revalidado; reenviar');

        return $this->enviarPendentes([$venda->id], true);
    }

    /** Pedido assinado sem envio (confirmar chaves e estrutura antes de enviar). */
    public function previsualizar(Venda $venda): array
    {
        if (! $venda->fe_documento) {
            throw new ErroNegocio('O documento não tem documento AGT gerado.', 'SEM_DOCUMENTO_AGT', 422);
        }
        if (self::importado($venda)) {
            throw new ErroNegocio('Documento integrado de outro sistema: não é comunicado à AGT.', 'DOCUMENTO_IMPORTADO', 422);
        }

        return $this->agt->registar($this->nif(), [$this->documentoParaEnvio($venda)], true);
    }

    /** Pede à AGT o código de uma série (pedirSerie, facturacao_agt_envio.js:246-270). */
    public function pedirSerie(SerieFaturacaoEletronica $serie): SerieFaturacaoEletronica
    {
        if ($serie->agt_codigo) {
            throw new ErroNegocio('Esta série já tem código atribuído pela AGT.', 'SERIE_JA_ATRIBUIDA', 422);
        }
        if ((int) $serie->proximo_numero > 1) {
            throw new ErroNegocio('A série já tem documentos com o código local. Feche-a e peça uma nova série à AGT.', 'SERIE_USADA', 422);
        }
        $hoje = now();
        if ($serie->ano !== $hoje->year && ! ($hoje->month === 12 && $hoje->day > 15 && $serie->ano === $hoje->year + 1)) {
            throw new ErroNegocio('A AGT só cria séries do ano corrente (e, a partir de 16 de Dezembro, do ano seguinte).', 'SERIE_ANO_INVALIDO', 422);
        }
        $r = $this->agt->solicitarSerie($this->nif(), $serie->ano, $serie->tipo, (string) ($serie->estabelecimento ?: '1'), (bool) $serie->contingencia);
        $resp = $r['resposta'] ?? [];
        $res = $resp['seriesFEResult'] ?? $resp;
        if (($r['http'] ?? 0) !== 200 || empty($res['seriesCode'])) {
            $erros = self::errosAgt($resp['errorList'] ?? []);
            throw new ErroNegocio('A AGT não criou a série'.($erros ? ': '.implode(' | ', array_map(fn ($e) => "{$e['codigo']} {$e['mensagem']}", $erros)) : ' (HTTP '.($r['http'] ?? 0).')').'.',
                'AGT_RECUSOU_SERIE', 422);
        }
        $codigo = (string) $res['seriesCode'];
        if (! preg_match('/^[A-Za-z0-9]{1,30}$/', $codigo)) {
            throw new ErroNegocio("Código de série devolvido pela AGT com formato inesperado ({$codigo}).", 'AGT_RESPOSTA_INVALIDA', 502);
        }
        // o código da AGT não pode repetir o de outra série do mesmo tipo (em qualquer ano): o n.º do documento seria igual
        if ($repetida = RegrasNumeracaoAgt::codigoUsado($serie->tipo, $codigo, $serie->id)) {
            throw new ErroNegocio("Já existe no ERP a série {$serie->tipo} {$repetida->codigo} ({$repetida->ano}) com o código que a AGT atribuiu ({$codigo}, n.os "
                .($res['firstDocumentNo'] ?? '?').' a '.($res['lastDocumentNo'] ?? '?').'). Feche ou renomeie a série local e registe o código da AGT em Vendas › Séries.',
                'SERIE_DUPLICADA', 422, ['agt_codigo' => $codigo, 'serie_existente_id' => $repetida->id]);
        }
        $primeiro = self::numeroAutorizado($res['firstDocumentNo'] ?? null);
        $serie->update([
            'codigo' => $codigo, 'agt_codigo' => $codigo, 'proximo_numero' => max(1, $primeiro ?? 1),
            'agt_primeiro_numero' => $primeiro, 'agt_ultimo_numero' => self::numeroAutorizado($res['lastDocumentNo'] ?? null),
            'agt_quantidade' => isset($res['authorizedQuantity']) ? (int) $res['authorizedQuantity'] : null, 'agt_pedido_em' => now(),
        ]);

        return $serie;
    }

    /** firstDocumentNo/lastDocumentNo podem vir como n.º («1») ou como n.º de documento («FT X/1»): fica a sequência. */
    public static function numeroAutorizado(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }

        return preg_match('/(\d+)\s*$/', (string) $v, $m) ? (int) $m[1] : null;
    }

    /** Contagem por estado (ecrã «Facturação electrónica»), numa só consulta. */
    public function resumo(): array
    {
        $r = array_fill_keys([E::POR_ENVIAR, E::ENVIADO, E::VALIDO, E::INVALIDO, E::REJEITADO, E::ERRO, E::CONTINGENCIA, E::SUBSTITUIDO, 'COM_ERROS_LOCAIS'], 0);
        $linhas = Venda::query()->where('fe_regime', true)
            ->selectRaw("CASE WHEN COALESCE(fe_estado, '') <> 'PRONTO' AND COALESCE(fe_envio->>'estado', '') IN ('', 'POR_ENVIAR', 'ERRO') THEN 'COM_ERROS_LOCAIS'
                ELSE COALESCE(fe_envio->>'estado', 'POR_ENVIAR') END AS estado_agt, COUNT(*) AS n")
            ->groupByRaw('1')->toBase()->get();
        foreach ($linhas as $l) {
            $r[$l->estado_agt] = ($r[$l->estado_agt] ?? 0) + (int) $l->n;
        }

        return $r;
    }

    /**
     * Monitorização por empresa: documentos por comunicar (com idade e motivo), recusados com os erros da AGT, lotes
     * incertos, falhas de comunicação das últimas 24 h e alerta quando há documentos por comunicar há mais de
     * AGT_ALERTA_HORAS (a AGT marca «P — válida com penalização» o documento comunicado com mais de 24 h sem
     * contingência aprovada — doc-agt «consultar_fatura.html»).
     */
    public function monitor(): array
    {
        $colunas = ['id', 'tipo_documento', 'numero_documento', 'data_emissao', 'total_bruto', 'fe_estado', 'fe_envio', 'fe_erros', 'fe_data_entrada_sistema', 'fe_regime'];
        $porComunicar = Venda::query()->where('fe_regime', true)
            ->where(fn ($q) => $q->whereNull('fe_envio')->orWhereRaw("COALESCE(fe_envio->>'estado', 'POR_ENVIAR') IN ('POR_ENVIAR', 'ERRO', 'CONTINGENCIA', 'ENVIADO')"))
            ->where(fn ($q) => $q->whereNull('saft_source_billing')->orWhere('saft_source_billing', 'P'))
            ->orderBy('fe_data_entrada_sistema')->limit(200)->get($colunas);
        $recusados = Venda::query()->where('fe_regime', true)->whereRaw("fe_envio->>'estado' IN ('INVALIDO', 'REJEITADO')")
            ->orderByDesc('id')->limit(200)->get($colunas);
        $limite = (int) config('erp.agt.alerta_horas_por_comunicar', 20);
        $linha = function (Venda $v) {
            $entrada = $v->fe_data_entrada_sistema ?? $v->data_emissao;

            return [
                'id' => $v->id, 'tipo_documento' => $v->tipo_documento, 'numero_documento' => $v->numero_documento,
                'data_emissao' => $v->data_emissao?->toDateString(), 'total_bruto' => $v->total_bruto,
                'estado' => $v->fe_estado !== 'PRONTO' && in_array($v->fe_envio['estado'] ?? null, E::PARA_ENVIAR, true) ? 'COM_ERROS_LOCAIS' : self::estadoEnvio($v),
                'horas' => $entrada ? (int) floor($entrada->diffInMinutes(now()) / 60) : null,
                'tentativas' => (int) ($v->fe_envio['tentativas'] ?? 0), 'proxima_tentativa' => $v->fe_envio['proxima_tentativa'] ?? null,
                'contingencia_desde' => $v->fe_envio['contingencia_desde'] ?? null, 'request_id' => $v->fe_envio['requestID'] ?? null,
                'erros' => array_values(array_merge($v->fe_envio['erros'] ?? [], array_map(fn ($m) => ['codigo' => 'LOCAL', 'mensagem' => (string) $m, 'documentNo' => null], (array) ($v->fe_erros ?? [])))),
                'substituido_por' => $v->fe_envio['substituido_por'] ?? null,
            ];
        };
        $pend = $porComunicar->map($linha)->values();
        $atrasados = $pend->filter(fn ($l) => $l['estado'] !== E::ENVIADO && ($l['horas'] ?? 0) >= $limite)->count();
        $desde = now()->subDay();

        return [
            'resumo' => $this->resumo(),
            'por_comunicar' => $pend->all(),
            'recusados' => $recusados->map($linha)->values()->all(),
            'lotes_incertos' => SubmissaoAgt::query()->whereIn('estado', [SubmissaoAgt::INCERTA, SubmissaoAgt::PENDENTE])->orderBy('id')->limit(50)
                ->get(['id', 'submission_uuid', 'documentos', 'estado', 'tentativas', 'ultimo_erro', 'proxima_tentativa_em', 'criado_em'])->toArray(),
            'comunicacoes_24h' => [
                'total' => ComunicacaoAgt::query()->where('criado_em', '>=', $desde)->count(),
                'falhas' => ComunicacaoAgt::query()->where('criado_em', '>=', $desde)->where('sucesso', false)->count(),
            ],
            'alertas' => array_values(array_filter([
                $atrasados ? ['nivel' => 'erro', 'mensagem' => "{$atrasados} documento(s) por comunicar à AGT há mais de {$limite} h. Depois de 24 h a AGT regista-os como «válidos com penalização» (sem contingência aprovada)."] : null,
                $recusados->count() ? ['nivel' => 'aviso', 'mensagem' => "{$recusados->count()} documento(s) recusado(s) pela AGT: emita os documentos de correcção (novo número)."] : null,
            ])),
            'limite_horas' => $limite,
        ];
    }

    /** Quantos documentos recusados pela AGT (alerta do Início). */
    public function contarRecusados(): int
    {
        return Venda::query()->where('fe_regime', true)->whereRaw("fe_envio->>'estado' IN ('INVALIDO', 'REJEITADO')")->count();
    }

    /** Ciclo automático (fila/agendador): envia pendentes (respeitando o recuo) e consulta estados. */
    public function ciclo(): array
    {
        return ['envio' => $this->enviarPendentes(), 'consulta' => $this->consultarEstados()];
    }

    /** Normaliza as listas de erros da AGT: idError/descriptionError (registar) e errorCode/errorDescription (obterEstado). */
    public static function errosAgt(mixed $lista): array
    {
        return collect(is_array($lista) ? $lista : [])->filter()->map(fn ($e) => is_string($e)
            ? ['codigo' => '', 'mensagem' => $e, 'documentNo' => null]
            : ['codigo' => (string) ($e['idError'] ?? $e['errorCode'] ?? $e['codigo'] ?? ''),
                'mensagem' => (string) ($e['descriptionError'] ?? $e['errorDescription'] ?? $e['mensagem'] ?? json_encode($e)),
                'documentNo' => $e['documentNo'] ?? null])
            ->filter(fn ($e) => $e['mensagem'] !== '')->values()->all();
    }

    private function aplicar(Venda $v, ?string $estadoDoc, mixed $erros, array &$resumo): void
    {
        if ($estadoDoc === 'V' || $estadoDoc === 'P') {
            $this->atualizar($v, ['estado' => E::VALIDO, 'validado_em' => now()->toIso8601String(), 'erros' => [], 'ultima_consulta' => now()->toIso8601String(),
                'penalizacao' => $estadoDoc === 'P'], 'consultar', $estadoDoc === 'P' ? 'Válido com penalização (comunicado com mais de 24 h)' : 'Válido');
            $resumo['validos']++;
        } elseif ($estadoDoc === 'I') {
            $lista = self::errosAgt($erros);
            $this->atualizar($v, ['estado' => E::INVALIDO, 'erros' => $lista, 'ultima_consulta' => now()->toIso8601String()],
                'consultar', 'Inválido: '.implode(', ', array_column($lista, 'codigo')));
            $resumo['invalidos']++;
        } else {
            $this->adiar(collect([$v]), 120, 'estado '.($estadoDoc ?: 'desconhecido'), $resumo);
        }
    }

    private function adiar(Collection $lista, int $segundos, string $motivo, array &$resumo): void
    {
        foreach ($lista as $v) {
            $this->atualizar($v, ['proxima_consulta' => now()->addSeconds($segundos)->toIso8601String(), 'esperas' => ($v->fe_envio['esperas'] ?? 0) + 1,
                'ultima_consulta' => now()->toIso8601String()], 'consultar', $motivo);
            $resumo['aguardar']++;
        }
    }

    /** Espera antes da próxima tentativa de envio: até 60 s × 2^(n-1) (máx. 1 h), com jitter; nunca menos de 30 s. */
    private function espera(int $tentativas): int
    {
        return max(30, intdiv(ClienteHttpAgt::atraso(max(1, $tentativas), 60_000, 3_600_000), 1000));
    }

    /** Actualiza fe_envio (máquina de estados) com histórico (últimos 20 eventos). */
    public function atualizar(Venda $v, array $mods, string $accao, string $resultado, bool $historico = true): void
    {
        $anterior = $v->fe_envio ?? [];
        if (isset($mods['estado'])) {
            E::exigir($anterior['estado'] ?? null, $mods['estado'], (string) $v->numero_documento);
        }
        $hist = $anterior['historico'] ?? [];
        if ($historico) {
            $hist = array_slice([...$hist, ['em' => now()->toIso8601String(), 'accao' => $accao, 'resultado' => mb_substr($resultado, 0, 500)]], -20);
        }
        $v->forceFill(['fe_envio' => array_merge($anterior, $mods, ['historico' => $hist])])->save();
    }

    private function nif(): string
    {
        $nif = preg_replace('/\s+/', '', (string) Empresa::query()->whereKey($this->contexto->obrigatorio())->value('nif'));
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            throw new ErroNegocio('A empresa não tem um NIF válido.', 'NIF_INVALIDO', 422);
        }

        return $nif;
    }

    private function comLock(string $operacao, callable $fn): array
    {
        try {
            return Cache::lock("lock:agt:{$operacao}:{$this->contexto->obrigatorio()}", 300)->block(1, $fn);
        } catch (LockTimeoutException) {
            return ['ocupado' => true, 'mensagens' => ['Já há um '.($operacao === 'envio' ? 'envio' : 'consulta').' à AGT em curso para esta empresa.']];
        }
    }
}
