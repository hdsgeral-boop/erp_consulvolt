<?php

namespace App\Http\Controllers\Api\Imobiliario;

use App\Http\Controllers\Controller;
use App\Http\Resources\Imobiliario\ImobiliarioResource;
use App\Models\CategoriaAtivo;
use App\Models\NegocioImobiliario;
use App\Services\Imobiliario\CalculadoraNegocios;
use App\Services\Imobiliario\ServicoNegociosImobiliarios;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compra e venda de imóveis (Onda 5, ecrã imo_negocios). Ver: imo_negocios_view. Criar, editar, apagar e gravar os encargos: imo_neg_editar. Aprovar o
 * negócio: imo_neg_aprovar. Avançar fases, cancelar e decidir o que acontece aos contratos da venda: imo_neg_fase (transmitir exige também
 * imo_contrato_terminar, porque termina os contratos). Concluir (compra: cria o bem em Activos; venda: factura e marca as fracções vendidas): imo_neg_concluir.
 * Abater o bem vendido e lançar a alienação: imo_neg_abater. Registar e anular pagamentos: imo_neg_pagamento. Todas recusadas no servidor, antes da validação
 * (quem aprova não regista pagamentos, quem conclui a venda não abate e quem avança as fases não conclui: pares do catálogo).
 *  GET  /imobiliario/negocios/listas|resumo|avisos|simular                 enumerações, indicadores, pontos a tratar, simulação dos impostos
 *  GET|POST /imobiliario/negocios;  GET|PUT|DELETE …/{id}                  lista, novo, ficha, editar, apagar
 *  POST /imobiliario/negocios/{id}/aprovar|avancar|cancelar|contratos|concluir|abater
 *  PUT  /imobiliario/negocios/{id}/custos                                  encargos (substitui todos)
 *  POST /imobiliario/negocios/{id}/pagamentos;  POST …/pagamentos/{p}/anular
 */
final class NegociosController extends Controller
{
    private const VER = ['imo_negocios_view'];

    public function __construct(private readonly ServicoNegociosImobiliarios $negocios) {}

    public function listas(): JsonResponse
    {
        $this->exigir(...self::VER);
        $rot = fn (array $m) => collect($m)->map(fn ($v, $k) => ['chave' => $k, 'rotulo' => is_array($v) ? $v['rot'] : $v] + (is_array($v) && isset($v['capitaliza']) ? ['capitaliza' => $v['capitaliza']] : []))->values()->all();

        return RespostaApi::sucesso(new ImobiliarioResource(['tipos' => $rot(CalculadoraNegocios::TIPOS), 'fases' => $rot(CalculadoraNegocios::FASES), 'tipos_custo' => $rot(CalculadoraNegocios::TIPOS_CUSTO),
            'isencoes' => $rot(CalculadoraNegocios::ISENCOES), 'finalidades' => $rot(CalculadoraNegocios::FINALIDADES), 'destinos_contratos' => $rot(CalculadoraNegocios::DESTINOS_CONTRATOS),
            'tipos_pagamento' => $rot(CalculadoraNegocios::TIPOS_PAGAMENTO)]), 'Listas dos negócios.');
    }

    public function categorias(): JsonResponse
    {
        $this->exigir('imo_neg_concluir');

        return RespostaApi::sucesso(ImobiliarioResource::collection(CategoriaAtivo::query()->orderBy('nome')->limit(200)->get(['id', 'nome', 'conta_ativo'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'nome' => $c->nome, 'conta_ativo' => $c->conta_ativo])->all()), 'Categorias de activos.');
    }

    public function resumo(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['ano' => ['nullable', 'integer', 'min:2000', 'max:2100']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->resumo(isset($d['ano']) ? (int) $d['ano'] : null)), 'Indicadores dos negócios.');
    }

    public function avisos(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->negocios->avisos(), 'Avisos dos negócios.');
    }

    public function simular(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $d = $r->validate(['preco' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'], 'vpt' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'finalidade' => ['nullable', Rule::in(array_keys(CalculadoraNegocios::FINALIDADES))], 'isencao' => ['nullable', Rule::in(array_keys(CalculadoraNegocios::ISENCOES))],
            'data_escritura' => ['nullable', 'date_format:Y-m-d'], 'data_promessa' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->simular($d)), 'Impostos da transmissão simulados.');
    }

    public function index(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['tipo' => ['nullable', Rule::in(NegocioImobiliario::TIPOS)], 'estado' => ['nullable', Rule::in(NegocioImobiliario::ESTADOS)], 'abertos' => ['nullable', 'boolean'],
            'imovel_id' => ['nullable', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'], 'texto' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso(ImobiliarioResource::collection($this->negocios->listar($f)), 'Negócios.');
    }

    public function show(int $negocio): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha(NegocioImobiliario::query()->findOrFail($negocio))), 'Negócio.');
    }

    /** @return array<string, mixed> */
    private function regras(): array
    {
        return ['tipo' => ['nullable', Rule::in(NegocioImobiliario::TIPOS)], 'imovel_id' => ['nullable', 'integer', 'min:1'], 'fracao_id' => ['nullable', 'integer', 'min:1'],
            'terceiro_id' => ['nullable', 'integer', 'min:1'], 'preco' => ['nullable', 'numeric', 'max:9999999999999999'], 'vpt' => ['nullable', 'numeric', 'max:9999999999999999'],
            'sinal' => ['nullable', 'numeric', 'max:9999999999999999'], 'finalidade' => ['nullable', Rule::in(array_keys(CalculadoraNegocios::FINALIDADES))],
            'isencao' => ['nullable', Rule::in(array_keys(CalculadoraNegocios::ISENCOES))], 'data_promessa' => ['nullable', 'date_format:Y-m-d'], 'data_escritura' => ['nullable', 'date_format:Y-m-d'],
            'data_registo' => ['nullable', 'date_format:Y-m-d'], 'notario' => ['nullable', 'string', 'max:150'], 'notas' => ['nullable', 'string', 'max:5000']];
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('imo_neg_editar');
        $n = $this->negocios->gravar($r->validate($this->regras()));

        return RespostaApi::criado(new ImobiliarioResource($this->negocios->ficha($n)), 'Negócio criado em negociação.');
    }

    public function update(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_editar');
        $n = $this->negocios->gravar($r->validate($this->regras()), NegocioImobiliario::query()->findOrFail($negocio));

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha($n)), 'Negócio gravado.');
    }

    public function destroy(int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_editar');
        $this->negocios->eliminar(NegocioImobiliario::query()->findOrFail($negocio));

        return RespostaApi::sucesso(null, 'Negócio eliminado.');
    }

    public function custos(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_editar');
        $d = $r->validate(['linhas' => ['present', 'array', 'max:60'], 'linhas.*.tipo' => ['nullable', 'string', 'max:12'], 'linhas.*.designacao' => ['nullable', 'string', 'max:200'],
            'linhas.*.valor' => ['nullable', 'numeric', 'max:9999999999999999'], 'linhas.*.capitaliza' => ['nullable', 'boolean'], 'linhas.*.pago' => ['nullable', 'boolean'],
            'linhas.*.data' => ['nullable', 'date_format:Y-m-d']]);
        $n = NegocioImobiliario::query()->findOrFail($negocio);
        $this->negocios->gravarCustos($n, $d['linhas']);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha($n->refresh())), 'Encargos gravados.');
    }

    public function aprovar(int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_aprovar');
        $n = $this->negocios->aprovar(NegocioImobiliario::query()->findOrFail($negocio));

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha($n)), 'Negócio aprovado.');
    }

    public function avancar(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_fase');
        $d = $r->validate(['fase' => ['required', 'string', 'max:12']]);
        $n = $this->negocios->avancar(NegocioImobiliario::query()->findOrFail($negocio), $d['fase']);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha($n)), 'Fase avançada.');
    }

    public function cancelar(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_fase');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $n = $this->negocios->cancelar(NegocioImobiliario::query()->findOrFail($negocio), $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha($n)), 'Negócio cancelado.');
    }

    public function contratos(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_fase');
        $d = $r->validate(['destino' => ['required', Rule::in(array_keys(CalculadoraNegocios::DESTINOS_CONTRATOS))]]);
        if ($d['destino'] === 'TRANSMITE') {
            $this->exigir('imo_contrato_terminar');   // transmitir termina os contratos: exige também a permissão de os terminar
        }
        $x = $this->negocios->tratarContratos(NegocioImobiliario::query()->findOrFail($negocio), $d['destino']);

        return RespostaApi::sucesso(new ImobiliarioResource(['terminados' => $x['terminados'], 'negocio' => $this->negocios->ficha($x['negocio'])]), 'Contratos tratados.');
    }

    public function concluir(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_concluir');
        $d = $r->validate(['categoria_ativo_id' => ['nullable', 'integer', 'min:1']]);
        $n = NegocioImobiliario::query()->findOrFail($negocio);
        $x = $n->tipo === 'COMPRA' ? $this->negocios->concluirCompra($n, $d) : $this->negocios->concluirVenda($n);
        $x['negocio'] = $this->negocios->ficha($x['negocio']);

        return RespostaApi::sucesso(new ImobiliarioResource($x), $n->tipo === 'COMPRA' ? 'Compra concluída.' : 'Venda concluída.');
    }

    public function abater(int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_abater');
        $x = $this->negocios->abater(NegocioImobiliario::query()->findOrFail($negocio));
        $x['negocio'] = $this->negocios->ficha($x['negocio']);

        return RespostaApi::sucesso(new ImobiliarioResource($x), 'Bem abatido e alienação lançada.');
    }

    public function pagar(Request $r, int $negocio): JsonResponse
    {
        $this->exigir('imo_neg_pagamento');
        $d = $r->validate(['tipo' => ['required', Rule::in(array_keys(CalculadoraNegocios::TIPOS_PAGAMENTO))], 'valor' => ['required', 'numeric', 'gt:0', 'max:9999999999999999'],
            'data' => ['nullable', 'date_format:Y-m-d'], 'meio' => ['nullable', 'string', 'max:20'], 'referencia' => ['nullable', 'string', 'max:120'], 'chave' => ['nullable', 'string', 'max:80']]);
        $n = NegocioImobiliario::query()->findOrFail($negocio);
        $this->negocios->registarPagamento($n, $d);

        return RespostaApi::criado(new ImobiliarioResource($this->negocios->ficha($n->refresh())), 'Pagamento registado.');
    }

    public function anularPagamento(Request $r, int $negocio, int $pagamento): JsonResponse
    {
        $this->exigir('imo_neg_pagamento');
        $d = $r->validate(['motivo' => ['nullable', 'string', 'max:300']]);
        $n = NegocioImobiliario::query()->findOrFail($negocio);
        $this->negocios->anularPagamento($n, $pagamento, $d['motivo'] ?? null);

        return RespostaApi::sucesso(new ImobiliarioResource($this->negocios->ficha($n->refresh())), 'Pagamento anulado.');
    }
}
