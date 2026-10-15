<?php

namespace App\Http\Controllers\Api\Ativos;

use App\Http\Controllers\Controller;
use App\Models\AndarEdificio;
use App\Models\AtivoImobilizado;
use App\Models\InventarioFisicoAtivo;
use App\Models\LinhaInventarioFisicoAtivo;
use App\Models\PortaEdificio;
use App\Services\Ativos\ServicoInventarioFisicoAtivos;
use App\Services\Ativos\ServicoLocaisAtivos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/ativos — Edifícios e Locais e folhas de inventário físico (Fase F, Versão Actual; js/activos_edificios.js).
 * Permissões (catálogo, ecrã activos_edificios): ver = activos_edificios_view; criar andares/portas e localizar bens =
 * activos_locais_gerir; gerar, contar, aplicar, fechar e anular folhas = activos_inventario_fisico (sensível).
 * A permissão é verificada sempre antes da validação (OWASP A01); os registos de outra empresa dão 404 (EscopoEmpresa).
 */
final class LocaisAtivosController extends Controller
{
    private const VER = ['activos_edificios_view', 'activos_locais_gerir', 'activos_inventario_fisico'];

    public function __construct(
        private readonly ServicoLocaisAtivos $locais,
        private readonly ServicoInventarioFisicoAtivos $inventario,
    ) {}

    // ───────────── Edifícios, andares e portas ─────────────

    public function edificios(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->locais->edificios(), 'Edifícios e locais.');
    }

    /** Selects encadeados da ficha do activo e coluna «Localização» da lista (também com as permissões do cadastro). */
    public function opcoes(): JsonResponse
    {
        $this->exigir('activos_view', 'activos_gerir', ...self::VER);

        return RespostaApi::sucesso($this->locais->opcoes(), 'Edifícios, andares e portas.');
    }

    public function planta(int $edificio): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->locais->planta(AtivoImobilizado::query()->findOrFail($edificio)), 'Planta do edifício.');
    }

    public function criarAndar(Request $r, int $edificio): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $d = $r->validate(self::REGRAS_ANDAR);

        return RespostaApi::criado($this->locais->guardarAndar(AtivoImobilizado::query()->findOrFail($edificio), $d), 'Andar gravado.');
    }

    public function actualizarAndar(Request $r, int $andar): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $d = $r->validate(self::REGRAS_ANDAR);
        $f = AndarEdificio::query()->findOrFail($andar);

        return RespostaApi::sucesso($this->locais->guardarAndar(AtivoImobilizado::query()->findOrFail($f->edificio_id), $d, $f), 'Andar gravado.');
    }

    private const REGRAS_ANDAR = ['numero' => ['nullable', 'string', 'max:20'], 'nome' => ['nullable', 'string', 'max:255'], 'notas' => ['nullable', 'string', 'max:2000'],
        'ordem' => ['nullable', 'integer', 'between:-1000,10000']];

    public function eliminarAndar(int $andar): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $res = $this->locais->eliminarAndar(AndarEdificio::query()->findOrFail($andar));

        return RespostaApi::sucesso($res, "Andar eliminado ({$res['bens_libertados']} bem(ns) ficaram sem porta).");
    }

    public function criarPortas(Request $r, int $edificio): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $d = $r->validate(['andar_id' => ['required', 'integer'], 'prefixo' => ['nullable', 'string', 'max:10'], 'de' => ['required', 'integer', 'between:0,99999'],
            'ate' => ['required', 'integer', 'between:0,99999'], 'tipo' => ['nullable', 'string', 'max:50'], 'capacidade' => ['nullable', 'integer', 'min:0', 'max:100000']]);
        $res = $this->locais->criarPortas(AtivoImobilizado::query()->findOrFail($edificio), (int) $d['andar_id'], $d['prefixo'] ?? null, (int) $d['de'], (int) $d['ate'],
            $d['tipo'] ?? null, isset($d['capacidade']) ? (int) $d['capacidade'] : null);

        return RespostaApi::criado($res, "{$res['criadas']} porta(s) criada(s).");
    }

    public function guardarPorta(Request $r, int $porta): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $d = $r->validate(['numero' => ['sometimes', 'required', 'string', 'max:20'], 'nome' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tipo' => ['sometimes', 'nullable', 'string', 'max:50'], 'capacidade' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000']]);

        return RespostaApi::sucesso($this->locais->guardarPorta(PortaEdificio::query()->findOrFail($porta), $d), 'Porta gravada.');
    }

    public function eliminarPorta(int $porta): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $res = $this->locais->eliminarPorta(PortaEdificio::query()->findOrFail($porta));

        return RespostaApi::sucesso($res, "Porta eliminada ({$res['bens_libertados']} bem(ns) ficaram sem porta).");
    }

    /** Arrastar um bem para uma porta (ou retirá-lo do local com porta_id = null) — assetMoverBem. */
    public function moverBem(Request $r, int $bem): JsonResponse
    {
        $this->exigir('activos_locais_gerir');
        $d = $r->validate(['porta_id' => ['present', 'nullable', 'integer']]);
        $res = $this->locais->moverBem(AtivoImobilizado::query()->findOrFail($bem), $d['porta_id'] ? (int) $d['porta_id'] : null);

        return RespostaApi::sucesso($res->only(['id', 'codigo', 'edificio_id', 'andar_id', 'porta_id']), $d['porta_id'] ? 'Bem movido para a porta seleccionada.' : 'Bem retirado do local.');
    }

    // ───────────── Folhas de inventário físico ─────────────

    public function inventarios(Request $r): JsonResponse
    {
        $this->exigir('activos_edificios_view', 'activos_inventario_fisico');
        $f = $r->validate(['edificio_id' => ['nullable', 'integer'], 'estado' => ['nullable', Rule::in([InventarioFisicoAtivo::ABERTO, InventarioFisicoAtivo::FECHADO, InventarioFisicoAtivo::ANULADO])],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1']]);

        return RespostaApi::paginado($this->inventario->listar($f), null, 'Folhas de inventário físico.');
    }

    public function gerarInventario(Request $r, int $edificio): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');
        $d = $r->validate(['ambito' => ['required', Rule::in(InventarioFisicoAtivo::AMBITOS)], 'andar_id' => ['nullable', 'integer'], 'porta_id' => ['nullable', 'integer'],
            'data' => ['nullable', 'date'], 'responsavel' => ['nullable', 'string', 'max:150'], 'notas' => ['nullable', 'string', 'max:2000'], 'confirmar_vazia' => ['nullable', 'boolean']]);
        $inv = $this->inventario->gerar(AtivoImobilizado::query()->findOrFail($edificio), $d);

        return RespostaApi::criado($inv, "Folha de inventário INV-{$inv->id} criada com {$inv->esperados} bem(ns).");
    }

    public function inventario(int $inventario): JsonResponse
    {
        $this->exigir('activos_edificios_view', 'activos_inventario_fisico');

        return RespostaApi::sucesso($this->inventario->folha(InventarioFisicoAtivo::query()->findOrFail($inventario)), 'Folha de inventário.');
    }

    public function comparacao(int $inventario): JsonResponse
    {
        $this->exigir('activos_edificios_view', 'activos_inventario_fisico');

        return RespostaApi::sucesso($this->inventario->comparacao(InventarioFisicoAtivo::query()->findOrFail($inventario)), 'Comparação do inventário.');
    }

    public function gravarContagem(Request $r, int $inventario): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');
        $d = $r->validate(['linhas' => ['required', 'array', 'max:20000'], 'linhas.*.id' => ['required', 'integer'],
            'linhas.*.resultado' => ['nullable', Rule::in(['', 'ENCONTRADO', 'EM_FALTA', 'DESLOCADO'])], 'linhas.*.porta_encontrada_id' => ['nullable', 'integer'],
            'linhas.*.notas' => ['nullable', 'string', 'max:2000']]);
        $res = $this->inventario->gravarContagem(InventarioFisicoAtivo::query()->findOrFail($inventario), $d['linhas']);

        return RespostaApi::sucesso($res, "Contagem gravada ({$res['contados']} bem(ns) contados).");
    }

    public function acrescentarExtra(Request $r, int $inventario): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');
        $d = $r->validate(['ativo_imobilizado_id' => ['required', 'integer'], 'porta_encontrada_id' => ['required', 'integer']]);

        return RespostaApi::criado($this->inventario->acrescentarExtra(InventarioFisicoAtivo::query()->findOrFail($inventario), (int) $d['ativo_imobilizado_id'],
            (int) $d['porta_encontrada_id']), 'Bem acrescentado como não previsto.');
    }

    public function removerExtra(int $linha): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');
        $this->inventario->removerExtra(LinhaInventarioFisicoAtivo::query()->findOrFail($linha));

        return RespostaApi::sucesso(null, 'Bem retirado da folha.');
    }

    public function aplicar(int $inventario): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');
        $res = $this->inventario->aplicar(InventarioFisicoAtivo::query()->findOrFail($inventario));

        return RespostaApi::sucesso($res, "{$res['relocalizados']} bem(ns) relocalizado(s).");
    }

    public function fechar(int $inventario): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');

        return RespostaApi::sucesso($this->inventario->fechar(InventarioFisicoAtivo::query()->findOrFail($inventario)), 'Inventário fechado.');
    }

    public function anular(Request $r, int $inventario): JsonResponse
    {
        $this->exigir('activos_inventario_fisico');
        $d = $r->validate(['motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->inventario->anular(InventarioFisicoAtivo::query()->findOrFail($inventario), $d['motivo']), 'Folha anulada.');
    }
}
