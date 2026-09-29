<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\Empresa;
use App\Models\SerieFaturacaoEletronica;
use App\Models\Venda;
use App\Services\Vendas\Agt\ClienteAgt;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Envio dos documentos à AGT e consulta do estado (js/facturacao_agt_envio.js do legado), agora no servidor:
 *   POR_ENVIAR/ERRO → ENVIADO (requestID) → VALIDO | INVALIDO (erros da AGT)
 *   REJEITADO: recusado na recepção (estrutura/assinatura) · E09: a AGT já tem o documento → consulta directa
 *   INVALIDO/REJEITADO → corrigir os dados de origem → revalidar e reenviar (INVALIDO vai como correcção "C")
 * Consulta com espera crescente (resultCode 8 em curso; 7/E97 prematuro; E98/HTTP 429 excesso; 9 cancelado).
 * Um lock Redis por empresa substitui a variável "emCurso" do browser (duas abas já não enviam em duplicado).
 */
final class ServicoEnvioAgt
{
    public const ESTADOS_PARA_ENVIAR = [null, 'POR_ENVIAR', 'ERRO'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ClienteAgt $agt,
        private readonly ServicoSelagemAgt $selagem,
    ) {}

    public static function estadoEnvio(Venda $v): ?string
    {
        return $v->fe_envio['estado'] ?? ($v->fe_regime && $v->fe_estado === 'PRONTO' ? 'POR_ENVIAR' : null);
    }

    /** @param  list<int>|null  $ids */
    public function enviarPendentes(?array $ids = null): array
    {
        return $this->comLock('envio', function () use ($ids) {
            $resumo = ['enviados' => 0, 'rejeitados' => 0, 'erros' => 0, 'mensagens' => []];
            $nif = $this->nif();
            $docs = Venda::query()->where('fe_regime', true)->where('fe_estado', 'PRONTO')->whereNotNull('fe_documento')
                ->when($ids, fn ($q) => $q->whereIn('id', $ids))
                ->orderBy('fe_data_entrada_sistema')->orderBy('id')->get()
                ->filter(fn (Venda $v) => in_array($v->fe_envio['estado'] ?? null, self::ESTADOS_PARA_ENVIAR, true))->values();

            foreach ($docs->chunk((int) config('erp.agt.max_documentos')) as $lote) {
                $documentos = $lote->map(fn (Venda $v) => ['documentStatus' => ! empty($v->fe_envio['correccao']) ? 'C' : 'N'] + $v->fe_documento['documento'])->values()->all();
                try {
                    $r = $this->agt->registar($nif, $documentos);
                } catch (ErroNegocio $e) {
                    foreach ($lote as $v) {
                        $this->atualizar($v, ['estado' => 'ERRO', 'erros' => [['codigo' => $e->codigo, 'mensagem' => $e->getMessage()]],
                            'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1], 'enviar', $e->getMessage());
                    }
                    $resumo['erros'] += $lote->count();
                    $resumo['mensagens'][] = $e->getMessage();
                    break;   // serviço/AGT indisponível: não insiste nos restantes lotes
                }
                $resp = $r['resposta'] ?? [];
                $erros = self::errosAgt($resp['errorList'] ?? []);
                if (($r['http'] ?? 0) === 200 && ! empty($resp['requestID'])) {
                    foreach ($lote as $v) {
                        $this->atualizar($v, ['estado' => 'ENVIADO', 'requestID' => (string) $resp['requestID'], 'submissionUUID' => $r['submissionUUID'] ?? null,
                            'enviado_em' => now()->toIso8601String(), 'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1,
                            'erros' => array_values(array_filter($erros, fn ($e) => $e['documentNo'] === $v->numero_documento)),
                            'proxima_consulta' => now()->addSeconds(60)->toIso8601String(), 'esperas' => 0], 'enviar', "requestID {$resp['requestID']}");
                        $resumo['enviados']++;
                    }

                    continue;
                }
                $semDocumento = array_values(array_filter($erros, fn ($e) => ! $e['documentNo']));
                foreach ($lote as $v) {
                    $proprios = array_values(array_filter($erros, fn ($e) => $e['documentNo'] === $v->numero_documento));
                    if (collect($proprios)->contains('codigo', 'E09')) {
                        $this->atualizar($v, ['estado' => 'ENVIADO', 'requestID' => null, 'erros' => [], 'proxima_consulta' => now()->addSeconds(5)->toIso8601String(), 'esperas' => 0],
                            'enviar', 'E09: a AGT já tem o documento; vai consultar-se o estado');
                        $resumo['enviados']++;
                    } elseif ($proprios) {
                        $this->atualizar($v, ['estado' => 'REJEITADO', 'erros' => $proprios, 'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1],
                            'enviar', implode(', ', array_column($proprios, 'codigo')));
                        $resumo['rejeitados']++;
                    } else {
                        $lista = $semDocumento ?: [['codigo' => 'HTTP_'.($r['http'] ?? 0), 'mensagem' => 'A AGT recusou o pedido (HTTP '.($r['http'] ?? 0).').', 'documentNo' => null]];
                        $this->atualizar($v, ['estado' => 'ERRO', 'erros' => $lista, 'tentativas' => ($v->fe_envio['tentativas'] ?? 0) + 1],
                            'enviar', implode(', ', array_map(fn ($e) => $e['codigo'] ?: $e['mensagem'], $lista)));
                        $resumo['erros']++;
                    }
                }
                if ($semDocumento) {
                    $resumo['mensagens'][] = implode(' | ', array_map(fn ($e) => "{$e['codigo']} {$e['mensagem']}", $semDocumento));
                }
            }

            return $resumo;
        });
    }

    /** @param  list<int>|null  $ids */
    public function consultarEstados(?array $ids = null, bool $forcar = false): array
    {
        return $this->comLock('consulta', function () use ($ids, $forcar) {
            $resumo = ['validos' => 0, 'invalidos' => 0, 'aguardar' => 0, 'mensagens' => []];
            $nif = $this->nif();
            $docs = Venda::query()->where('fe_regime', true)->whereNotNull('fe_envio')->when($ids, fn ($q) => $q->whereIn('id', $ids))->get()
                ->filter(fn (Venda $v) => ($v->fe_envio['estado'] ?? null) === 'ENVIADO')
                ->filter(fn (Venda $v) => $forcar || empty($v->fe_envio['proxima_consulta']) || $v->fe_envio['proxima_consulta'] <= now()->toIso8601String())->values();

            foreach ($docs->filter(fn ($v) => ! empty($v->fe_envio['requestID']))->groupBy(fn ($v) => $v->fe_envio['requestID']) as $requestId => $lista) {
                try {
                    $r = $this->agt->estado($nif, (string) $requestId);
                } catch (ErroNegocio $e) {
                    $resumo['mensagens'][] = $e->getMessage();
                    if (in_array($e->codigo, ['SERVICO_INDISPONIVEL', 'SEM_CREDENCIAIS', 'SEM_SERVICO', 'AGT_INDISPONIVEL', 'AGT_DESLIGADA'], true)) {
                        break;
                    }

                    continue;
                }
                $resp = $r['resposta'] ?? [];
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
                if ($resultado === '8') {
                    $this->adiar($lista, min(600, 60 * (1 + ($lista->first()->fe_envio['esperas'] ?? 0))), 'em processamento', $resumo);

                    continue;
                }
                if ($resultado === '9') {
                    foreach ($lista as $v) {
                        $this->atualizar($v, ['estado' => 'ERRO', 'erros' => [['codigo' => '9', 'mensagem' => 'A AGT cancelou o processamento do pedido; o documento vai ser reenviado.', 'documentNo' => null]]],
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
            // Documentos que a AGT já tinha (E09, sem requestID): consulta directa
            foreach ($docs->filter(fn ($v) => empty($v->fe_envio['requestID'])) as $v) {
                try {
                    $r = $this->agt->consultar($nif, $v->numero_documento);
                    $this->aplicar($v, $r['resposta']['documentStatus'] ?? null, $r['resposta']['errorList'] ?? [], $resumo);
                } catch (ErroNegocio $e) {
                    $resumo['mensagens'][] = $e->getMessage();
                    break;
                }
            }

            return $resumo;
        });
    }

    /**
     * Revalida um documento com erros locais ou recusado pela AGT, depois de corrigidos os dados de origem
     * (cliente, empresa, produto). Os campos fiscais continuam selados; só o documento electrónico é refeito.
     */
    public function revalidarEReenviar(Venda $venda): array
    {
        $estado = self::estadoEnvio($venda);
        $comErrosLocais = $venda->fe_estado === 'COM_ERROS' && in_array($venda->fe_envio['estado'] ?? null, self::ESTADOS_PARA_ENVIAR, true);
        if (! $venda->fe_regime || (! $comErrosLocais && ! in_array($estado, ['INVALIDO', 'REJEITADO'], true))) {
            throw new ErroNegocio('Só documentos com erros locais, inválidos ou rejeitados pela AGT podem ser revalidados.', 'REVALIDACAO_NAO_PERMITIDA', 422);
        }
        $referencia = $venda->tipo_documento === 'NC' ? DB::table('vendas_documentos_relacionados as r')->join('vendas as o', 'o.id', '=', 'r.venda_relacionada_id')
            ->where('r.empresa_id', $venda->empresa_id)->where('r.venda_id', $venda->id)->value('o.numero_documento') : null;

        $erros = $this->selagem->revalidar($venda, $venda->itensVenda()->orderBy('id')->get(), ConfigFaturacaoEletronica::query()->first(), $referencia);
        if ($erros) {
            throw new ErroNegocio('O documento ainda não cumpre as regras locais: '.implode(' | ', $erros), 'DOCUMENTO_COM_ERROS', 422, ['erros' => $erros]);
        }
        $this->atualizar($venda, ['estado' => 'POR_ENVIAR', 'correccao' => $estado === 'INVALIDO', 'erros' => []], 'corrigir',
            $estado === 'INVALIDO' ? 'reenviar como correcção (C)' : 'reenviar');

        return $this->enviarPendentes([$venda->id]);
    }

    /** Pedido assinado sem envio (confirmar chaves e estrutura antes de enviar). */
    public function previsualizar(Venda $venda): array
    {
        if (! $venda->fe_documento) {
            throw new ErroNegocio('O documento não tem documento AGT gerado.', 'SEM_DOCUMENTO_AGT', 422);
        }

        return $this->agt->registar($this->nif(), [['documentStatus' => ! empty($venda->fe_envio['correccao']) ? 'C' : 'N'] + $venda->fe_documento['documento']], true);
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
        $res = $resp['seriesFEResult'] ?? [];
        if (($r['http'] ?? 0) !== 200 || empty($res['seriesCode'])) {
            $erros = self::errosAgt($resp['errorList'] ?? []);
            throw new ErroNegocio('A AGT não criou a série'.($erros ? ': '.implode(' | ', array_map(fn ($e) => "{$e['codigo']} {$e['mensagem']}", $erros)) : ' (HTTP '.($r['http'] ?? 0).')').'.',
                'AGT_RECUSOU_SERIE', 422);
        }
        $codigo = (string) $res['seriesCode'];
        if (! preg_match('/^[A-Za-z0-9]{1,30}$/', $codigo)) {
            throw new ErroNegocio("Código de série devolvido pela AGT com formato inesperado ({$codigo}).", 'AGT_RESPOSTA_INVALIDA', 502);
        }
        if (SerieFaturacaoEletronica::query()->where('id', '<>', $serie->id)->where('tipo', $serie->tipo)->where('ano', $serie->ano)->where('codigo', $codigo)->exists()) {
            throw new ErroNegocio("Já existe no ERP uma série {$serie->tipo} com o código {$codigo}.", 'SERIE_DUPLICADA', 422);
        }
        $serie->update([
            'codigo' => $codigo, 'agt_codigo' => $codigo, 'proximo_numero' => max(1, (int) ($res['firstDocumentNo'] ?? 1)),
            'agt_primeiro_numero' => $res['firstDocumentNo'] ?? null, 'agt_ultimo_numero' => $res['lastDocumentNo'] ?? null,
            'agt_quantidade' => $res['authorizedQuantity'] ?? null, 'agt_pedido_em' => now(),
        ]);

        return $serie;
    }

    /** Contagem por estado (ecrã "Facturação electrónica"). */
    public function resumo(): array
    {
        $r = ['POR_ENVIAR' => 0, 'ENVIADO' => 0, 'VALIDO' => 0, 'INVALIDO' => 0, 'REJEITADO' => 0, 'ERRO' => 0, 'COM_ERROS_LOCAIS' => 0];
        foreach (Venda::query()->where('fe_regime', true)->get(['id', 'fe_regime', 'fe_estado', 'fe_envio']) as $v) {
            $v->fe_estado !== 'PRONTO' ? $r['COM_ERROS_LOCAIS']++ : $r[self::estadoEnvio($v) ?? 'POR_ENVIAR']++;
        }

        return $r;
    }

    /** Ciclo automático (fila/agendador): envia pendentes e consulta estados. */
    public function ciclo(): array
    {
        return ['envio' => $this->enviarPendentes(), 'consulta' => $this->consultarEstados()];
    }

    public static function errosAgt(mixed $lista): array
    {
        return collect(is_array($lista) ? $lista : [])->filter()->map(fn ($e) => is_string($e)
            ? ['codigo' => '', 'mensagem' => $e, 'documentNo' => null]
            : ['codigo' => (string) ($e['idError'] ?? $e['codigo'] ?? ''), 'mensagem' => (string) ($e['descriptionError'] ?? $e['mensagem'] ?? json_encode($e)),
                'documentNo' => $e['documentNo'] ?? null])
            ->filter(fn ($e) => $e['mensagem'] !== '')->values()->all();
    }

    private function aplicar(Venda $v, ?string $estadoDoc, mixed $erros, array &$resumo): void
    {
        if ($estadoDoc === 'V') {
            $this->atualizar($v, ['estado' => 'VALIDO', 'validado_em' => now()->toIso8601String(), 'erros' => [], 'ultima_consulta' => now()->toIso8601String(), 'correccao' => false],
                'consultar', 'Válido');
            $resumo['validos']++;
        } elseif ($estadoDoc === 'I') {
            $lista = self::errosAgt($erros);
            $this->atualizar($v, ['estado' => 'INVALIDO', 'erros' => $lista, 'ultima_consulta' => now()->toIso8601String()],
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

    /** Actualiza fe_envio com histórico (últimos 20 eventos). */
    private function atualizar(Venda $v, array $mods, string $accao, string $resultado): void
    {
        $anterior = $v->fe_envio ?? [];
        $historico = array_slice([...($anterior['historico'] ?? []), ['em' => now()->toIso8601String(), 'accao' => $accao, 'resultado' => mb_substr($resultado, 0, 500)]], -20);
        $v->forceFill(['fe_envio' => array_merge($anterior, $mods, ['historico' => $historico])])->save();
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
