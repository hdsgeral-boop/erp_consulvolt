<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imobiliario\GravarContratoRequest;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\ContratoArrendamento;
use App\Models\MovimentoCaucaoImo;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Imobiliario\CatalogoContratos;
use App\Services\Imobiliario\ServicoAvisosContratos;
use App\Services\Imobiliario\ServicoCaucao;
use App\Services\Imobiliario\ServicoContaCorrente;
use App\Services\Imobiliario\ServicoContratos;
use App\Services\Imobiliario\ServicoRendas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Contratos de arrendamento, conta corrente e caução (Onda 2, ecrã imo_contratos). Ver: imo_contratos_view. Escrever: imo_contrato_editar;
 * activar e actualizar a renda: imo_contrato_activar; suspender: imo_contrato_suspender; terminar, denunciar e eliminar: imo_contrato_terminar;
 * renovar: imo_contrato_renovar; gerar rendas: imo_renda_gerar; movimentar a caução: imo_caucao_movimentar (quem a movimenta não termina o contrato).
 *  GET  /imobiliario/contratos/listas|resumo|avisos|contas      enumerações, indicadores, avisos e contas de movimento do plano
 *  GET|POST /imobiliario/contratos, GET|PUT|DELETE /imobiliario/contratos/{id}
 *  POST /imobiliario/contratos/{id}/activar|suspender|terminar|renovar|actualizar-renda|rendas/gerar
 *  GET  /imobiliario/contratos/{id}/conta-corrente             rendas, pago, dívida, atraso e juros de mora
 *  GET|POST /imobiliario/contratos/{id}/caucao                 ledger da caução e novo movimento;  POST /imobiliario/caucao/{movimento}/anular
 */
final class ContratosController extends Controller
{
    private const VER = ['imo_contratos_view', 'imo_rendas_view', 'imo_sub_view'];

    public function __construct(
        private readonly ServicoContratos $contratos,
        private readonly ServicoRendas $rendas,
        private readonly ServicoContaCorrente $contaCorrente,
        private readonly ServicoCaucao $caucao,
        private readonly ServicoAvisosContratos $avisos,
        private readonly ServicoPlanoContas $plano,
    ) {}

    public function listas(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource(CatalogoContratos::listas()), 'Listas de contratos e rendas.');
    }

    public function resumo(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource($this->contratos->resumo()), 'Indicadores de contratos e rendas.');
    }

    public function avisos(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->avisos->todos()), 'Avisos de contratos.');
    }

    public function contas(): JsonResponse
    {
        $this->exigir('imo_contratos_view', 'imo_contrato_editar', 'imo_caucao_movimentar', 'imo_taxas_editar', 'imo_renda_pagar', 'imo_reserva_receber', 'imo_reserva_caucao');
        $contas = collect($this->plano->todas())->where('tipo', 'M')->sortKeys()->map(fn ($c) => ['codigo' => (string) $c['codigo'], 'descricao' => $c['descricao'] ?? null])->values()->all();

        return RespostaApi::sucesso(ImobiliarioResource::collection($contas), 'Contas de movimento do plano de contas.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['texto' => ['nullable', 'string', 'max:100'], 'estado' => ['nullable', Rule::in(array_keys(CatalogoContratos::ESTADOS_CONTRATO))],
            'posicao' => ['nullable', Rule::in(array_keys(CatalogoContratos::LADOS))]]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->contratos->listar($f)), 'Contratos.');
    }

    public function show(int $contrato): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource($this->contratos->ficha(ContratoArrendamento::query()->findOrFail($contrato))), 'Ficha do contrato.');
    }

    public function store(GravarContratoRequest $r): JsonResponse
    {
        $c = $this->contratos->gravar($r->validated());

        return RespostaApi::criado(new ImobiliarioResource($this->contratos->ficha($c)), "Contrato {$c->numero} criado em rascunho — active-o para gerar rendas.");
    }

    public function update(GravarContratoRequest $r, int $contrato): JsonResponse
    {
        $c = $this->contratos->gravar($r->validated(), ContratoArrendamento::query()->findOrFail($contrato));

        return RespostaApi::sucesso(new ImobiliarioResource($this->contratos->ficha($c)), 'Contrato gravado.');
    }

    public function destroy(int $contrato): JsonResponse
    {
        $this->exigir('imo_contrato_terminar');
        $this->contratos->eliminar(ContratoArrendamento::query()->findOrFail($contrato));

        return RespostaApi::sucesso(null, 'Contrato eliminado.');
    }

    public function activar(int $contrato): JsonResponse
    {
        $this->exigir('imo_contrato_activar');
        $c = $this->contratos->activar(ContratoArrendamento::query()->findOrFail($contrato));

        return RespostaApi::sucesso(new ImobiliarioResource($this->contratos->ficha($c)), $c->posicao === 'SENHORIO' ? 'Contrato activo. As fracções passaram a arrendadas.' : 'Contrato activo.');
    }

    public function suspender(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_contrato_suspender');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $c = $this->contratos->suspender(ContratoArrendamento::query()->findOrFail($contrato), $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->contratos->ficha($c)), 'Contrato suspenso.');
    }

    public function terminar(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_contrato_terminar');
        $d = $r->validate(['data' => ['nullable', 'date_format:Y-m-d'], 'motivo' => ['nullable', 'string', 'max:300'], 'denuncia' => ['nullable', 'boolean'], 'cascata' => ['nullable', 'boolean']]);
        $res = $this->contratos->terminar(ContratoArrendamento::query()->findOrFail($contrato), $d);

        return RespostaApi::sucesso(new ImobiliarioResource(['contrato' => $this->contratos->ficha($res['contrato'])] + array_diff_key($res, ['contrato' => 1])),
            "Contrato terminado. {$res['rendas_anuladas']} renda(s) futura(s) anulada(s).");
    }

    public function renovar(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_contrato_renovar');
        $d = $r->validate(['meses' => ['nullable', 'integer', 'min:1', 'max:120']]);
        $c = $this->contratos->renovar(ContratoArrendamento::query()->findOrFail($contrato), isset($d['meses']) ? (int) $d['meses'] : null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->contratos->ficha($c)), 'Contrato renovado até '.$c->fim?->format('Y-m-d').'.');
    }

    public function actualizarRenda(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_contrato_activar');
        $d = $r->validate(['renda_nova' => ['required', 'numeric', 'min:0', 'max:9999999999999999'], 'data_efeito' => ['required', 'date_format:Y-m-d'], 'recalcular' => ['nullable', 'boolean']],
            ['renda_nova.required' => 'Indique a renda nova.', 'data_efeito.required' => 'Indique a data de efeito.']);
        $res = $this->contratos->actualizarRenda(ContratoArrendamento::query()->findOrFail($contrato), $d['renda_nova'], $d['data_efeito'], ! array_key_exists('recalcular', $d) || (bool) $d['recalcular']);

        return RespostaApi::sucesso(new ImobiliarioResource(['contrato' => $this->contratos->ficha($res['contrato']), 'recalculadas' => $res['recalculadas']]),
            "Renda actualizada. {$res['recalculadas']} renda(s) recalculada(s).");
    }

    public function gerarRendas(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_renda_gerar');
        $d = $r->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $res = $this->rendas->gerar(ContratoArrendamento::query()->findOrFail($contrato), $d['de'] ?? null, $d['ate'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($res), "{$res['criadas']} renda(s) prevista(s) criada(s).");
    }

    public function contaCorrente(int $contrato): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource($this->contaCorrente->doContrato(ContratoArrendamento::query()->findOrFail($contrato))), 'Conta corrente do contrato.');
    }

    public function caucao(int $contrato): JsonResponse
    {
        $this->exigir('imo_contratos_view', 'imo_caucao_movimentar');

        return RespostaApi::sucesso(new ImobiliarioResource($this->caucao->listar(ContratoArrendamento::query()->findOrFail($contrato))), 'Caução do contrato.');
    }

    public function movimentarCaucao(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_caucao_movimentar');
        $d = $r->validate(['tipo' => ['required', Rule::in(array_keys(CatalogoContratos::TIPOS_CAUCAO))], 'valor' => ['required', 'numeric', 'min:0', 'max:9999999999999999'],
            'data' => ['nullable', 'date_format:Y-m-d'], 'conta_financeira' => ['nullable', 'string', 'max:20'], 'motivo' => ['nullable', 'string', 'max:300'], 'chave' => ['nullable', 'string', 'max:80']],
            ['tipo.required' => 'Escolha o tipo de movimento.', 'valor.required' => 'Indique o valor do movimento.']);
        $c = ContratoArrendamento::query()->findOrFail($contrato);
        $m = $this->caucao->movimentar($c, $d);

        return RespostaApi::criado(new ImobiliarioResource($this->caucao->listar($c->refresh()) + ['movimento' => $m->attributesToArray()]), 'Movimento da caução registado e contabilizado.');
    }

    public function anularMovimento(Request $r, int $movimento): JsonResponse
    {
        $this->exigir('imo_caucao_movimentar');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $m = $this->caucao->anular(MovimentoCaucaoImo::query()->findOrFail($movimento), $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->caucao->listar(ContratoArrendamento::query()->findOrFail($m->contrato_id))), 'Movimento anulado e estornado.');
    }
}
