<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\EspacoComum;
use App\Models\Fracao;
use App\Models\MovimentoCaucaoReserva;
use App\Models\ReservaEspaco;
use App\Models\Terceiro;
use App\Models\VistoriaReserva;
use App\Services\Imobiliario\CatalogoParqueamento;
use App\Services\Imobiliario\ServicoCaucaoReserva;
use App\Services\Imobiliario\ServicoReservasEspaco;
use App\Services\Imobiliario\ServicoReservasFinanceiro;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reservas das áreas comuns e a sua parte financeira (Onda 3, ecrã imo_areas). Ver: imo_areas_view. Marcar, alterar e apagar: imo_reserva_marcar;
 * aprovar e recusar: imo_reserva_aprovar; autorizar quem deve rendas: imo_reserva_devedor; facturar: imo_reserva_facturar; registar recebimentos:
 * imo_reserva_receber; vistorias: imo_reserva_vistoria; caução: imo_reserva_caucao; cancelar: imo_reserva_cancelar (também recusadas no servidor, antes da validação).
 *  GET  /imobiliario/reservas/verificar|contas|avisos       verificação em directo da marcação, resumo financeiro, avisos
 *  GET|POST /imobiliario/reservas, GET|PUT|DELETE /imobiliario/reservas/{id}
 *  POST /imobiliario/reservas/{id}/estado|cancelar|facturar|receber|vistoria|caucao;  GET /imobiliario/reservas/{id}/previsao-cancelamento|caucao
 *  POST /imobiliario/reservas/caucao/{movimento}/anular;  POST /imobiliario/reservas/caducar
 */
final class ReservasController extends Controller
{
    public function __construct(
        private readonly ServicoReservasEspaco $reservas,
        private readonly ServicoReservasFinanceiro $financeiro,
        private readonly ServicoCaucaoReserva $caucao,
    ) {}

    public function verificar(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $d = $r->validate(['espaco_id' => ['required', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'hora_inicio' => ['nullable', 'string', 'max:5'], 'hora_fim' => ['nullable', 'string', 'max:5'], 'excepto' => ['nullable', 'integer', 'min:1'], 'pessoas' => ['nullable', 'integer', 'min:0', 'max:100000']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->reservas->elegibilidade((int) $d['espaco_id'], $d['fracao_id'] ?? null, $d['data'] ?? null, $d['hora_inicio'] ?? null,
            $d['hora_fim'] ?? null, $d['excepto'] ?? null, $d['pessoas'] ?? null)), 'Verificação da marcação.');
    }

    public function contas(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1'], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->financeiro->contas($f)), 'Contas das reservas.');
    }

    public function avisos(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::sucesso($this->financeiro->avisos($f['imovel_id'] ?? null), 'Avisos financeiros das reservas.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $f = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1'], 'espaco_id' => ['nullable', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'],
            'estado' => ['nullable', Rule::in(ReservaEspaco::ESTADOS)], 'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'vivas' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->reservas->listar($f)), 'Reservas.');
    }

    /** @return array<string, mixed> */
    private function regras(): array
    {
        return ['espaco_id' => ['nullable', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'], 'data' => ['nullable', 'date_format:Y-m-d'], 'hora_inicio' => ['nullable', 'string', 'max:5'],
            'hora_fim' => ['nullable', 'string', 'max:5'], 'pessoas' => ['nullable', 'integer', 'min:0', 'max:100000'], 'motivo' => ['nullable', 'string', 'max:300'], 'contacto' => ['nullable', 'string', 'max:100'],
            'autorizacao_motivo' => ['nullable', 'string', 'max:300'], 'notas' => ['nullable', 'string', 'max:5000']];
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('imo_reserva_marcar');
        $d = $r->validate($this->regras());
        $res = $this->reservas->gravar($d, null, Gate::allows('imo_reserva_devedor'));

        return RespostaApi::criado(new ImobiliarioResource($this->ficha($res)), 'Reserva marcada.');
    }

    public function update(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_marcar');
        $d = $r->validate($this->regras());
        $res = $this->reservas->gravar($d, ReservaEspaco::query()->findOrFail($reserva), Gate::allows('imo_reserva_devedor'));

        return RespostaApi::sucesso(new ImobiliarioResource($this->ficha($res)), 'Reserva actualizada.');
    }

    public function show(int $reserva): JsonResponse
    {
        $this->exigir('imo_areas_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->ficha(ReservaEspaco::query()->findOrFail($reserva))), 'Reserva.');
    }

    public function destroy(int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_marcar');
        $this->reservas->eliminar(ReservaEspaco::query()->findOrFail($reserva));

        return RespostaApi::sucesso(null, 'Reserva apagada.');
    }

    /** Aprovar e recusar (imo_reserva_aprovar), realizada e «não compareceu» (imo_reserva_marcar) e cancelar (imo_reserva_cancelar, com a escala de reembolso). */
    public function estado(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_aprovar', 'imo_reserva_marcar', 'imo_reserva_cancelar');
        $d = $r->validate(['estado' => ['required', Rule::in(ReservaEspaco::ESTADOS)], 'motivo' => ['nullable', 'string', 'max:300']]);
        $permissao = match ($d['estado']) {
            'APROVADA', 'RECUSADA' => 'imo_reserva_aprovar', 'CANCELADA' => 'imo_reserva_cancelar', default => 'imo_reserva_marcar',
        };
        $this->exigir($permissao);
        $res = ReservaEspaco::query()->findOrFail($reserva);
        if ($d['estado'] === 'CANCELADA') {
            $this->financeiro->cancelar($res, $d['motivo'] ?? null);
        } else {
            $this->reservas->mudarEstado($res, $d['estado'], $d['motivo'] ?? null);
        }

        return RespostaApi::sucesso(new ImobiliarioResource($this->ficha($res->refresh())), 'Reserva '.mb_strtolower(CatalogoParqueamento::ESTADOS_RESERVA[$d['estado']]['rot']).'.');
    }

    public function previsaoCancelamento(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_areas_view');
        $d = $r->validate(['quando' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->financeiro->previsaoCancelamento(ReservaEspaco::query()->findOrFail($reserva), $d['quando'] ?? null)), 'Previsão do cancelamento.');
    }

    public function cancelar(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_cancelar');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300'], 'devolver' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999']]);
        $res = ReservaEspaco::query()->findOrFail($reserva);
        $p = $this->financeiro->cancelar($res, $d['motivo'] ?? null, $d['devolver'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource(['previsao' => $p, 'reserva' => $this->ficha($res->refresh())]), 'Reserva cancelada.');
    }

    public function facturar(int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_facturar');
        $res = $this->financeiro->facturar(ReservaEspaco::query()->findOrFail($reserva));

        return RespostaApi::sucesso(new ImobiliarioResource(['venda_id' => $res['venda_id'], 'numero_documento' => $res['numero_documento'], 'repetida' => $res['repetida'],
            'reserva' => $this->ficha($res['reserva'])]), $res['repetida'] ? 'A reserva já estava facturada.' : "Factura {$res['numero_documento']} emitida.");
    }

    public function receber(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_receber');
        $d = $r->validate(['valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'caucao' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'meio_pagamento' => ['nullable', Rule::in(['NUMERARIO', 'TPA', 'TRANSFERENCIA'])], 'conta_financeira' => ['nullable', 'string', 'max:20'], 'referencia' => ['nullable', 'string', 'max:100'],
            'chave' => ['nullable', 'string', 'max:60']]);
        $res = $this->financeiro->receber(ReservaEspaco::query()->findOrFail($reserva), $d);

        return RespostaApi::sucesso(new ImobiliarioResource(['recibo_id' => $res['recibo_id'], 'valor' => $res['valor'], 'caucao' => $res['caucao'], 'reserva' => $this->ficha($res['reserva'])]),
            $res['reserva']->estado === 'CONFIRMADA' ? 'Recebido. A reserva está confirmada e o espaço garantido.' : 'Recebimento registado.');
    }

    public function vistoria(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_vistoria');
        $d = $r->validate(['quando' => ['required', Rule::in(VistoriaReserva::QUANDO)], 'estado' => ['nullable', Rule::in(VistoriaReserva::ESTADOS)], 'observacoes' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'string', 'max:1000'], 'data' => ['nullable', 'date_format:Y-m-d'], 'custo_danos' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999']]);
        $res = ReservaEspaco::query()->findOrFail($reserva);
        $this->financeiro->vistoria($res, $d);

        return RespostaApi::criado(new ImobiliarioResource($this->ficha($res->refresh())), 'Vistoria registada.');
    }

    public function caucao(int $reserva): JsonResponse
    {
        $this->exigir('imo_areas_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->caucao->listar(ReservaEspaco::query()->findOrFail($reserva))), 'Caução da reserva.');
    }

    public function movimentarCaucao(Request $r, int $reserva): JsonResponse
    {
        $this->exigir('imo_reserva_caucao');
        $d = $r->validate(['tipo' => ['required', Rule::in(MovimentoCaucaoReserva::TIPOS)], 'valor' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'conta_financeira' => ['nullable', 'string', 'max:20'], 'motivo' => ['nullable', 'string', 'max:300'], 'chave' => ['nullable', 'string', 'max:80']]);
        $res = ReservaEspaco::query()->findOrFail($reserva);
        $m = $this->caucao->movimentar($res, $d);

        return RespostaApi::criado(new ImobiliarioResource(['movimento' => $m->attributesToArray(), 'reserva' => $this->ficha($res->refresh())]), 'Movimento da caução registado.');
    }

    public function anularCaucao(Request $r, int $movimento): JsonResponse
    {
        $this->exigir('imo_reserva_caucao');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $m = $this->caucao->anular(MovimentoCaucaoReserva::query()->findOrFail($movimento), $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($m->attributesToArray()), 'Movimento anulado.');
    }

    /** Verifica os prazos de pagamento (a tarefa agendada faz o mesmo): confirma o que ficou pago e cancela o que não recebeu nada. */
    public function caducar(Request $r): JsonResponse
    {
        $this->exigir('imo_reserva_aprovar', 'imo_reserva_cancelar');
        $d = $r->validate(['imovel_id' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->financeiro->caducarPendentes($d['imovel_id'] ?? null)), 'Prazos de pagamento verificados.');
    }

    /** @return array<string, mixed> a reserva com o espaço, a fracção, o reservante, a situação financeira, as vistorias e o trilho */
    private function ficha(ReservaEspaco $r): array
    {
        $e = EspacoComum::query()->find($r->espaco_id, ['id', 'nome', 'tipo', 'regime', 'regulamento', 'regulamento_url', 'cancel_dias', 'cancel_pct', 'capacidade']);
        $f = $r->fracao_id ? Fracao::query()->find($r->fracao_id, ['id', 'designacao', 'codigo']) : null;
        $t = $r->terceiro_id ? Terceiro::query()->find($r->terceiro_id, ['id', 'nome']) : null;
        if (! $e) {
            throw new ErroNegocio('Espaço não encontrado.', 'RESERVA_ESPACO_INEXISTENTE', 404);
        }

        return $r->attributesToArray() + ['espaco' => $e->attributesToArray(), 'fraccao' => $f?->only(['id', 'designacao', 'codigo']), 'reservante' => $r->interna ? 'Administração' : ($t?->nome ?? $f?->designacao ?? '—'),
            'situacao' => $this->financeiro->situacao($r), 'historico' => $this->financeiro->historico($r),
            'vistorias' => VistoriaReserva::query()->where('reserva_id', $r->id)->orderBy('id')->get()->map(fn ($v) => $v->attributesToArray())->all()];
    }
}
