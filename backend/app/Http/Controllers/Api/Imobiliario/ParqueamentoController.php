<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\ContratoArrendamento;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Services\Imobiliario\CatalogoParqueamento;
use App\Services\Imobiliario\ServicoParqueamento;
use App\Services\Imobiliario\ServicoVeiculos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Parqueamento (Onda 3, ecrã imo_parque). Ver: imo_parque_view. Escrever: criar, editar e gerar vagas imo_vaga_editar; afectar e libertar
 * imo_vaga_afectar; veículos do contrato imo_vaga_veiculos (também recusadas no servidor, antes da validação).
 *  GET  /imobiliario/parque/listas                          enumerações do parqueamento e das áreas comuns
 *  GET  /imobiliario/imoveis/{id}/parque/mapa|livres        mapa por piso com a ocupação, e lugares livres
 *  POST /imobiliario/imoveis/{id}/parque/gerar|vagas        gerar por piso; nova vaga;  PUT|DELETE /imobiliario/parque/vagas/{id}
 *  POST /imobiliario/parque/afectar|libertar                afectar vagas a uma fracção; libertá-las
 *  GET  /imobiliario/parque/encargos-propostos              encargos (origem PARQUE) propostos às fracções indicadas
 *  GET|PUT /imobiliario/contratos/{id}/veiculos             veículos do contrato
 */
final class ParqueamentoController extends Controller
{
    private const VER = ['imo_parque_view', 'imo_areas_view'];

    public function __construct(private readonly ServicoParqueamento $parque, private readonly ServicoVeiculos $veiculos) {}

    public function listas(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource(CatalogoParqueamento::listas()), 'Listas do parqueamento e das áreas comuns.');
    }

    public function mapa(Request $r, int $imovel): JsonResponse
    {
        $this->exigir('imo_parque_view');
        $d = $r->validate(['texto' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->parque->mapa(Imovel::query()->findOrFail($imovel), $d['texto'] ?? null)), 'Mapa de parqueamento.');
    }

    public function livres(int $imovel): JsonResponse
    {
        $this->exigir('imo_parque_view', 'imo_contratos_view', 'imo_contrato_editar');

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->parque->livres(Imovel::query()->findOrFail($imovel))), 'Lugares livres.');
    }

    public function gerar(Request $r, int $imovel): JsonResponse
    {
        $this->exigir('imo_vaga_editar');
        $d = $r->validate([
            'pisos' => ['required'], 'pisos.*' => ['nullable', 'integer', 'min:-20', 'max:300'], 'por_piso' => ['nullable', 'integer', 'min:1', 'max:500'], 'prefixo' => ['nullable', 'string', 'max:4'],
            'bloco' => ['nullable', 'string', 'max:40'], 'park_tipo' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_TIPOS))], 'park_porte' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_PORTES))],
            'afectacao' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_AFECTACOES))], 'park_altura_max' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'park_comprimento' => ['nullable', 'numeric', 'min:0', 'max:99'], 'park_largura' => ['nullable', 'numeric', 'min:0', 'max:99'], 'park_tomada' => ['nullable', 'boolean'],
            'area_bruta' => ['nullable', 'numeric', 'min:0', 'max:9999999'], 'renda_referencia' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
        ]);
        if (is_string($d['pisos']) && preg_match('/^[-0-9,\s]{1,200}$/', $d['pisos']) !== 1) {
            throw new ErroNegocio('Indique os pisos como números separados por vírgulas (ex.: -1, -2).', 'PARQUE_PISOS');
        }
        $res = $this->parque->gerarVagas(Imovel::query()->findOrFail($imovel), $d);

        return RespostaApi::sucesso(new ImobiliarioResource($res), $res['criadas'].' lugar(es) criado(s)'.($res['repetidas'] > 0 ? ', '.$res['repetidas'].' já existiam e foram saltados.' : '.'));
    }

    /** @return array<string, mixed> */
    private function regrasVaga(): array
    {
        return [
            'designacao' => ['nullable', 'string', 'max:120'], 'codigo' => ['nullable', 'string', 'max:40'], 'piso' => ['nullable', 'integer', 'min:-20', 'max:300'],
            'park_tipo' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_TIPOS))], 'park_porte' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_PORTES))],
            'afectacao' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_AFECTACOES))], 'fracao_id' => ['nullable', 'integer', 'min:1'], 'incluida_na_renda' => ['nullable', 'boolean'],
            'park_altura_max' => ['nullable', 'numeric', 'min:0', 'max:99'], 'park_comprimento' => ['nullable', 'numeric', 'min:0', 'max:99'], 'park_largura' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'park_tomada' => ['nullable', 'boolean'], 'park_distico' => ['nullable', 'string', 'max:60'], 'area_bruta' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'renda_referencia' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'estado' => ['nullable', Rule::in(['DISPONIVEL', 'EM_OBRA', 'INDISPONIVEL'])],
            'bloco' => ['nullable', 'string', 'max:40'], 'numero' => ['nullable', 'string', 'max:30'], 'notas' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function criar(Request $r, int $imovel): JsonResponse
    {
        $this->exigir('imo_vaga_editar');
        $v = $this->parque->criarVaga(Imovel::query()->findOrFail($imovel), $r->validate($this->regrasVaga()));

        return RespostaApi::criado(new ImobiliarioResource($v), "Vaga {$v->designacao} criada.");
    }

    public function actualizar(Request $r, int $vaga): JsonResponse
    {
        $this->exigir('imo_vaga_editar');
        $v = $this->parque->actualizarVaga(Fracao::query()->findOrFail($vaga), $r->validate($this->regrasVaga()));

        return RespostaApi::sucesso(new ImobiliarioResource($v), 'Vaga actualizada.');
    }

    public function eliminar(int $vaga): JsonResponse
    {
        $this->exigir('imo_vaga_editar');
        $this->parque->eliminarVaga(Fracao::query()->findOrFail($vaga));

        return RespostaApi::sucesso(null, 'Vaga apagada.');
    }

    public function afectar(Request $r): JsonResponse
    {
        $this->exigir('imo_vaga_afectar');
        $d = $r->validate(['fracao_id' => ['required', 'integer', 'min:1'], 'vaga_ids' => ['required', 'array', 'min:1', 'max:500'], 'vaga_ids.*' => ['integer', 'min:1'],
            'incluida_na_renda' => ['nullable', 'boolean'], 'reatribuir' => ['nullable', 'boolean']]);
        $res = $this->parque->afectar(Fracao::query()->findOrFail($d['fracao_id']), $d['vaga_ids'], $d['incluida_na_renda'] ?? null, (bool) ($d['reatribuir'] ?? false));

        return RespostaApi::sucesso(new ImobiliarioResource($res), $res['afectadas'].' lugar(es) afecto(s).');
    }

    public function libertar(Request $r): JsonResponse
    {
        $this->exigir('imo_vaga_afectar');
        $d = $r->validate(['vaga_ids' => ['required', 'array', 'min:1', 'max:500'], 'vaga_ids.*' => ['integer', 'min:1']]);
        $res = $this->parque->libertar($d['vaga_ids']);

        return RespostaApi::sucesso(new ImobiliarioResource($res), $res['libertadas'].' lugar(es) libertado(s).');
    }

    public function encargosPropostos(Request $r): JsonResponse
    {
        $this->exigir('imo_parque_view', 'imo_contratos_view', 'imo_contrato_editar');
        $d = $r->validate(['fracao_ids' => ['required', 'string', 'max:500']]);
        $ids = array_filter(array_map('intval', explode(',', $d['fracao_ids'])));

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->parque->encargosPropostos($ids)), 'Encargos propostos pelas vagas afectas.');
    }

    public function veiculos(int $contrato): JsonResponse
    {
        $this->exigir('imo_parque_view', 'imo_contratos_view');

        return RespostaApi::sucesso(new ImobiliarioResource($this->veiculos->doContrato(ContratoArrendamento::query()->findOrFail($contrato))), 'Veículos do contrato.');
    }

    public function gravarVeiculos(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('imo_vaga_veiculos');
        $d = $r->validate(['veiculos' => ['present', 'array', 'max:50'], 'veiculos.*.matricula' => ['nullable', 'string', 'max:20'], 'veiculos.*.marca' => ['nullable', 'string', 'max:60'],
            'veiculos.*.modelo' => ['nullable', 'string', 'max:60'], 'veiculos.*.cor' => ['nullable', 'string', 'max:40'], 'veiculos.*.porte' => ['nullable', Rule::in(array_keys(CatalogoParqueamento::PARK_PORTES))],
            'veiculos.*.condutor' => ['nullable', 'string', 'max:120'], 'veiculos.*.distico' => ['nullable', 'string', 'max:60'], 'veiculos.*.vaga_id' => ['nullable', 'integer', 'min:1'],
            'confirmar_repetidas' => ['nullable', 'boolean']]);
        $lista = array_values(array_filter($d['veiculos'], fn ($v) => trim((string) ($v['matricula'] ?? '')) !== '' || trim((string) ($v['marca'] ?? '')) !== ''));
        $res = $this->veiculos->gravar(ContratoArrendamento::query()->findOrFail($contrato), $lista, (bool) ($d['confirmar_repetidas'] ?? false));

        return RespostaApi::sucesso(new ImobiliarioResource($res), $res['gravados'].' veículo(s) gravado(s).');
    }
}
