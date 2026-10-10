<?php

namespace App\Services\Orcamento;

use App\Models\CentroCusto;
use App\Models\LinhaOrcamento;
use App\Models\OrcamentoAnual;
use App\Models\PlanoConta;
use App\Models\Projeto;
use App\Models\RubricaOrcamental;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Paridade do Orçamento com o sistema anterior (verificação visual de 2026-10-08, secção 11) — só leituras.
 *   - lista de orçamentos como em orcamento_ui.js:291-326: ordem de orcamento_dados.js:156 (ano ↓, tipo, chave das
 *     dimensões, versão ↓), hierarquia (contributos/metas por baixo do pai), «Dimensões» («UN x · CC y · Proj. z» ou «Toda a
 *     empresa»), «Total das rubricas» e «Última acção»; e o orçamento por omissão do Controlo (orcamento_ui.js:709: o primeiro
 *     aprovado dessa ordem, senão o primeiro);
 *   - causas de um desvio como em orcamento_planeamento.js:576-630 (orcAnalisarDesvio): a análise do serviço de planeamento
 *     mais a descrição das contas, os maiores movimentos por valor absoluto e o peso por terceiro (clientes / fornecedores).
 */
final class ServicoParidadeOrcamento
{
    public const TIPOS = ['EXPLORACAO' => 'Exploração', 'TESOURARIA' => 'Tesouraria'];

    public const ESTADOS = ['RASCUNHO' => 'Rascunho', 'SUBMETIDO' => 'Submetido', 'APROVADO' => 'Aprovado', 'SUBSTITUIDO' => 'Substituído'];

    public function __construct(
        private readonly ServicoPlaneamentoOrcamental $plano,
        private readonly ContextoEmpresa $contexto,
    ) {}

    /** Chave das dimensões do legado (orcamento_dados.js:152). */
    public static function chave(OrcamentoAnual $o): string
    {
        return "{$o->ano}|{$o->tipo}|".($o->unidade_negocio_id ?: '').'|'.($o->centro_custo_id ?: '').($o->projeto_id ? '|P'.$o->projeto_id : '');
    }

    /**
     * Comparação das chaves como o String.localeCompare do legado: o separador «|» (pontuação) ordena antes dos algarismos e das
     * letras, por isso «Toda a empresa» (dimensão vazia) vem antes dos orçamentos por UN/CC/projecto.
     */
    public static function compararChaves(string $a, string $b): int
    {
        return strcmp(str_replace('|', "\x01", $a), str_replace('|', "\x01", $b)) <=> 0;
    }

    /**
     * @return array{orcamentos: list<array<string, mixed>>, por_omissao: int|null}
     */
    public function lista(?int $ano = null, ?string $tipo = null): array
    {
        $todos = OrcamentoAnual::query()->when($ano, fn ($q, $a) => $q->where('ano', $a))->when($tipo, fn ($q, $t) => $q->where('tipo', $t))->get()
            ->sort(fn (OrcamentoAnual $a, OrcamentoAnual $b) => ($b->ano <=> $a->ano) ?: strcmp((string) $a->tipo, (string) $b->tipo) ?: self::compararChaves(self::chave($a), self::chave($b))
                ?: ($b->versao <=> $a->versao) ?: ($a->id <=> $b->id))
            ->values();
        $ids = $todos->pluck('id')->all();
        $uns = UnidadeNegocio::query()->whereIn('id', $todos->pluck('unidade_negocio_id')->filter()->unique()->values()->all())->pluck('codigo', 'id');
        $ccs = CentroCusto::query()->whereIn('id', $todos->pluck('centro_custo_id')->filter()->unique()->values()->all())->pluck('codigo', 'id');
        $pjs = Projeto::query()->whereIn('id', $todos->pluck('projeto_id')->filter()->unique()->values()->all())->pluck('codigo', 'id');
        $totais = $ids ? LinhaOrcamento::query()->whereIn('orcamento_anual_id', $ids)->groupBy('orcamento_anual_id')
            ->selectRaw('orcamento_anual_id, COALESCE(SUM(total), 0) AS t')->pluck('t', 'orcamento_anual_id') : collect();

        $porId = $todos->keyBy('id');
        $ordenada = [];
        $juntar = function (OrcamentoAnual $o, int $nivel) use (&$juntar, &$ordenada, $todos) {
            if (isset($ordenada[$o->id])) {
                return;   // protecção contra ciclos pai ↔ filho
            }
            $ordenada[$o->id] = $nivel;
            $todos->filter(fn ($f) => (int) $f->orcamento_pai_id === $o->id)->each(fn ($f) => $juntar($f, $nivel + 1));
        };
        $todos->filter(fn ($o) => ! $o->orcamento_pai_id || ! $porId->has($o->orcamento_pai_id))->each(fn ($o) => $juntar($o, 0));
        $todos->each(fn ($o) => $juntar($o, 0));   // restos de ciclos

        $linhas = [];
        foreach ($ordenada as $id => $nivel) {
            $o = $porId[$id];
            $dims = implode(' · ', array_filter([
                $o->unidade_negocio_id ? 'UN '.($uns[$o->unidade_negocio_id] ?? "#{$o->unidade_negocio_id}") : '',
                $o->centro_custo_id ? 'CC '.($ccs[$o->centro_custo_id] ?? "#{$o->centro_custo_id}") : '',
                $o->projeto_id ? 'Proj. '.($pjs[$o->projeto_id] ?? "#{$o->projeto_id}") : '',
            ])) ?: 'Toda a empresa';
            $ultima = $o->aprovado_em ? ['accao' => 'APROVADO', 'por' => $o->aprovado_por, 'em' => $o->aprovado_em?->toIso8601String()]
                : ($o->submetido_em && $o->estado === 'SUBMETIDO' ? ['accao' => 'SUBMETIDO', 'por' => $o->submetido_por, 'em' => $o->submetido_em?->toIso8601String()]
                    : ['accao' => 'CRIADO', 'por' => $o->criado_por, 'em' => $o->criado_em?->toIso8601String()]);
            $linhas[] = $o->toArray() + [
                'nivel' => $nivel,
                'dimensoes' => $dims,
                'total_rubricas' => round((float) ($totais[$o->id] ?? 0), 2),
                'ultima_accao' => $ultima,
                'rotulo' => "{$o->ano} · ".(self::TIPOS[$o->tipo] ?? $o->tipo)." · {$dims} · v{$o->versao} (".(self::ESTADOS[$o->estado] ?? $o->estado).')',
            ];
        }
        $omissao = $todos->firstWhere('estado', 'APROVADO') ?? $todos->first();

        return ['orcamentos' => $linhas, 'por_omissao' => $omissao?->id];
    }

    /**
     * Análise do desvio de uma rubrica com as causas (contas com descrição, maiores movimentos, peso por terceiro).
     *
     * @return array<string, mixed>
     */
    public function causas(OrcamentoAnual $o, int $rubrica, int $m0, int $m1): array
    {
        if ($m1 < $m0) {
            [$m0, $m1] = [$m1, $m0];
        }
        $a = $this->plano->analisarDesvio($o, $rubrica, $m0, $m1);
        $r = RubricaOrcamental::query()->findOrFail($rubrica);
        $descricoes = PlanoConta::query()->whereIn('codigo', array_map('strval', array_column($a['contas'], 'conta')))->pluck('descricao', 'codigo');
        $a['contas'] = array_map(fn ($c) => $c + ['descricao' => $descricoes[(string) $c['conta']] ?? null], $a['contas']);
        $a['terceiros'] = [];
        if ($o->tipo === 'EXPLORACAO' && ! empty($r->contas)) {
            $entrada = in_array($r->natureza, ['PROVEITO', 'RECEBIMENTO'], true);
            $base = $this->movimentos($o, $r, $m0, $m1);
            $sinal = $entrada ? "CASE WHEN l.tipo_dc = 'C' THEN l.valor ELSE -l.valor END" : "CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END";
            // maiores movimentos pelo valor absoluto (o legado ordena por |valor|), com o sinal da rubrica
            $a['maiores_movimentos'] = (clone $base)->orderByRaw('l.valor DESC')->orderBy('l.id')->limit(10)
                ->get(['l.data_documento', 'l.numero_documento', 'l.codigo_conta', 'l.descricao', 'l.tipo_dc', 'l.valor', 'l.terceiro_id', DB::raw("({$sinal}) AS valor_rubrica")])
                ->map(fn ($m) => (array) $m)->all();
            $porTerceiro = (clone $base)->groupBy('l.terceiro_id')->selectRaw("l.terceiro_id, SUM({$sinal}) AS v")->get();
            $nomes = Terceiro::query()->whereIn('id', $porTerceiro->pluck('terceiro_id')->filter()->all())->pluck('nome', 'id');
            $soma = $porTerceiro->sum(fn ($x) => abs((float) $x->v)) ?: 1.0;
            $a['terceiros'] = $porTerceiro->map(fn ($x) => ['terceiro_id' => $x->terceiro_id ? (int) $x->terceiro_id : null,
                'nome' => $x->terceiro_id ? ($nomes[$x->terceiro_id] ?? "#{$x->terceiro_id}") : 'Sem terceiro',
                'valor' => round((float) $x->v, 2), 'peso_pct' => round(abs((float) $x->v) / $soma * 100, 1)])
                ->sortByDesc(fn ($x) => abs($x['valor']))->take(8)->values()->all();
        }
        $a['periodo'] = ['de' => $m0, 'ate' => $m1, 'ano' => $o->ano];
        $a['tipo_orcamento'] = $o->tipo;

        return $a;
    }

    /** Movimentos do Diário das contas da rubrica no período, com os filtros UN/CC/projecto do orçamento (sem apuramento nem classe 9). */
    private function movimentos(OrcamentoAnual $o, RubricaOrcamental $r, int $m0, int $m1)
    {
        $de = sprintf('%04d-%02d-01', $o->ano, $m0);
        $ate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $o->ano, $m1)));

        return DB::table('lancamentos_contabeis as l')->leftJoin('diarios_contabeis as d', 'd.id', '=', 'l.diario_id')
            ->where('l.empresa_id', $this->contexto->obrigatorio())->whereBetween('l.data_documento', [$de, $ate])
            ->where('l.codigo_conta', 'not like', '9%')
            ->where(fn ($q) => $q->whereNull('d.codigo')->orWhere('d.codigo', 'not like', 'AP-%'))
            ->where(function ($q) use ($r) {
                foreach ((array) $r->contas as $e) {
                    $codigo = (string) ($e['codigo'] ?? '');
                    if ($codigo === '') {
                        continue;
                    }
                    ! empty($e['prefixo']) ? $q->orWhere('l.codigo_conta', 'like', str_replace(['%', '_'], ['\%', '\_'], $codigo).'%') : $q->orWhere('l.codigo_conta', $codigo);
                }
            })
            ->when($o->unidade_negocio_id, fn ($q, $v) => $q->where('l.unidade_negocio_id', $v))
            ->when($o->centro_custo_id, fn ($q, $v) => $q->where('l.centro_custo_id', $v))
            ->when($o->projeto_id, fn ($q, $v) => $q->where('l.projeto_id', $v));
    }
}
