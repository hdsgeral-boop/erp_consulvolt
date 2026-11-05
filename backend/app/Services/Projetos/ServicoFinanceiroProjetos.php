<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\FaturacaoProjeto;
use App\Models\MarcoProjeto;
use App\Models\Projeto;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Collection;

/**
 * Separador «Financeiro» do projecto (docs/PROJECTOS_FINANCEIRO.md). Agrega o que o sistema já calcula — orçamento,
 * custos reais (compras, folhas de horas, autos, equipamentos), compromissos, facturas de venda ligadas ao projecto e
 * progresso das tarefas — e acrescenta o plano de facturação (`faturacao_projetos`) e os indicadores de valor acrescentado.
 * A emissão de facturas continua a ser a da Faturação (série, hash, AGT): aqui só se ASSOCIA uma factura já emitida
 * para o projecto a uma prestação do plano. Indicadores sem base de cálculo ficam a null com a explicação em `ocultos`.
 */
final class ServicoFinanceiroProjetos
{
    public function __construct(private readonly ServicoAnaliticoProjetos $analitico, private readonly ServicoAuditoria $auditoria) {}

    /** @return array<string, mixed> */
    public function painel(Projeto $p): array
    {
        $r = $this->analitico->resumo($p);
        $i = $r['indicadores'];
        $hoje = now()->toDateString();
        $pctExec = isset($i['execucao_ponderada']) ? (float) $i['execucao_ponderada'] : (isset($i['execucao']) ? (float) $i['execucao'] : null);
        $curva = $r['curva_s'] ?? null;
        $pctPlan = $curva ? FormulasFinanceirasProjeto::pctPlaneada($curva['meses'], $curva['previsto'], $hoje) : null;
        $bac = (float) $i['orcamento'];
        $evm = FormulasFinanceirasProjeto::evm($bac, $pctPlan, $pctExec, (float) $i['custo']);

        $ocultos = [];
        if ($bac <= 0) {
            $ocultos[] = 'Sem orçamento no projecto: os indicadores de valor acrescentado (PV, EV, CPI, SPI, EAC) não se calculam.';
        } elseif ($evm['cpi'] === null) {
            $ocultos[] = 'CPI/EAC/ETC/VAC aparecem quando houver custo real registado e execução medida nas tarefas.';
        }
        if ($evm['spi'] === null && $bac > 0) {
            $ocultos[] = 'SPI aparece quando o planeado até hoje for superior a zero (datas das tarefas ou do projecto).';
        }

        $vendas = collect($this->analitico->vendasDosProjetos(collect([$p->id => $p])))->where('fonte', 'VENDA');
        $faturas = $vendas->where('tipo_documento', '!=', 'NC');
        $recebido = $faturas->sum(fn ($m) => max(0.0, (float) $m['total_bruto'] - (float) $m['valor_pendente']));
        $facturadoBruto = $faturas->sum(fn ($m) => (float) $m['total_bruto']);

        $externo = $p->externo();
        $vendaGlobal = (float) ($i['venda_global'] ?? 0);
        $facturado = (float) ($i['faturado'] ?? 0);
        $receita = $externo && $vendaGlobal > 0 ? FormulasFinanceirasProjeto::receita($vendaGlobal, $pctExec, $facturado) : null;
        if (! $externo) {
            $ocultos[] = 'Projecto interno: não há receita nem facturação a cliente.';
        }

        $plano = $this->plano($p, $vendas);
        $retencao = array_sum(array_map(fn ($l) => $l['retencao'], array_filter($plano, fn ($l) => $l['estado'] === 'FACTURADO')));
        $consumo = (float) ($i['consumo_pct'] ?? 0);

        return [
            'indicadores' => [
                'orcamento' => $bac, 'custo_real' => (float) $i['custo'], 'compromissos' => (float) ($i['compromissos'] ?? 0),
                'orcamento_disponivel' => round($bac - (float) $i['custo'] - (float) ($i['compromissos'] ?? 0), 2), 'consumo_pct' => $consumo,
                'venda_global' => $externo ? $vendaGlobal : null, 'facturado' => $externo ? $facturado : null, 'facturado_com_iva' => $externo ? round($facturadoBruto, 2) : null,
                'recebido' => $externo ? round($recebido, 2) : null, 'por_receber' => $externo ? round($facturadoBruto - $recebido, 2) : null,
                'saldo_a_facturar' => $externo ? round($vendaGlobal - $facturado, 2) : null, 'retencao' => $externo ? round($retencao, 2) : null,
                'margem_bruta' => $externo ? round($facturado - (float) $i['custo'], 2) : null,
                'margem_bruta_pct' => $externo && $facturado > 0 ? round(($facturado - (float) $i['custo']) / $facturado * 100, 2) : null,
                'margem_prevista_pct' => $externo ? ($i['margem_prevista_pct'] ?? null) : null,
                'execucao_pct' => $pctExec, 'planeado_pct' => $pctPlan,
            ],
            'evm' => $evm,
            'receita' => $receita,
            'curva_s' => $curva ? $curva + ['valor_acrescentado' => $this->curvaEv($curva, $bac, $pctExec, $hoje)] : null,
            'custos_por_categoria' => $r['orcado_realizado'],
            'origem_custos' => $r['origem_custos'],
            'plano' => $plano,
            'facturas_por_associar' => $faturas->filter(fn ($m) => ! in_array($m['fonte_id'], array_column(array_filter($plano, fn ($l) => $l['estado'] === 'FACTURADO'), 'venda_id'), true))
                ->map(fn ($m) => ['venda_id' => $m['fonte_id'], 'documento' => $m['documento'], 'data' => $m['data'], 'valor' => $m['valor']])->values()->all(),
            'fluxo_previsto' => $this->fluxoPrevisto($plano, $curva, $hoje),
            'alertas' => FormulasFinanceirasProjeto::alertas($evm, $consumo),
            'ocultos' => $ocultos,
        ];
    }

    /** EV acumulado: só se conhece o valor actual (último ponto até ao mês de hoje); os meses anteriores não se inventam (null). */
    private function curvaEv(array $curva, float $bac, ?float $pctExec, string $hoje): array
    {
        $ev = [];
        foreach ($curva['meses'] as $m) {
            $ev[] = $pctExec !== null && $bac > 0 && $m === substr($hoje, 0, 7) ? round($bac * $pctExec / 100, 2) : null;
        }

        return $ev;
    }

    /** @return list<array<string, mixed>> */
    public function plano(Projeto $p, ?Collection $vendas = null): array
    {
        $vendas ??= collect($this->analitico->vendasDosProjetos(collect([$p->id => $p])))->where('fonte', 'VENDA');
        $porId = $vendas->keyBy('fonte_id');
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->pluck('nome', 'id');

        return FaturacaoProjeto::query()->where('projeto_id', $p->id)->orderBy('data_prevista')->orderBy('id')->get()->map(function (FaturacaoProjeto $l) use ($porId, $marcos) {
            $v = $l->venda_id ? ($porId[$l->venda_id] ?? null) : null;
            $facturado = $v ? (float) $v['valor'] : null;
            $pct = (float) $l->retencao_pct;

            return $l->toArray() + ['marco' => $l->marco_projeto_id ? ($marcos[$l->marco_projeto_id] ?? null) : null, 'valor_facturado' => $facturado,
                'documento' => $v['documento'] ?? $l->numero_documento, 'retencao' => $facturado !== null ? FormulasFinanceirasProjeto::retencao($facturado, $pct) : FormulasFinanceirasProjeto::retencao((float) $l->montante, $pct),
                'recebido' => $v ? round(max(0.0, (float) $v['total_bruto'] - (float) $v['valor_pendente']), 2) : null];
        })->all();
    }

    /**
     * Previsão do fluxo de caixa do projecto a partir de hoje: entradas = prestações PLANEADAS (líquidas da retenção) no mês da
     * data prevista; saídas = custo orçado ainda por distribuir (curva S prevista). Só existe com plano de facturação.
     *
     * @return list<array{mes: string, entradas: float, saidas: float, saldo: float}>|null
     */
    private function fluxoPrevisto(array $plano, ?array $curva, string $hoje): ?array
    {
        $pendentes = array_filter($plano, fn ($l) => $l['estado'] === 'PLANEADO' && $l['data_prevista']);
        if (! $pendentes || ! $curva) {
            return null;
        }
        $mes0 = substr($hoje, 0, 7);
        $entradas = [];
        foreach ($pendentes as $l) {
            $m = max($mes0, substr((string) $l['data_prevista'], 0, 7));
            $entradas[$m] = ($entradas[$m] ?? 0) + (float) $l['montante'] - (float) $l['retencao'];
        }
        $saidas = [];
        foreach ($curva['meses'] as $k => $m) {
            $saidas[$m] = round((float) $curva['previsto'][$k] - (float) ($curva['previsto'][$k - 1] ?? 0), 2);
        }
        $meses = array_values(array_unique(array_merge(array_filter(array_keys($saidas), fn ($m) => $m >= $mes0), array_keys($entradas))));
        sort($meses);
        $acum = 0.0;
        $out = [];
        foreach ($meses as $m) {
            $acum += ($entradas[$m] ?? 0) - ($saidas[$m] ?? 0);
            $out[] = ['mes' => $m, 'entradas' => round($entradas[$m] ?? 0, 2), 'saidas' => round($saidas[$m] ?? 0, 2), 'saldo' => round($acum, 2)];
        }

        return $out;
    }

    // ───────────── Plano de facturação ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarPrestacao(Projeto $p, array $d, ?FaturacaoProjeto $l = null): FaturacaoProjeto
    {
        if (! $p->externo()) {
            throw new ErroNegocio('Só os projectos com cliente (externos) têm plano de facturação.', 'PROJECTO_INTERNO', 422);
        }
        if ($l && $l->estado !== 'PLANEADO') {
            throw new ErroNegocio('Só se editam prestações planeadas (as facturadas ou canceladas ficam como estão).', 'PRESTACAO_NAO_EDITAVEL', 422);
        }
        if (! empty($d['marco_projeto_id']) && ! MarcoProjeto::query()->where('projeto_id', $p->id)->whereKey($d['marco_projeto_id'])->exists()) {
            throw new ErroNegocio('Marco inválido para este projecto.', 'MARCO_INVALIDO', 422);
        }
        $dados = ['descricao' => $d['descricao'], 'montante' => $d['montante'], 'data_prevista' => $d['data_prevista'] ?? null, 'retencao_pct' => $d['retencao_pct'] ?? 0,
            'marco_projeto_id' => $d['marco_projeto_id'] ?? null];
        if ($l) {
            $l->update($dados);
        } else {
            $l = FaturacaoProjeto::create($dados + ['projeto_id' => $p->id, 'estado' => 'PLANEADO']);
        }
        $this->auditoria->registar('Projectos', 'Plano de facturação', "{$p->codigo}: {$l->descricao} — {$l->montante} Kz", 'faturacao_projetos', $l->id);

        return $l->fresh();
    }

    public function cancelarPrestacao(FaturacaoProjeto $l): FaturacaoProjeto
    {
        if ($l->estado === 'FACTURADO') {
            throw new ErroNegocio('A prestação já tem factura associada: desassocie-a primeiro.', 'PRESTACAO_FACTURADA', 422);
        }
        $l->update(['estado' => 'CANCELADO']);
        $this->auditoria->registar('Projectos', 'Plano de facturação: prestação cancelada', $l->descricao ?? "#{$l->id}", 'faturacao_projetos', $l->id);

        return $l->fresh();
    }

    /** Associa uma factura (FT/FR) JÁ emitida e ligada a este projecto a uma prestação; não altera o documento fiscal. */
    public function associarFactura(Projeto $p, FaturacaoProjeto $l, int $vendaId): FaturacaoProjeto
    {
        if ($l->estado === 'CANCELADO') {
            throw new ErroNegocio('Prestação cancelada.', 'PRESTACAO_CANCELADA', 422);
        }
        $v = collect($this->analitico->vendasDosProjetos(collect([$p->id => $p])))->where('fonte', 'VENDA')->where('tipo_documento', '!=', 'NC')->firstWhere('fonte_id', $vendaId);
        if (! $v) {
            throw new ErroNegocio('Só se associam facturas (FT/FR) válidas ligadas a este projecto — emita-a na Faturação escolhendo o projecto.', 'FACTURA_NAO_DO_PROJECTO', 422);
        }
        if (FaturacaoProjeto::query()->where('venda_id', $vendaId)->where('id', '<>', $l->id)->where('estado', 'FACTURADO')->exists()) {
            throw new ErroNegocio('Essa factura já está associada a outra prestação.', 'FACTURA_JA_ASSOCIADA', 422);
        }
        $l->update(['venda_id' => $vendaId, 'numero_documento' => $v['documento'], 'data' => $v['data'], 'estado' => 'FACTURADO']);
        $this->auditoria->registar('Projectos', 'Plano de facturação: factura associada', "{$p->codigo}: {$l->descricao} ← {$v['documento']}", 'faturacao_projetos', $l->id);

        return $l->fresh();
    }

    public function desassociarFactura(FaturacaoProjeto $l): FaturacaoProjeto
    {
        $l->update(['venda_id' => null, 'numero_documento' => null, 'data' => null, 'estado' => 'PLANEADO']);
        $this->auditoria->registar('Projectos', 'Plano de facturação: factura desassociada', $l->descricao ?? "#{$l->id}", 'faturacao_projetos', $l->id);

        return $l->fresh();
    }
}
