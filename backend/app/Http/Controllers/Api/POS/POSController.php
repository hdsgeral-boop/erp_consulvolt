<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\Produto;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\POS\ServicoConfigPOS;
use App\Services\POS\ServicoContabilizacaoPOS;
use App\Services\POS\ServicoSessoesPOS;
use App\Services\POS\ServicoTerminaisPOS;
use App\Services\POS\ServicoVendasPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/pos — terminais, definições, sessões (abertura, X, Z), vendas, integração contabilística e desvios. */
final class POSController extends Controller
{
    public function __construct(
        private readonly ServicoTerminaisPOS $terminais,
        private readonly ServicoConfigPOS $config,
        private readonly ServicoSessoesPOS $sessoes,
        private readonly ServicoVendasPOS $vendas,
        private readonly ServicoContabilizacaoPOS $contabilizacao,
    ) {}

    // ───────────── Terminais ─────────────

    public function terminais(): JsonResponse
    {
        $this->exigir('pos_view', 'pos_terminais_view', 'pos_terminais_gerir', 'pos_venda');
        $abertas = SessaoPOS::query()->where('estado', 'ABERTA')->get(['id', 'terminal_pos_id', 'codigo_sessao', 'nome_operador', 'aberto_em'])->keyBy('terminal_pos_id');

        return RespostaApi::sucesso(TerminalPOS::query()->orderBy('codigo')->get()->map(fn ($t) => $t->toArray() + ['sessao_aberta' => $abertas[$t->id] ?? null]), 'Terminais POS.');
    }

    public function terminal(int $terminal): JsonResponse
    {
        $this->exigir('pos_view', 'pos_terminais_view', 'pos_terminais_gerir', 'pos_venda');
        $t = TerminalPOS::query()->findOrFail($terminal);

        return RespostaApi::sucesso($t->toArray() + ['sessao_aberta' => $this->terminais->sessaoAberta($t)], 'Terminal POS.');
    }

    public function guardarTerminal(Request $r, ?int $terminal = null): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $d = $r->validate([
            'codigo' => [$terminal ? 'nullable' : 'required', 'string', 'max:10'], 'nome' => [$terminal ? 'nullable' : 'required', 'string', 'max:255'],
            'tipo' => ['nullable', Rule::in(ServicoTerminaisPOS::TIPOS)], 'armazem_id' => ['nullable', 'integer'], 'cliente_padrao_id' => ['nullable', 'integer'],
            'fundo_maneio_padrao' => ['nullable', 'numeric', 'min:0'], 'unidade_negocio_id' => ['nullable', 'integer'], 'centro_custo_id' => ['nullable', 'integer'],
            'meios_pagamento' => ['sometimes', 'array', 'min:1'], 'meios_pagamento.*' => ['array'],
            'hotel_hora_entrada' => ['nullable', 'date_format:H:i'], 'hotel_hora_saida' => ['nullable', 'date_format:H:i'], 'hotel_tolerancia_atraso_min' => ['nullable', 'integer', 'min:0'],
            'hotel_bloco_horas' => ['nullable', 'boolean'], 'hotel_bloco_horas_de' => ['nullable', 'date_format:H:i'], 'hotel_bloco_horas_ate' => ['nullable', 'date_format:H:i'],
        ]);
        if ($r->has('meios_pagamento')) {
            $d['meios_pagamento'] = $r->input('meios_pagamento');   // os subcampos são validados no serviço
        }
        $t = $terminal ? TerminalPOS::query()->findOrFail($terminal) : null;
        $res = $this->terminais->guardar($d, $t);

        return $t ? RespostaApi::sucesso($res, 'Terminal actualizado.') : RespostaApi::criado($res, 'Terminal criado.');
    }

    public function copiarMeios(Request $r, int $terminal): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $d = $r->validate(['terminal_origem_id' => ['required', 'integer'], 'modo' => ['required', 'in:SUBSTITUIR,ACRESCENTAR'], 'empresa_origem_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->terminais->copiarMeios(TerminalPOS::query()->findOrFail($terminal), (int) $d['terminal_origem_id'], $d['modo'],
            isset($d['empresa_origem_id']) ? (int) $d['empresa_origem_id'] : null), 'Meios de pagamento copiados.');
    }

    public function ativarTerminal(Request $r, int $terminal): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $d = $r->validate(['ativo' => ['required', 'boolean']]);

        return RespostaApi::sucesso($this->terminais->definirAtivo(TerminalPOS::query()->findOrFail($terminal), (bool) $d['ativo']), $d['ativo'] ? 'Terminal activado.' : 'Terminal desactivado.');
    }

    public function eliminarTerminal(int $terminal): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $this->terminais->eliminar(TerminalPOS::query()->findOrFail($terminal));

        return RespostaApi::sucesso(null, 'Terminal eliminado.');
    }

    // ───────────── Definições ─────────────

    public function definicoes(): JsonResponse
    {
        $this->exigir('pos_view', 'pos_terminais_view', 'pos_terminais_gerir', 'pos_desvios_view', 'pos_desvio_deliberar');

        return RespostaApi::sucesso($this->config->obter(), 'Definições do POS.');
    }

    public function guardarDefinicoes(Request $r): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $d = $r->validate(['conta_sobra' => ['nullable', 'string', 'max:20'], 'conta_quebra' => ['nullable', 'string', 'max:20'], 'conta_operador' => ['nullable', 'string', 'max:20'],
            'tolerancia_desvio' => ['nullable', 'numeric', 'min:0'], 'codigo_diario' => ['nullable', 'string', 'max:10']]);

        return RespostaApi::sucesso($this->config->guardar($d), 'Definições do POS gravadas.');
    }

    // ───────────── Sessões ─────────────

    public function sessoes(Request $r): JsonResponse
    {
        $this->exigir('pos_view', 'pos_relatorios_view', 'pos_integracao_view', 'pos_desvios_view', 'pos_prestacao_view', 'pos_venda');
        $f = $r->validate(['terminal_pos_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'in:ABERTA,FECHADA'],
            'estado_contabilizacao' => ['nullable', 'string', 'max:20'], 'estado_desvio' => ['nullable', 'string', 'max:20'], 'estado_liquidacao' => ['nullable', 'string', 'max:20'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);
        $q = SessaoPOS::query();
        foreach (['terminal_pos_id', 'estado', 'estado_contabilizacao', 'estado_desvio', 'estado_liquidacao'] as $c) {
            $q->when($f[$c] ?? null, fn ($q, $v) => $q->where($c, $v));
        }

        return RespostaApi::paginado($q->orderByDesc('aberto_em')->orderByDesc('id')->paginate($f['por_pagina'] ?? 50, ['*'], 'pagina', $f['pagina'] ?? 1), null, 'Sessões POS.');
    }

    public function sessao(int $sessao): JsonResponse
    {
        $this->exigir('pos_view', 'pos_relatorios_view', 'pos_integracao_view', 'pos_desvios_view', 'pos_prestacao_view', 'pos_venda');
        $s = SessaoPOS::query()->findOrFail($sessao);

        return RespostaApi::sucesso($s->toArray() + ['vendas' => Venda::query()->where('sessao_pos_id', $s->id)->orderBy('id')
            ->get(['id', 'numero_documento', 'data_emissao', 'cliente_id', 'total_bruto', 'estado', 'pos_pagamentos', 'pos_troco', 'pos_operador', 'contabilizado'])], 'Sessão POS.');
    }

    public function abrirSessao(Request $r, int $terminal): JsonResponse
    {
        $this->exigir('pos_venda');
        $d = $r->validate(['fundo_maneio' => ['nullable', 'numeric', 'min:0']]);
        $res = $this->sessoes->abrir(TerminalPOS::query()->findOrFail($terminal), isset($d['fundo_maneio']) ? (string) $d['fundo_maneio'] : null);

        return RespostaApi::criado($res['sessao'], $res['aviso'] ?? 'Sessão aberta.');
    }

    public function relatorioX(int $sessao): JsonResponse
    {
        $this->exigir('pos_venda', 'pos_fecho');

        return RespostaApi::sucesso($this->sessoes->relatorioX(SessaoPOS::query()->findOrFail($sessao)), 'Relatório X.');
    }

    public function fecharSessao(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('pos_fecho');
        $d = $r->validate(['contagens' => ['nullable', 'array'], 'contagens.*' => ['integer', 'min:0'], 'numerario_contado' => ['nullable', 'numeric', 'min:0'],
            'fechos_tpa' => ['nullable', 'array'], 'fechos_tpa.*.meio_id' => ['required', 'string'], 'fechos_tpa.*.valor_talao' => ['required', 'numeric', 'min:0'],
            'fechos_tpa.*.operacoes_talao' => ['nullable', 'integer', 'min:0'], 'fechos_tpa.*.referencia_lote' => ['nullable', 'string', 'max:60'],
            'justificacao' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::sucesso($this->sessoes->fechar(SessaoPOS::query()->findOrFail($sessao), $d), 'Sessão fechada (Z).');
    }

    // ───────────── Vendas ─────────────

    public function vender(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('pos_venda');
        $d = $r->validate([
            'cliente_id' => ['nullable', 'integer'], 'observacoes' => ['nullable', 'string', 'max:2000'], 'percentagem_desconto' => ['nullable', 'numeric', 'between:0,100'],
            'linhas' => ['required', 'array', 'min:1', 'max:1000'], 'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'linhas.*.descricao' => ['nullable', 'string', 'max:500'],
            'pagamentos' => ['required', 'array', 'min:1', 'max:50'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'], 'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'],
            'pagamentos.*.referencia' => ['nullable', 'string', 'max:100'],
        ]);
        // desconto e alteração de preço (updatePOSDiscount / updatePOSCartPrice, permissoes.js:229)
        $precos = Produto::query()->whereIn('id', array_column($d['linhas'], 'produto_id'))->pluck('preco_unitario', 'id');
        $alterado = (float) ($d['percentagem_desconto'] ?? 0) > 0 || collect($d['linhas'])->contains(fn ($l) => isset($l['preco_unitario'])
            && bccomp(number_format((float) $l['preco_unitario'], 2, '.', ''), number_format((float) ($precos[$l['produto_id']] ?? 0), 2, '.', ''), 2) !== 0);
        if ($alterado) {
            $this->exigir('pos_desconto');
        }
        $v = $this->vendas->vender(SessaoPOS::query()->findOrFail($sessao), $d);

        return RespostaApi::criado($v->load('itensVenda'), "Venda {$v->numero_documento} registada. Troco: ".number_format((float) $v->pos_troco, 2, ',', ' ').' Kz.');
    }

    // ───────────── Integração e desvios ─────────────

    public function contabilizar(int $sessao): JsonResponse
    {
        $this->exigir('pos_integrar');

        return RespostaApi::sucesso($this->contabilizacao->contabilizar(SessaoPOS::query()->findOrFail($sessao)), 'Sessão integrada na contabilidade.');
    }

    public function descontabilizar(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('pos_descontabilizar');
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->contabilizacao->descontabilizar(SessaoPOS::query()->findOrFail($sessao), $d['motivo']), 'Integração estornada.');
    }

    public function deliberar(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('pos_desvio_deliberar');
        $d = $r->validate(['decisao' => ['required', Rule::in(ServicoContabilizacaoPOS::DECISOES)], 'nota' => ['nullable', 'string', 'max:1000']]);

        return RespostaApi::sucesso($this->contabilizacao->deliberar(SessaoPOS::query()->findOrFail($sessao), $d['decisao'], $d['nota'] ?? null), 'Desvio deliberado.');
    }

    public function anularDeliberacao(Request $r, int $sessao): JsonResponse
    {
        $this->exigir('pos_desvio_deliberar');
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->contabilizacao->anularDeliberacao(SessaoPOS::query()->findOrFail($sessao), $d['motivo']), 'Deliberação anulada.');
    }
}
