<?php

namespace App\Http\Controllers\Api\Ativos;

use App\Http\Controllers\Controller;
use App\Models\AbateVendaAtivo;
use App\Models\AfetacaoAtivoProjeto;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\RegistoManutencaoAtivo;
use App\Models\TransferenciaCentroCustoAtivo;
use App\Services\Ativos\ServicoAbatesAtivos;
use App\Services\Ativos\ServicoAquisicoesAtivos;
use App\Services\Ativos\ServicoAtivos;
use App\Services\Ativos\ServicoCategoriasAtivos;
use App\Services\Ativos\ServicoManutencaoAtivos;
use App\Support\Api\RespostaApi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/ativos — categorias, cadastro, transferências, afectações a projectos, manutenções, aquisições pendentes e abates. */
final class AtivosController extends Controller
{
    private const VER = ['activos_view', 'activos_amortizacoes_view', 'activos_mapa_view', 'activos_abates_view', 'activos_manutencao_view', 'activos_pendentes_view'];

    public function __construct(
        private readonly ServicoCategoriasAtivos $categorias,
        private readonly ServicoAtivos $ativos,
        private readonly ServicoAquisicoesAtivos $aquisicoes,
        private readonly ServicoAbatesAtivos $abates,
        private readonly ServicoManutencaoAtivos $manutencao,
    ) {}

    // ───────────── Categorias ─────────────

    public function categorias(): JsonResponse
    {
        $this->exigir('activos_categorias_view', ...self::VER);
        $n = AtivoImobilizado::query()->selectRaw('categoria_ativo_id, COUNT(*) AS n')->groupBy('categoria_ativo_id')->pluck('n', 'categoria_ativo_id');

        return RespostaApi::sucesso(CategoriaAtivo::query()->orderBy('nome')->get()->map(fn ($c) => $c->toArray() + ['ativos' => (int) ($n[$c->id] ?? 0)]), 'Categorias de imobilizado.');
    }

    public function guardarCategoria(Request $r, ?int $categoria = null): JsonResponse
    {
        $this->exigir('activos_cat_gerir');
        $d = $r->validate([
            'nome' => [$categoria ? 'sometimes' : 'required', 'string', 'max:255'], 'taxa_anual' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'vida_util_padrao' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'conta_gasto' => ['sometimes', 'nullable', 'string', 'max:20'], 'conta_amortizacao_acumulada' => ['sometimes', 'nullable', 'string', 'max:20'],
            'conta_venda' => ['sometimes', 'nullable', 'string', 'max:20'], 'conta_perda' => ['sometimes', 'nullable', 'string', 'max:20'],
            'conta_ativo' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $c = $categoria ? CategoriaAtivo::query()->findOrFail($categoria) : null;
        $res = $this->categorias->guardar($d, $c);

        return $c ? RespostaApi::sucesso($res, 'Categoria actualizada.') : RespostaApi::criado($res, 'Categoria registada.');
    }

    public function eliminarCategoria(int $categoria): JsonResponse
    {
        $this->exigir('activos_cat_gerir');
        $this->categorias->eliminar(CategoriaAtivo::query()->findOrFail($categoria));

        return RespostaApi::sucesso(null, 'Categoria eliminada.');
    }

    // ───────────── Cadastro ─────────────

    public function bens(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', Rule::in(AtivoImobilizado::ESTADOS)], 'categoria_ativo_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'],
            'unidade_negocio_id' => ['nullable', 'integer'], 'texto' => ['nullable', 'string', 'max:100'], 'sem_lancamento' => ['nullable', 'boolean'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:1000'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $q = AtivoImobilizado::query()->with(['categoriaAtivo:id,nome,taxa_anual', 'centroCusto:id,codigo', 'unidadeNegocio:id,codigo', 'fornecedor:id,nome']);
        foreach (['estado', 'categoria_ativo_id', 'centro_custo_id', 'unidade_negocio_id'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }
        $q->when($f['texto'] ?? null, fn ($q, $t) => $q->where(fn ($q) => $q->where('codigo', 'ilike', "%{$t}%")->orWhere('descricao', 'ilike', "%{$t}%")));
        $q->when($f['sem_lancamento'] ?? false, fn ($q) => $q->whereNull('lancamento_contabil_id'));
        $pagina = $q->orderBy('codigo')->paginate($f['por_pagina'] ?? 500, ['*'], 'pagina', $f['pagina'] ?? 1);
        $pagina->setCollection($this->ativos->enriquecer($pagina->getCollection()));

        return RespostaApi::paginado($pagina, null, 'Activos imobilizados.');
    }

    public function bem(int $bem): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->ativos->ficha(AtivoImobilizado::query()->findOrFail($bem)), 'Ficha do activo.');
    }

    public function guardarBem(Request $r, ?int $bem = null): JsonResponse
    {
        $this->exigir('activos_gerir');
        $d = $r->validate($this->regrasFicha($bem !== null));
        $a = $bem ? AtivoImobilizado::query()->findOrFail($bem) : null;
        $res = $this->ativos->guardar($d, $a);

        return $a ? RespostaApi::sucesso($res, 'Activo actualizado.') : RespostaApi::criado($res, "Activo {$res->codigo} registado.");
    }

    public function eliminarBem(int $bem): JsonResponse
    {
        $this->exigir('activos_eliminar');
        $this->ativos->eliminar(AtivoImobilizado::query()->findOrFail($bem));

        return RespostaApi::sucesso(null, 'Activo eliminado.');
    }

    public function eliminarBens(Request $r): JsonResponse
    {
        $this->exigir('activos_eliminar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer']]);

        return RespostaApi::sucesso(['eliminados' => $this->ativos->eliminarVarios($d['ids'])], 'Activos eliminados.');
    }

    public function editarBens(Request $r): JsonResponse
    {
        $this->exigir('activos_gerir');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer'], 'campos' => ['required', 'array']]
            + collect($this->regrasFicha(true))->only(ServicoAtivos::CAMPOS_MASSA)->mapWithKeys(fn ($v, $k) => ["campos.{$k}" => $v])->all());

        return RespostaApi::sucesso(['actualizados' => $this->ativos->editarVarios($d['ids'], $d['campos'])], 'Activos actualizados.');
    }

    public function importarBens(Request $r): JsonResponse
    {
        $this->exigir('activos_gerir');
        $d = $r->validate(['decisao' => ['nullable', 'in:IGNORAR,ACTUALIZAR'], 'simular' => ['nullable', 'boolean'], 'linhas' => ['required', 'array', 'min:1', 'max:5000'],
            'linhas.*.codigo' => ['nullable'], 'linhas.*.descricao' => ['nullable', 'string', 'max:2000'], 'linhas.*.valor_aquisicao' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.categoria' => ['nullable', 'string', 'max:255'], 'linhas.*.vida_util' => ['nullable', 'integer', 'min:0'], 'linhas.*.anos_amortizados' => ['nullable', 'numeric', 'min:0'],
            'linhas.*.amortizacao_acumulada' => ['nullable', 'numeric', 'min:0'], 'linhas.*.ano_amortizacao_acumulada' => ['nullable', 'integer', 'between:1900,2100'],
            'linhas.*.data_aquisicao' => ['nullable']]);
        $res = $this->ativos->importar($d['linhas'], $d['decisao'] ?? 'IGNORAR', (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, ($d['simular'] ?? false) ? 'Simulação da importação.' : "Importação concluída: {$res['importados']} activo(s) importado(s).");
    }

    // ───────────── Transferências e afectações ─────────────

    public function transferir(Request $r, int $bem): JsonResponse
    {
        $this->exigir('activos_gerir');
        $d = $r->validate(['centro_custo_destino_id' => ['required', 'integer'], 'data' => ['required', 'date'], 'projeto_id' => ['nullable', 'integer']]);

        return RespostaApi::criado($this->ativos->transferir(AtivoImobilizado::query()->findOrFail($bem), (int) $d['centro_custo_destino_id'], $d['data'],
            isset($d['projeto_id']) ? (int) $d['projeto_id'] : null), 'Transferência concluída.');
    }

    public function transferencias(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['ativo_imobilizado_id' => ['nullable', 'integer'], 'projeto_id' => ['nullable', 'integer'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'],
            'pagina' => ['nullable', 'integer', 'min:1']]);
        $q = TransferenciaCentroCustoAtivo::query()->with(['ativoImobilizado:id,codigo,descricao', 'centroCustoOrigem:id,codigo,descricao', 'centroCustoDestino:id,codigo,descricao']);
        foreach (['ativo_imobilizado_id', 'projeto_id'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }

        return RespostaApi::paginado($q->orderByDesc('data')->orderByDesc('id')->paginate($f['por_pagina'] ?? 100, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Transferências de centro de custo.');
    }

    public function afetacoes(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['ativo_imobilizado_id' => ['nullable', 'integer'], 'projeto_id' => ['nullable', 'integer']]);
        $q = AfetacaoAtivoProjeto::query()->with(['ativoImobilizado:id,codigo,descricao', 'projeto:id,codigo,nome']);
        foreach (['ativo_imobilizado_id', 'projeto_id'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }

        return RespostaApi::sucesso($q->orderByDesc('data_inicio')->get(), 'Afectações de activos a projectos.');
    }

    public function guardarAfetacao(Request $r, ?int $afetacao = null): JsonResponse
    {
        $this->exigir('activos_gerir');
        $d = $r->validate(['projeto_id' => [$afetacao ? 'sometimes' : 'required', 'integer'], 'ativo_imobilizado_id' => [$afetacao ? 'sometimes' : 'required', 'integer'],
            'data_inicio' => [$afetacao ? 'sometimes' : 'required', 'date'], 'data_fim' => ['sometimes', 'nullable', 'date']]);
        $f = $afetacao ? AfetacaoAtivoProjeto::query()->findOrFail($afetacao) : null;
        $res = $this->ativos->guardarAfetacao($d, $f);

        return $f ? RespostaApi::sucesso($res, 'Afectação actualizada.') : RespostaApi::criado($res, 'Afectação registada.');
    }

    public function eliminarAfetacao(int $afetacao): JsonResponse
    {
        $this->exigir('activos_gerir');
        AfetacaoAtivoProjeto::query()->findOrFail($afetacao)->delete();

        return RespostaApi::sucesso(null, 'Afectação eliminada.');
    }

    // ───────────── Manutenções ─────────────

    public function manutencoes(Request $r): JsonResponse
    {
        $this->exigir('activos_manutencao_view', 'activos_view');
        $f = $r->validate(['ativo_imobilizado_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'string', 'max:20'], 'tipo' => ['nullable', 'string', 'max:30']]
            + self::REGRAS_LISTA);
        $q = RegistoManutencaoAtivo::query()->with('ativoImobilizado:id,codigo,descricao');
        foreach (['ativo_imobilizado_id', 'estado', 'tipo'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }
        self::filtrarPeriodo($q, $f);

        // paginado (ADR-064): «dados» é a lista da página; metadados.paginacao descreve a página
        return RespostaApi::paginado($q->orderByDesc('data')->orderByDesc('id')->paginate((int) ($f['por_pagina'] ?? 100), ['*'], 'pagina', (int) ($f['pagina'] ?? 1)), null, 'Manutenções.');
    }

    /** Paginação e período (data de/até) das listas de manutenções e abates. */
    private const REGRAS_LISTA = ['data_de' => ['nullable', 'date'], 'data_ate' => ['nullable', 'date', 'after_or_equal:data_de'],
        'pesquisa' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']];

    /** @param  Builder<Model>  $q */
    private static function filtrarPeriodo($q, array $f): void
    {
        $q->when($f['data_de'] ?? null, fn ($q, $v) => $q->whereDate('data', '>=', $v))->when($f['data_ate'] ?? null, fn ($q, $v) => $q->whereDate('data', '<=', $v))
            ->when($f['pesquisa'] ?? null, function ($q, $p) {
                $termo = '%'.str_replace(['%', '_'], ['\%', '\_'], $p).'%';
                $q->where(fn ($x) => $x->where('descricao', 'ilike', $termo)
                    ->orWhereHas('ativoImobilizado', fn ($a) => $a->withTrashed()->where(fn ($y) => $y->where('codigo', 'ilike', $termo)->orWhere('descricao', 'ilike', $termo))));
            });
    }

    public function registarManutencao(Request $r): JsonResponse
    {
        $this->exigir('activos_manut');
        $d = $r->validate(['ativo_imobilizado_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(RegistoManutencaoAtivo::TIPOS)], 'data' => ['required', 'date'],
            'descricao' => ['nullable', 'string', 'max:5000'], 'custo' => ['nullable', 'numeric', 'min:0']]);

        return RespostaApi::criado($this->manutencao->registar($d), 'Manutenção registada.');
    }

    public function executarManutencao(Request $r, int $manutencao): JsonResponse
    {
        $this->exigir('activos_manut');
        $d = $r->validate(['resolucao' => ['required', 'string', 'max:5000'], 'custo' => ['nullable', 'numeric', 'min:0']]);

        return RespostaApi::sucesso($this->manutencao->executar(RegistoManutencaoAtivo::query()->findOrFail($manutencao), $d['resolucao'], $d['custo'] ?? 0), 'Manutenção finalizada.');
    }

    public function eliminarManutencao(int $manutencao): JsonResponse
    {
        $this->exigir('activos_manut');
        RegistoManutencaoAtivo::query()->findOrFail($manutencao)->delete();

        return RespostaApi::sucesso(null, 'Manutenção removida.');
    }

    // ───────────── Aquisições pendentes ─────────────

    public function aquisicoesPendentes(): JsonResponse
    {
        $this->exigir('activos_pendentes_view', 'activos_view');

        return RespostaApi::sucesso($this->aquisicoes->pendentes() + ['ativos_sem_lancamento' => $this->aquisicoes->semLancamento()], 'Aquisições por inventariar.');
    }

    public function inventariar(Request $r, int $linha): JsonResponse
    {
        $this->exigir('activos_inventariar');
        $d = $r->validate(['itens' => ['required', 'array', 'min:1', 'max:1000'], 'itens.*.codigo' => ['nullable', 'string', 'max:50'],
            'itens.*.descricao' => ['required', 'string', 'max:2000'], 'itens.*.categoria_ativo_id' => ['required', 'integer'],
            'itens.*.valor_aquisicao' => ['required', 'numeric', 'min:0'], 'itens.*.vida_util' => ['nullable', 'integer', 'min:0'],
            'itens.*.vida_util_restante' => ['nullable', 'integer', 'min:0'], 'itens.*.amortizacao_acumulada_inicial' => ['nullable', 'numeric', 'min:0'],
            'itens.*.unidade_negocio_id' => ['nullable', 'integer'], 'itens.*.centro_custo_id' => ['nullable', 'integer']]);
        $res = $this->aquisicoes->inventariar($linha, $d['itens']);

        return RespostaApi::criado($res, count($res['ativos']).' item(ns) de imobilizado inventariado(s).');
    }

    public function ligar(Request $r, int $linha): JsonResponse
    {
        $this->exigir('activos_inventariar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer']]);

        return RespostaApi::sucesso($this->aquisicoes->ligar($linha, $d['ids']), 'Activos ligados ao lançamento.');
    }

    // ───────────── Abates e vendas ─────────────

    public function abatesLista(Request $r): JsonResponse
    {
        $this->exigir('activos_abates_view', 'activos_view');
        $f = $r->validate(['tipo' => ['nullable', 'string', 'max:20'], 'ativo_imobilizado_id' => ['nullable', 'integer']] + self::REGRAS_LISTA);
        $q = AbateVendaAtivo::query()->with(['ativoImobilizado:id,codigo,descricao,valor_aquisicao', 'terceiro:id,nome'])
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v))->when($f['ativo_imobilizado_id'] ?? null, fn ($q, $v) => $q->where('ativo_imobilizado_id', $v));
        self::filtrarPeriodo($q, $f);
        // paginado (ADR-064)
        $pagina = $q->orderByDesc('data')->orderByDesc('id')->paginate((int) ($f['por_pagina'] ?? 100), ['*'], 'pagina', (int) ($f['pagina'] ?? 1));
        $pagina->setCollection($pagina->getCollection()->map(fn ($x) => $x->toArray() + ['numero_documento' => $x->numeroDocumento()]));

        return RespostaApi::paginado($pagina, null, 'Abates e vendas.');
    }

    public function simularAbate(Request $r): JsonResponse
    {
        $this->exigir('activos_abater', 'activos_abates_view');

        return RespostaApi::sucesso($this->abates->simular($r->validate($this->regrasAbate())), 'Simulação do lançamento do abate.');
    }

    public function abater(Request $r): JsonResponse
    {
        $this->exigir('activos_abater');
        $res = $this->abates->registar($r->validate($this->regrasAbate() + ['contabilizar' => ['nullable', 'boolean']]));

        return RespostaApi::criado($res, 'Abate registado e activo abatido'.($res['numero_lan'] ? " (lançamento {$res['numero_lan']})." : '.'));
    }

    public function anularAbate(Request $r, int $abate): JsonResponse
    {
        $this->exigir('activos_abater');
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->abates->anular(AbateVendaAtivo::query()->findOrFail($abate), $d['motivo']), 'Abate anulado e activo reactivado.');
    }

    // ───────────── Regras ─────────────

    private function regrasFicha(bool $alteracao): array
    {
        $s = $alteracao ? 'sometimes' : 'nullable';

        return [
            'codigo' => [$s, 'nullable', 'string', 'max:50'], 'descricao' => [$alteracao ? 'sometimes' : 'required', 'string', 'max:2000'],
            'categoria_ativo_id' => [$alteracao ? 'sometimes' : 'required', 'integer'], 'estado' => [$s, 'nullable', Rule::in(AtivoImobilizado::ESTADOS)],
            'unidade_negocio_id' => [$s, 'nullable', 'integer'], 'centro_custo_id' => [$s, 'nullable', 'integer'], 'fornecedor_id' => [$s, 'nullable', 'integer'],
            'data_aquisicao' => [$s, 'date'], 'valor_aquisicao' => [$s, 'numeric', 'min:0'], 'valor_residual' => [$s, 'nullable', 'numeric', 'min:0'],
            'vida_util' => [$s, 'nullable', 'integer', 'min:0'], 'vida_util_restante' => [$s, 'nullable', 'integer', 'min:0'], 'quota_fixa' => [$s, 'nullable', 'numeric', 'min:0'],
            'amortizacao_acumulada_inicial' => [$s, 'nullable', 'numeric', 'min:0'], 'acumulado_fim_ano' => [$s, 'nullable', 'integer', 'between:1900,2100'],
        ];
    }

    private function regrasAbate(): array
    {
        return ['ativo_imobilizado_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(AbateVendaAtivo::TIPOS)], 'data' => ['required', 'date'],
            'descricao' => ['nullable', 'string', 'max:2000'], 'valor' => ['nullable', 'numeric', 'min:0'], 'terceiro_id' => ['nullable', 'integer'],
            'conta_terceiro' => ['nullable', 'string', 'max:20'], 'conta_ativo' => ['nullable', 'string', 'max:20']];
    }
}
