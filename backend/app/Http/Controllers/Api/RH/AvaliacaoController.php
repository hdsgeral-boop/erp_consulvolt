<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\AutoavaliacaoColaborador;
use App\Models\AvaliacaoDesempenhoRH;
use App\Models\BonificacaoAvaliacaoRH;
use App\Models\CicloAvaliacao360;
use App\Models\CriterioAvaliacaoRH;
use App\Models\FeedbackAvaliacao360;
use App\Services\RH\ServicoAvaliacao;
use App\Services\RH\ServicoAvaliacao360;
use App\Services\RH\ServicoPortalColaborador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** /api/rh/avaliacao — avaliação de desempenho, ciclos 360º, contestação, bonificação, autoavaliação e ascendente. */
final class AvaliacaoController extends Controller
{
    public function __construct(
        private readonly ServicoAvaliacao $avaliacao,
        private readonly ServicoAvaliacao360 $a360,
        private readonly ServicoPortalColaborador $portal,
    ) {}

    // ───────────── Itens ─────────────

    public function itens(): JsonResponse
    {
        $this->exigir('rh_avaliacao_view', 'rh_avaliacao_itens', 'rh_avaliacao_config_view');

        return RespostaApi::sucesso($this->avaliacao->itens(), 'Itens de avaliação.');
    }

    public function guardarItem(Request $r, ?int $item = null): JsonResponse
    {
        $this->exigir('rh_avaliacao_itens');
        $d = $r->validate(['ambito' => ['required', 'in:COMUM,ESPECIFICO'], 'colaborador_id' => ['required_if:ambito,ESPECIFICO', 'nullable', 'integer'],
            'tipo' => ['required', 'in:CRITERIO,OBJECTIVO'], 'nome' => ['required', 'string', 'max:255'], 'descricao' => ['nullable', 'string', 'max:2000'],
            'peso' => ['nullable', 'numeric', 'min:0', 'max:100'], 'ordem' => ['nullable', 'integer'], 'ativo' => ['nullable', 'boolean'],
            'natureza' => ['nullable', 'in:QUANTITATIVO,QUALITATIVO'], 'meta' => ['nullable', 'numeric', 'gt:0'], 'unidade' => ['nullable', 'string', 'max:50'],
            'sentido' => ['nullable', 'in:MAIOR,MENOR']]);
        $i = $item ? CriterioAvaliacaoRH::query()->findOrFail($item) : null;
        $res = $this->avaliacao->guardarItem($d, $i);

        return $i ? RespostaApi::sucesso($res, 'Item actualizado.') : RespostaApi::criado($res, 'Item criado.');
    }

    public function eliminarItem(int $item): JsonResponse
    {
        $this->exigir('rh_avaliacao_itens');
        $r = $this->avaliacao->eliminarItem(CriterioAvaliacaoRH::query()->findOrFail($item));

        return RespostaApi::sucesso(['resultado' => $r], $r === 'ELIMINADO' ? 'Item eliminado.' : 'O item já foi usado em avaliações: foi desactivado.');
    }

    // ───────────── Avaliações ─────────────

    public function avaliacoes(Request $r): JsonResponse
    {
        $this->exigir('rh_avaliacao_view', 'rh_avaliacao_ciclo_view');
        $f = $r->validate(['ano' => ['required', 'integer'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)]]);
        $c = CicloAvaliacao360::query()->where('ano', $f['ano'])->where('periodo', $f['periodo'])->first();

        return RespostaApi::sucesso(AvaliacaoDesempenhoRH::query()->where('ano', $f['ano'])->where('periodo', $f['periodo'])->orderBy('colaborador_id')->get()
            ->map(fn ($a) => $a->toArray() + ['fase' => ServicoAvaliacao360::fase($a, $c), 'nota_final' => ServicoAvaliacao360::notaFinal($a)]), 'Avaliações de desempenho.');
    }

    /** Gravar: o RH (rh_avaliacao_edit) ou, sem essa permissão, a chefia directa no ciclo aberto. */
    public function gravar(Request $r): JsonResponse
    {
        $this->exigir('rh_avaliacao_edit', 'rh_portal_usar');
        $d = $r->validate(['colaborador_id' => ['required', 'integer'], 'ano' => ['required', 'integer', 'between:2000,2100'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)],
            'criterios' => ['nullable', 'array'], 'criterios.*.chave' => ['required', 'string'], 'criterios.*.nota' => ['nullable', 'numeric', 'between:1,5'],
            'criterios.*.comentario' => ['nullable', 'string', 'max:2000'], 'objetivos' => ['nullable', 'array'], 'objetivos.*.chave' => ['required', 'string'],
            'objetivos.*.atingido' => ['nullable', 'numeric'], 'objetivos.*.nota_qual' => ['nullable', 'numeric', 'between:1,5'], 'objetivos.*.comentario' => ['nullable', 'string', 'max:2000'],
            'peso_objetivos' => ['nullable', 'numeric', 'between:0,100'], 'avaliador' => ['nullable', 'string', 'max:255'], 'data_avaliacao' => ['nullable', 'date'],
            'pontos_fortes' => ['nullable', 'string', 'max:5000'], 'pontos_melhorar' => ['nullable', 'string', 'max:5000'], 'plano_desenvolvimento' => ['nullable', 'string', 'max:5000'],
            'concluir' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->avaliacao->gravar($d, ! Gate::any(['rh_avaliacao_edit'])), ! empty($d['concluir']) ? 'Avaliação concluída.' : 'Avaliação gravada.');
    }

    public function reabrir(int $avaliacao): JsonResponse
    {
        $this->exigir('rh_avaliacao_edit');

        return RespostaApi::sucesso($this->avaliacao->reabrir(AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao)), 'Avaliação reaberta.');
    }

    public function eliminar(int $avaliacao): JsonResponse
    {
        $this->exigir('rh_avaliacao_edit');
        $this->avaliacao->eliminar(AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao));

        return RespostaApi::sucesso(null, 'Avaliação eliminada.');
    }

    public function conhecimento(Request $r, int $avaliacao): JsonResponse
    {
        $d = $r->validate(['comentario' => ['nullable', 'string', 'max:2000'], 'observacao' => ['nullable', 'string', 'max:1000']]);
        $a = AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao);
        $peloRh = $this->portal->colaboradorDe() !== $a->colaborador_id;
        $peloRh && $this->exigir('rh_avaliacao_edit');

        return RespostaApi::sucesso($this->a360->tomarConhecimento($a, $d['comentario'] ?? null, $peloRh, $d['observacao'] ?? null), 'Tomada de conhecimento registada.');
    }

    public function contestar(Request $r, int $avaliacao): JsonResponse
    {
        $d = $r->validate(['fundamentacao' => ['required', 'string', 'max:10000'], 'pontos' => ['nullable', 'array']]);

        return RespostaApi::sucesso($this->a360->contestar(AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao), $d['fundamentacao'], $d['pontos'] ?? []), 'Contestação registada.');
    }

    public function parecer(Request $r, int $avaliacao): JsonResponse
    {
        $this->exigir('rh_aval_parecer');
        $d = $r->validate(['texto' => ['required', 'string', 'max:10000']]);

        return RespostaApi::sucesso($this->a360->parecerRh(AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao), $d['texto']), 'Parecer registado.');
    }

    public function decidirContestacao(Request $r, int $avaliacao): JsonResponse
    {
        $d = $r->validate(['resultado' => ['required', 'in:MANTIDA,ALTERADA'], 'justificacao' => ['required', 'string', 'max:10000'], 'nota' => ['nullable', 'numeric', 'between:1,5']]);

        return RespostaApi::sucesso($this->a360->decidirContestacao(AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao), $d['resultado'], $d['justificacao'],
            isset($d['nota']) ? (float) $d['nota'] : null), 'Contestação decidida.');
    }

    public function resultado360(int $avaliacao): JsonResponse
    {
        $a = AvaliacaoDesempenhoRH::query()->findOrFail($avaliacao);
        if ($this->portal->colaboradorDe() !== $a->colaborador_id) {
            $this->exigir('rh_avaliacao_view', 'rh_avaliacao_ciclo_view');
        }
        $c = CicloAvaliacao360::query()->where('ano', $a->ano)->where('periodo', $a->periodo)->firstOrFail();

        return RespostaApi::sucesso($this->a360->resultado($c, $a->colaborador_id), 'Resultado 360º.');
    }

    // ───────────── Ciclos e bonificação ─────────────

    public function ciclos(): JsonResponse
    {
        $this->exigir('rh_avaliacao_config_view', 'rh_avaliacao_ciclo_view', 'rh_avaliacao_view');

        return RespostaApi::sucesso(CicloAvaliacao360::query()->orderByDesc('ano')->orderBy('periodo')->get(), 'Ciclos de avaliação.');
    }

    public function gravarCiclo(Request $r, ?int $ciclo = null): JsonResponse
    {
        $this->exigir('rh_aval_config');
        $d = $r->validate(['nome' => ['nullable', 'string', 'max:255'], 'ano' => [$ciclo ? 'nullable' : 'required', 'integer', 'between:2000,2100'],
            'periodo' => [$ciclo ? 'nullable' : 'required', Rule::in(ServicoAvaliacao::PERIODOS)], 'pesos' => ['nullable', 'array'], 'pesos.*' => ['numeric', 'min:0'],
            'minimo_anonimato' => ['nullable', 'integer'], 'max_pares' => ['nullable', 'integer', 'between:0,50'], 'prazos' => ['nullable', 'array'],
            'feedback' => ['nullable', 'array'], 'comunicado' => ['nullable', 'array'], 'bonificacao' => ['nullable', 'array'],
            // com regras aninhadas o validate() só devolve as subchaves listadas: todas têm de estar aqui
            'bonificacao.metodo' => ['nullable', Rule::in(ServicoAvaliacao360::METODOS)], 'bonificacao.tabela' => ['nullable', 'array'],
            'bonificacao.tabela.*' => ['numeric', 'min:0'], 'bonificacao.meses_base' => ['nullable', 'numeric', 'gt:0'], 'bonificacao.bolsa' => ['nullable', 'array'],
            'bonificacao.bolsa.montante' => ['nullable', 'numeric', 'min:0'], 'bonificacao.bolsa.nota_minima' => ['nullable', 'numeric', 'between:1,5'],
            'bonificacao.infotipo_salarial_id' => ['nullable', 'integer'], 'bonificacao.mes_lancamento' => ['nullable', 'string', 'max:7']]);
        $c = $ciclo ? CicloAvaliacao360::query()->findOrFail($ciclo) : null;
        $res = $this->a360->gravarCiclo($d, $c);

        return $c ? RespostaApi::sucesso($res, 'Ciclo actualizado.') : RespostaApi::criado($res, 'Ciclo criado.');
    }

    public function abrirCiclo(int $ciclo): JsonResponse
    {
        $this->exigir('rh_aval_abrir');

        return RespostaApi::sucesso($this->a360->abrirCiclo(CicloAvaliacao360::query()->findOrFail($ciclo)), 'Ciclo aberto.');
    }

    public function fecharCiclo(int $ciclo): JsonResponse
    {
        $this->exigir('rh_aval_abrir');

        return RespostaApi::sucesso($this->a360->fecharCiclo(CicloAvaliacao360::query()->findOrFail($ciclo)), 'Ciclo fechado.');
    }

    public function bonificacoes(int $ciclo): JsonResponse
    {
        $this->exigir('rh_avaliacao_ciclo_view', 'rh_aval_bonus_calcular', 'rh_aval_bonus_aprovar');

        return RespostaApi::sucesso(BonificacaoAvaliacaoRH::query()->where('ciclo_avaliacao_id', $ciclo)->orderBy('colaborador_id')->get(), 'Bonificações do ciclo.');
    }

    public function calcularBonificacoes(int $ciclo): JsonResponse
    {
        $this->exigir('rh_aval_bonus_calcular');

        return RespostaApi::sucesso($this->a360->calcularBonificacoes(CicloAvaliacao360::query()->findOrFail($ciclo)), 'Bonificações calculadas.');
    }

    public function aprovarBonificacoes(int $ciclo): JsonResponse
    {
        $this->exigir('rh_aval_bonus_aprovar');
        $n = $this->a360->aprovarBonificacoes(CicloAvaliacao360::query()->findOrFail($ciclo));

        return RespostaApi::sucesso(['aprovadas' => $n], "{$n} bonificação(ões) aprovada(s).");
    }

    public function lancarBonificacoes(int $ciclo): JsonResponse
    {
        $this->exigir('rh_aval_bonus_lancar');
        $n = $this->a360->lancarBonificacoes(CicloAvaliacao360::query()->findOrFail($ciclo));

        return RespostaApi::sucesso(['lancadas' => $n], "{$n} bonificação(ões) lançada(s) no processamento.");
    }

    public function anularBonificacao(int $bonificacao): JsonResponse
    {
        $this->exigir('rh_aval_bonus_aprovar');

        return RespostaApi::sucesso($this->a360->anularBonificacao(BonificacaoAvaliacaoRH::query()->findOrFail($bonificacao)), 'Bonificação anulada (volta a proposta).');
    }

    // ───────────── Acompanhamento ─────────────

    public function registarFeedback(Request $r): JsonResponse
    {
        $d = $r->validate(['ciclo_avaliacao_id' => ['required', 'integer'], 'colaborador_id' => ['required', 'integer'],
            'periodo_referencia' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2]|T[1-4]|S[12])$/'], 'data' => ['required', 'date'], 'objetivos' => ['nullable', 'array', 'max:100'],
            'positivos' => ['nullable', 'string', 'max:5000'], 'melhorar' => ['nullable', 'string', 'max:5000'], 'acordos' => ['nullable', 'string', 'max:5000']]);

        return RespostaApi::sucesso($this->a360->registarFeedback($d), 'Reunião de acompanhamento registada.');
    }

    public function confirmarFeedback(Request $r, int $feedback): JsonResponse
    {
        $d = $r->validate(['comentario' => ['nullable', 'string', 'max:2000']]);

        return RespostaApi::sucesso($this->a360->confirmarFeedback(FeedbackAvaliacao360::query()->findOrFail($feedback), $d['comentario'] ?? null), 'Reunião confirmada.');
    }

    // ───────────── O próprio (portal) ─────────────

    public function tarefas360(): JsonResponse
    {
        return RespostaApi::sucesso($this->a360->minhasTarefas(), 'Avaliações 360º por fazer.');
    }

    public function responder360(Request $r): JsonResponse
    {
        $d = $r->validate(['colaborador_avaliado_id' => ['required', 'integer'], 'notas' => ['required', 'array', 'max:200'], 'notas.*.chave' => ['required', 'string'],
            'notas.*.nota' => ['required', 'integer', 'between:1,5'], 'comentario' => ['nullable', 'string', 'max:2000']]);
        $this->a360->responder((int) $d['colaborador_avaliado_id'], $d['notas'], $d['comentario'] ?? null);

        return RespostaApi::sucesso(null, 'Resposta registada (anónima).');
    }

    public function confirmarComunicado(int $ciclo): JsonResponse
    {
        return RespostaApi::sucesso($this->a360->confirmarComunicado(CicloAvaliacao360::query()->findOrFail($ciclo)), 'Leitura do comunicado confirmada.');
    }

    public function autoavaliacao(Request $r): JsonResponse
    {
        $f = $r->validate(['ano' => ['required', 'integer'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)]]);
        $c = $this->portal->exigirColaborador();

        return RespostaApi::sucesso(['autoavaliacao' => AutoavaliacaoColaborador::query()->where('colaborador_id', $c->id)->where('ano', $f['ano'])->where('periodo', $f['periodo'])->first(),
            'criterios' => $this->avaliacao->itensDe($c->id, 'CRITERIO'), 'objetivos' => $this->avaliacao->itensDe($c->id, 'OBJECTIVO')], 'Autoavaliação.');
    }

    public function gravarAutoavaliacao(Request $r): JsonResponse
    {
        $d = $r->validate(['ano' => ['required', 'integer', 'between:2000,2100'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)], 'criterios' => ['nullable', 'array'],
            'objetivos' => ['nullable', 'array'], 'realizacoes' => ['nullable', 'string', 'max:10000'], 'dificuldades' => ['nullable', 'string', 'max:10000'],
            'formacao' => ['nullable', 'string', 'max:2000'], 'submeter' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->avaliacao->gravarAutoavaliacao($d), ! empty($d['submeter']) ? 'Autoavaliação submetida.' : 'Autoavaliação gravada.');
    }

    public function responderAscendente(Request $r): JsonResponse
    {
        $d = $r->validate(['ano' => ['required', 'integer'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)], 'respostas' => ['required', 'array', 'max:200'],
            'respostas.*.chave' => ['required', 'string'], 'respostas.*.nota' => ['required', 'integer', 'between:1,5'], 'comentario' => ['nullable', 'string', 'max:2000']]);
        $this->a360->responderAscendente((int) $d['ano'], $d['periodo'], $d['respostas'], $d['comentario'] ?? null);

        return RespostaApi::sucesso(null, 'Avaliação da chefia registada (anónima).');
    }

    public function resultadosAscendente(Request $r, int $colaborador): JsonResponse
    {
        $f = $r->validate(['ano' => ['required', 'integer'], 'periodo' => ['required', Rule::in(ServicoAvaliacao::PERIODOS)]]);

        return RespostaApi::sucesso($this->a360->resultadosAscendente($colaborador, (int) $f['ano'], $f['periodo']), 'Avaliação ascendente.');
    }
}
