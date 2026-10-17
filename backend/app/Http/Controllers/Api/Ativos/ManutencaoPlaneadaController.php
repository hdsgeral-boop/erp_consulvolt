<?php

namespace App\Http\Controllers\Api\Ativos;

use App\Http\Controllers\Controller;
use App\Models\Armazem;
use App\Models\ItemOrdemManutencao;
use App\Models\OrdemManutencaoAtivo;
use App\Models\PlanoManutencaoAtivo;
use App\Models\Produto;
use App\Models\RequisicaoManutencao;
use App\Services\Ativos\ServicoOrdensManutencao;
use App\Services\Ativos\ServicoPlanosManutencao;
use App\Services\Ativos\ServicoRequisicoesManutencao;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * /api/ativos/manutencao — planos, ordens de manutenção, necessidades e requisições ao armazém (Fase F, Versão Actual;
 * js/activos_manutencao.js). Permissões (ecrã activos_manutencao): ver = activos_manutencao_view; planos e ordens =
 * activos_manut_gerir; requisitar ao armazém / pedir compras e entregar ou cancelar requisições = activos_manut_requisitar.
 * A permissão é verificada sempre antes da validação (OWASP A01); os registos de outra empresa dão 404 (EscopoEmpresa).
 */
final class ManutencaoPlaneadaController extends Controller
{
    private const VER = ['activos_manutencao_view', 'activos_manut_gerir', 'activos_manut_requisitar'];

    private const GERIR = 'activos_manut_gerir';

    private const REQUISITAR = 'activos_manut_requisitar';

    public function __construct(
        private readonly ServicoPlanosManutencao $planos,
        private readonly ServicoOrdensManutencao $ordens,
        private readonly ServicoRequisicoesManutencao $requisicoes,
    ) {}

    // ───────────── Apoio aos formulários ─────────────

    /** Resumo do ecrã: contagens das ordens e planos vencidos/a vencer (caixa de aviso dos Planos, UI :110, :150-153). */
    public function resumo(): JsonResponse
    {
        $this->exigir(...self::VER);
        $planos = collect($this->planos->listar());
        $porVencer = $planos->filter(fn ($p) => in_array($p['situacao']['chave'], ['VENCIDO', 'A_VENCER'], true));

        return RespostaApi::sucesso(['ordens' => $this->ordens->listar(['por_pagina' => 1])['contagens'], 'planos_por_vencer' => $porVencer->count(),
            'planos_por_vencer_nomes' => $porVencer->pluck('nome')->take(5)->values()], 'Resumo da manutenção.');
    }

    /** Artigos para as necessidades (código ou nome), sem depender das permissões do Armazém. */
    public function artigos(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['texto' => ['nullable', 'string', 'max:100'], 'tipo' => ['nullable', Rule::in(['MATERIAL', 'SERVICO'])]]);
        $termo = isset($f['texto']) && $f['texto'] !== '' ? '%'.str_replace(['%', '_'], ['\%', '\_'], $f['texto']).'%' : null;
        $q = Produto::query()->where(fn ($q) => $q->whereNull('bloqueado')->orWhere('bloqueado', false))
            ->when($termo, fn ($q, $t) => $q->where(fn ($x) => $x->where('codigo', 'ilike', $t)->orWhere('nome', 'ilike', $t)))
            // serviços: só artigos que não movimentam stock (opcoesProduto, UI :73-75)
            ->when(($f['tipo'] ?? null) === 'SERVICO', fn ($q) => $q->where(fn ($x) => $x->whereNull('movimenta_stock')->orWhere('movimenta_stock', false)));

        return RespostaApi::sucesso($q->orderBy('codigo')->limit(50)->get(['id', 'codigo', 'nome', 'movimenta_stock', 'quantidade_stock']), 'Artigos.');
    }

    public function armazens(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(Armazem::query()->ordenarPorNome()->get(['id', 'codigo', 'nome', 'predefinido']), 'Armazéns.');
    }

    // ───────────── Planos ─────────────

    public function planos(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->planos->listar(), 'Planos de manutenção.');
    }

    public function criarPlano(Request $r): JsonResponse
    {
        $this->exigir(self::GERIR);

        return RespostaApi::criado($this->planos->guardar($r->validate(self::regrasPlano())), 'Plano gravado.');
    }

    public function actualizarPlano(Request $r, int $plano): JsonResponse
    {
        $this->exigir(self::GERIR);
        $p = PlanoManutencaoAtivo::query()->findOrFail($plano);

        return RespostaApi::sucesso($this->planos->guardar($r->validate(self::regrasPlano()), $p), 'Plano gravado.');
    }

    public function alternarPlano(int $plano): JsonResponse
    {
        $this->exigir(self::GERIR);
        $p = $this->planos->alternar(PlanoManutencaoAtivo::query()->findOrFail($plano));

        return RespostaApi::sucesso($p, $p->ativo === false ? 'Plano desactivado: deixa de gerar ordens.' : 'Plano reactivado.');
    }

    public function eliminarPlano(int $plano): JsonResponse
    {
        $this->exigir(self::GERIR);
        $this->planos->eliminar(PlanoManutencaoAtivo::query()->findOrFail($plano));

        return RespostaApi::sucesso(null, 'Plano eliminado.');
    }

    /** «Ordem» do plano: abre já as ordens dos activos do plano. */
    public function ordensDoPlano(int $plano): JsonResponse
    {
        $this->exigir(self::GERIR);
        $res = $this->ordens->gerarDosPlanos(PlanoManutencaoAtivo::query()->findOrFail($plano));

        return RespostaApi::criado($res, count($res['criadas']).' ordem(ns) aberta(s).');
    }

    /** «Gerar ordens dos planos vencidos». */
    public function gerarOrdens(): JsonResponse
    {
        $this->exigir(self::GERIR);
        $res = $this->ordens->gerarDosPlanos();

        return RespostaApi::sucesso($res, $res['criadas'] ? count($res['criadas']).' ordem(ns) criada(s).'
            : ($res['saltadas'] ? 'Nada a gerar. '.$res['saltadas'][0] : 'Não há planos vencidos nem a vencer.'));
    }

    // ───────────── Ordens ─────────────

    public function ordens(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['filtro' => ['nullable', Rule::in(['ABERTAS', 'TODAS', ...OrdemManutencaoAtivo::ESTADOS])], 'ativo_imobilizado_id' => ['nullable', 'integer'],
            'pesquisa' => ['nullable', 'string', 'max:100'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado($this->ordens->listar($f)['pagina'], null, 'Ordens de manutenção.');
    }

    public function ordem(int $ordem): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->ordens->detalhe(OrdemManutencaoAtivo::query()->findOrFail($ordem)), 'Ordem de manutenção.');
    }

    /** Ordem avulsa (avaria ou trabalho pontual, formularioOrdem UI :380-422). */
    public function criarOrdem(Request $r): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['ativo_imobilizado_id' => ['required', 'integer'], 'tipo' => ['required', Rule::in(PlanoManutencaoAtivo::TIPOS)],
            'prioridade' => ['nullable', Rule::in(OrdemManutencaoAtivo::PRIORIDADES)], 'data_prevista' => ['required', 'date'],
            'descricao' => ['required', 'string', 'max:5000'], 'responsavel' => ['nullable', 'string', 'max:150'],
            // r3_activos: cada tarefa é um texto (obrigatória) ou {texto, obrigatoria}
            'tarefas' => ['nullable', 'array', 'max:200'], 'tarefas.*' => ['nullable', fn ($a, $v, $falha) => is_string($v) || is_array($v) || $v === null ? null : $falha('Tarefa inválida.')],
            'tarefas.*.texto' => ['sometimes', 'nullable', 'string', 'max:500'], 'tarefas.*.obrigatoria' => ['sometimes', 'nullable', 'boolean']] + self::regrasItens('itens'));
        foreach ($d['tarefas'] ?? [] as $t) {
            if (is_string($t) && mb_strlen($t) > 500) {
                throw ValidationException::withMessages(['tarefas' => 'Cada tarefa tem no máximo 500 caracteres.']);
            }
        }
        unset($d['plano_manutencao_id']);
        $o = $this->ordens->criar($d);

        return RespostaApi::criado($o, "Ordem {$o->numero} aberta.");
    }

    public function iniciar(int $ordem): JsonResponse
    {
        $this->exigir(self::GERIR);

        return RespostaApi::sucesso($this->ordens->iniciar(OrdemManutencaoAtivo::query()->findOrFail($ordem)), 'Ordem iniciada.');
    }

    public function tarefas(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['feitas' => ['required', 'array', 'max:200'], 'feitas.*' => ['boolean']]);

        return RespostaApi::sucesso($this->ordens->marcarTarefas(OrdemManutencaoAtivo::query()->findOrFail($ordem), array_values($d['feitas'])), 'Tarefas gravadas.');
    }

    public function acrescentarItem(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['tipo' => ['required', Rule::in(['MATERIAL', 'SERVICO'])], 'produto_id' => ['required', 'integer'], 'quantidade' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'descricao' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::criado($this->ordens->acrescentarItem(OrdemManutencaoAtivo::query()->findOrFail($ordem), $d), 'Necessidade acrescentada.');
    }

    public function retirarItem(int $item): JsonResponse
    {
        $this->exigir(self::GERIR);
        $this->ordens->retirarItem(ItemOrdemManutencao::query()->findOrFail($item));

        return RespostaApi::sucesso(null, 'Necessidade retirada.');
    }

    public function concluir(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['resolucao' => ['required', 'string', 'max:5000'], 'data_fim' => ['nullable', 'date'], 'horas' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'custo_mao_obra' => ['nullable', 'numeric', 'min:0'], 'custo_servicos' => ['nullable', 'numeric', 'min:0'], 'leitura_contador' => ['nullable', 'numeric', 'min:0'],
            'tarefas' => ['nullable', 'array', 'max:200'], 'tarefas.*' => ['boolean'], 'forcar' => ['nullable', 'boolean']]);
        $o = $this->ordens->concluir(OrdemManutencaoAtivo::query()->findOrFail($ordem), $d);

        return RespostaApi::sucesso($o->toArray() + ['custo_total' => $this->ordens->custoTotal($o)], "Ordem concluída. Custo total {$this->ordens->custoTotal($o)} Kz.");
    }

    public function cancelar(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::GERIR);
        $d = $r->validate(['motivo' => ['required', 'string', 'max:1000']]);
        $res = $this->ordens->cancelar(OrdemManutencaoAtivo::query()->findOrFail($ordem), $d['motivo']);

        return RespostaApi::sucesso($res, $res['pedidos_emitidos']
            ? "Ordem cancelada. Atenção: havia {$res['pedidos_emitidos']} necessidade(s) já pedidas (requisição ou compra) que não foram anuladas."
            : 'Ordem cancelada.');
    }

    // ───────────── Requisição (necessidades → armazém / compras) ─────────────

    public function planoRequisicao(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::REQUISITAR);
        $d = $r->validate(['armazem_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->ordens->planoDeRequisicao(OrdemManutencaoAtivo::query()->findOrFail($ordem), isset($d['armazem_id']) ? (int) $d['armazem_id'] : null),
            'Pré-visualização da requisição.');
    }

    public function requisitar(Request $r, int $ordem): JsonResponse
    {
        $this->exigir(self::REQUISITAR);
        $d = $r->validate(['armazem_id' => ['nullable', 'integer'], 'requerente' => ['nullable', 'string', 'max:150']]);
        if (! empty($d['armazem_id'])) {
            Armazem::query()->findOrFail((int) $d['armazem_id']);
        }
        $res = $this->ordens->requisitar(OrdemManutencaoAtivo::query()->findOrFail($ordem), isset($d['armazem_id']) ? (int) $d['armazem_id'] : null, $d['requerente'] ?? null);
        $partes = array_filter([$res['requisicao_numero'] ? "requisição {$res['requisicao_numero']} ({$res['armazem']} linha(s))" : null,
            $res['pedido_compra_id'] ? "pedido de compra {$res['pedido_compra_numero']} com {$res['compra']} linha(s), já em Compras › Pedidos" : null]);

        return RespostaApi::criado($res, 'Emitido: '.implode(' e ', $partes).'.');
    }

    // ───────────── Requisições ao armazém ─────────────

    public function listarRequisicoes(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['estado' => ['nullable', Rule::in(['PENDENTE', 'PARCIAL', 'SATISFEITA', 'CANCELADA'])], 'ordem_manutencao_id' => ['nullable', 'integer'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado($this->requisicoes->listar($f), null, 'Requisições ao armazém.');
    }

    public function requisicao(Request $r, int $requisicao): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['armazem_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->requisicoes->detalhe(RequisicaoManutencao::query()->findOrFail($requisicao), isset($d['armazem_id']) ? (int) $d['armazem_id'] : null),
            'Requisição ao armazém.');
    }

    public function entregar(Request $r, int $requisicao): JsonResponse
    {
        $this->exigir(self::REQUISITAR);
        $d = $r->validate(['armazem_id' => ['nullable', 'integer'], 'entregas' => ['nullable', 'array', 'max:1000'], 'entregas.*' => ['numeric', 'min:0']]);
        $res = $this->requisicoes->entregar(RequisicaoManutencao::query()->findOrFail($requisicao), $d['entregas'] ?? [], isset($d['armazem_id']) ? (int) $d['armazem_id'] : null);

        return RespostaApi::sucesso($res, "Material entregue ({$res['total']} Kz).".($res['completa'] ? '' : ' Ficaram linhas por entregar.'));
    }

    public function cancelarRequisicao(Request $r, int $requisicao): JsonResponse
    {
        $this->exigir(self::REQUISITAR);
        $d = $r->validate(['motivo' => ['required', 'string', 'max:1000']]);

        return RespostaApi::sucesso($this->requisicoes->cancelar(RequisicaoManutencao::query()->findOrFail($requisicao), $d['motivo']), 'Requisição cancelada.');
    }

    // ───────────── Regras ─────────────

    private static function regrasPlano(): array
    {
        return ['codigo' => ['nullable', 'string', 'max:50'], 'nome' => ['required', 'string', 'max:255'], 'ativo_imobilizado_id' => ['nullable', 'integer'],
            'categoria_ativo_id' => ['nullable', 'integer'], 'tipo' => ['required', Rule::in(PlanoManutencaoAtivo::TIPOS)],
            'periodicidade' => ['required', 'array'], 'periodicidade.modo' => ['required', Rule::in(['TEMPO', 'CONTADOR'])],
            'periodicidade.intervalo' => ['required', 'integer', 'min:1', 'max:100000'],
            'periodicidade.unidade' => ['required', Rule::in([...PlanoManutencaoAtivo::UNIDADES_TEMPO, ...PlanoManutencaoAtivo::UNIDADES_CONTADOR])],
            'proxima_data' => ['nullable', 'date'], 'proximo_contador' => ['nullable', 'numeric', 'min:0'], 'contador_atual' => ['nullable', 'numeric', 'min:0'],
            'antecedencia_dias' => ['nullable', 'integer', 'min:0', 'max:3650'], 'duracao_horas' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'responsavel' => ['nullable', 'string', 'max:150'], 'instrucoes' => ['nullable', 'string', 'max:5000'], 'ativo' => ['nullable', 'boolean'],
            'tarefas' => ['nullable', 'array', 'max:200'], 'tarefas.*' => ['nullable', 'string', 'max:500']] + self::regrasItens('itens');
    }

    private static function regrasItens(string $campo): array
    {
        return [$campo => ['nullable', 'array', 'max:200'], "{$campo}.*.tipo" => ['nullable', Rule::in(['MATERIAL', 'SERVICO'])], "{$campo}.*.produto_id" => ['nullable', 'integer'],
            "{$campo}.*.descricao" => ['nullable', 'string', 'max:2000'], "{$campo}.*.quantidade" => ['nullable', 'numeric', 'gt:0', 'max:9999999']];
    }
}
