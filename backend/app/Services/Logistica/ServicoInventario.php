<?php

namespace App\Services\Logistica;

use App\Exceptions\ErroNegocio;
use App\Models\LinhaSessaoInventario;
use App\Models\MovimentoInventario;
use App\Models\Produto;
use App\Models\SessaoInventario;
use App\Models\StockArmazem;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Inventário físico (js/ui_inventory.js). Paridade: uma sessão por armazém de cada vez; fotografia da quantidade
 * teórica de todos os produtos de stock (incluindo os de stock zero); contagem cega; revisão com custo e
 * justificação por linha; aprovação → regularização (sobras: D inventário / C sobras; quebras: D quebras /
 * C inventário) no diário SQ com o documento INV AAAA/id; reabrir volta à revisão.
 * Correcções (ADR-042):
 *   - o armazém fica sem movimentos durante a contagem (ServicoStock) — no legado a diferença era calculada contra
 *     uma fotografia desactualizada;
 *   - linhas por contar não passam a zero em silêncio: é preciso confirmá-lo (no legado o aviso nunca aparecia por
 *     um erro no nome do campo);
 *   - a regularização usa a data da sessão (o legado usava sempre a data de hoje) e cada linha fica valorizada ao
 *     custo médio (ou ao custo indicado na revisão) — no legado os movimentos não tinham preço;
 *   - sem conta de sobras/quebras a aprovação é recusada (o legado mexia no stock sem lançar nada);
 *   - aprovação por outra pessoa que não quem abriu a contagem (em vez de uma palavra-passe de administrador);
 *   - anular deixa a sessão ANULADA com motivo (o legado apagava-a); reabrir é um estorno, não apagar lançamentos.
 */
final class ServicoInventario
{
    public function __construct(
        private readonly ServicoStock $stock,
        private readonly ServicoConfigLogistica $config,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
    ) {}

    public function abrir(int $armazem, string $data, ?string $descricao): SessaoInventario
    {
        return DB::transaction(function () use ($armazem, $data, $descricao) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["inventario:armazem:{$armazem}"]);
            if (SessaoInventario::query()->where('armazem_id', $armazem)->whereIn('estado', ['EM_CONTAGEM', 'REVISAO'])->exists()) {
                throw new ErroNegocio('Já há um inventário em curso neste armazém.', 'INVENTARIO_EM_CURSO', 422);
            }
            $s = SessaoInventario::create(['armazem_id' => $armazem, 'data' => $data, 'descricao' => $descricao, 'estado' => 'EM_CONTAGEM', 'tipo' => 'GERAL',
                'iniciado_por' => Auth::user()?->nome_utilizador]);
            $stock = StockArmazem::query()->where('armazem_id', $armazem)->pluck('quantidade_stock', 'produto_id');
            foreach (Produto::query()->where('movimenta_stock', true)->orderBy('codigo')->get(['id']) as $p) {
                LinhaSessaoInventario::create(['sessao_inventario_id' => $s->id, 'produto_id' => $p->id, 'quantidade_sistema' => $stock[$p->id] ?? 0, 'quantidade_contada' => null]);
            }

            return $s->refresh();
        });
    }

    /** @param  list<array{produto_id: int, quantidade_contada: mixed, observacoes?: ?string}>  $linhas */
    public function contar(SessaoInventario $s, array $linhas): int
    {
        $this->exigirEstado($s, ['EM_CONTAGEM']);
        $n = 0;
        foreach ($linhas as $l) {
            $linha = LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->where('produto_id', $l['produto_id'])->first();
            if (! $linha) {   // produto criado depois da abertura
                $p = Produto::query()->where('movimenta_stock', true)->findOrFail($l['produto_id']);
                $linha = LinhaSessaoInventario::create(['sessao_inventario_id' => $s->id, 'produto_id' => $p->id,
                    'quantidade_sistema' => StockArmazem::query()->where('armazem_id', $s->armazem_id)->where('produto_id', $p->id)->value('quantidade_stock') ?? 0]);
            }
            $q = $l['quantidade_contada'];
            if ($q !== null && (float) $q < 0) {
                throw new ErroNegocio('A quantidade contada não pode ser negativa.', 'QUANTIDADE_INVALIDA', 422);
            }
            $linha->update(['quantidade_contada' => $q, 'observacoes' => $l['observacoes'] ?? $linha->observacoes]);
            $n++;
        }

        return $n;
    }

    /** Fim da contagem → revisão. Linhas por contar só passam a zero se isso for confirmado. */
    public function concluirContagem(SessaoInventario $s, bool $porContarComoZero = false): SessaoInventario
    {
        $this->exigirEstado($s, ['EM_CONTAGEM']);
        $porContar = LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->whereNull('quantidade_contada')->count();
        if ($porContar && ! $porContarComoZero) {
            throw new ErroNegocio("Há {$porContar} produto(s) por contar. Conte-os ou confirme que a quantidade é zero.", 'LINHAS_POR_CONTAR', 422, ['por_contar' => $porContar]);
        }
        DB::transaction(function () use ($s) {
            LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->whereNull('quantidade_contada')->update(['quantidade_contada' => 0]);
            foreach (LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->get() as $l) {
                $l->update(['diferenca' => bcsub((string) $l->quantidade_contada, (string) $l->quantidade_sistema, 3)]);
            }
            $s->update(['estado' => 'REVISAO']);
        });

        return $s->refresh();
    }

    /** @param  list<array{produto_id: int, custo_personalizado?: mixed, justificacao?: ?string}>  $linhas */
    public function rever(SessaoInventario $s, array $linhas): void
    {
        $this->exigirEstado($s, ['REVISAO']);
        foreach ($linhas as $l) {
            LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->where('produto_id', $l['produto_id'])->firstOrFail()
                ->update(['custo_personalizado' => $l['custo_personalizado'] ?? null, 'justificacao' => $l['justificacao'] ?? null]);
        }
    }

    public function voltarContagem(SessaoInventario $s): SessaoInventario
    {
        $this->exigirEstado($s, ['REVISAO']);
        $s->update(['estado' => 'EM_CONTAGEM']);

        return $s->refresh();
    }

    /** Aprovação: regularização do stock e lançamento no diário SQ, à data da sessão. */
    public function aprovar(SessaoInventario $s): SessaoInventario
    {
        return DB::transaction(function () use ($s) {
            $s = SessaoInventario::query()->lockForUpdate()->findOrFail($s->id);
            $this->exigirEstado($s, ['REVISAO']);
            if ($s->iniciado_por && $s->iniciado_por === Auth::user()?->nome_utilizador) {
                throw new ErroNegocio('A regularização tem de ser aprovada por outra pessoa que não quem abriu a contagem.', 'SEGREGACAO_FUNCOES', 403);
            }
            $data = $s->data->toDateString();
            $numero = sprintf('INV %s/%d', $s->data->format('Y'), $s->id);
            $gl = [];
            $somar = function (string $conta, string $dc, string $valor) use (&$gl) {
                $gl["{$conta}|{$dc}"] ??= ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => '0.00'];
                $gl["{$conta}|{$dc}"]['valor'] = bcadd($gl["{$conta}|{$dc}"]['valor'], $valor, 2);
            };
            foreach (LinhaSessaoInventario::query()->where('sessao_inventario_id', $s->id)->get() as $l) {
                $dif = (string) $l->diferenca;
                if (bccomp($dif, '0', 3) === 0) {
                    continue;
                }
                $p = Produto::query()->findOrFail($l->produto_id);
                $custo = $l->custo_personalizado !== null ? (string) $l->custo_personalizado : (string) ($p->custo_medio ?? '0');
                $sobra = bccomp($dif, '0', 3) > 0;
                $ajuste = $this->stock->ajustar($p->id, $s->armazem_id, $sobra ? 'E' : 'S', ltrim($dif, '-'), $custo, $data, "Regularização de inventário {$numero}".($l->justificacao ? ": {$l->justificacao}" : ''),
                    ['documento_tipo' => 'INVENTARIO', 'documento_id' => $s->id, 'ignorar_inventario' => true, 'permitir_negativo' => true]);
                $mov = $ajuste['movimento'];
                // decisão 15: sobra sobre stock negativo — acerto do CMV das unidades vendidas a descoberto
                if (bccomp($ajuste['acerto_cmv'], '0', 2) !== 0) {
                    foreach (ServicoStock::linhasAcertoCmv($ajuste['acerto_cmv'], $this->config->contaCusto($p), $this->config->contaInventario($p)) as $a) {
                        $somar($a['codigo_conta'], $a['tipo_dc'], $a['valor']);
                    }
                }
                $valor = (string) $mov->valor;
                $l->update(['custo_unitario' => $custo, 'valor_diferenca' => $sobra ? $valor : '-'.$valor]);
                if (bccomp($valor, '0', 2) > 0) {
                    $inv = $this->config->contaInventario($p);
                    $sobra ? [$somar($inv, 'D', $valor), $somar($this->config->contaSobras($p), 'C', $valor)]
                        : [$somar($this->config->contaQuebras($p), 'D', $valor), $somar($inv, 'C', $valor)];
                }
            }
            $lan = null;
            if ($gl) {
                $lan = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('SQ', 'Regularizações de inventário')->id, 'data_documento' => $data,
                    'numero_documento' => $numero, 'descricao' => "Regularização de inventário {$numero}", 'tipo_origem' => 'INVENTARIO', 'linhas' => array_values($gl)])->first()->numero_lan;
            }
            $s->update(['estado' => 'CONCLUIDA', 'aprovado_por' => Auth::user()?->nome_utilizador, 'aprovado_em' => now(), 'numero_lan_contabilizacao' => $lan]);

            return $s->refresh();
        });
    }

    /** Reabrir: estorna o lançamento e anula os ajustes (movimentos inversos); a sessão volta à revisão. */
    public function reabrir(SessaoInventario $s, string $motivo): SessaoInventario
    {
        return DB::transaction(function () use ($s, $motivo) {
            $s = SessaoInventario::query()->lockForUpdate()->findOrFail($s->id);
            $this->exigirEstado($s, ['CONCLUIDA']);
            if ($s->numero_lan_contabilizacao) {
                $this->lancamentos->estornar($this->localizador->localizar($s->numero_lan_contabilizacao, sprintf('INV %s/%d', $s->data->format('Y'), $s->id)), $motivo);
            }
            // saldo líquido por produto dos ajustes da sessão (regularizações e anulações anteriores): anula-se só o que resta
            $liquido = [];
            foreach (MovimentoInventario::query()->whereIn('documento_tipo', ['INVENTARIO', 'INVENTARIO_ANULACAO'])->where('documento_id', $s->id)->where('tipo', 'AJUSTE')->get() as $m) {
                $k = "{$m->produto_id}|{$m->armazem_id}";
                $liquido[$k] ??= ['q' => '0', 'v' => '0'];
                $sinal = $m->sentido === 'E' ? '' : '-';
                $liquido[$k]['q'] = bcadd($liquido[$k]['q'], $sinal.$m->quantidade, 3);
                $liquido[$k]['v'] = bcadd($liquido[$k]['v'], $sinal.$m->valor, 2);
            }
            foreach ($liquido as $k => $x) {
                if (bccomp($x['q'], '0', 3) === 0) {
                    continue;
                }
                [$produto, $armazem] = array_map('intval', explode('|', $k));
                $q = ltrim($x['q'], '-');
                $this->stock->ajustar($produto, $armazem, bccomp($x['q'], '0', 3) > 0 ? 'S' : 'E', $q, bcdiv(ltrim($x['v'], '-'), $q, 6), now()->toDateString(),
                    sprintf('Anulação da regularização INV %s/%d: %s', $s->data->format('Y'), $s->id, $motivo),
                    ['documento_tipo' => 'INVENTARIO_ANULACAO', 'documento_id' => $s->id, 'ignorar_inventario' => true, 'permitir_negativo' => true]);
            }
            $s->update(['estado' => 'REVISAO', 'aprovado_por' => null, 'aprovado_em' => null, 'numero_lan_contabilizacao' => null]);

            return $s->refresh();
        });
    }

    public function anular(SessaoInventario $s, string $motivo): SessaoInventario
    {
        $this->exigirEstado($s, ['EM_CONTAGEM', 'REVISAO']);
        $s->update(['estado' => 'ANULADA', 'motivo_anulacao' => $motivo]);

        return $s->refresh();
    }

    private function exigirEstado(SessaoInventario $s, array $estados): void
    {
        if (! in_array($s->estado, $estados, true)) {
            throw new ErroNegocio("O inventário está {$s->estado}: operação não permitida.", 'ESTADO_INVALIDO', 422);
        }
    }
}
