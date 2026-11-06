<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Mesas\CobrarRequest;
use App\Http\Requests\POS\Mesas\GravarContaRequest;
use App\Http\Requests\POS\Mesas\GuardarMesaRequest;
use App\Http\Requests\POS\Mesas\IndexRequest;
use App\Http\Resources\POS\VendaPOSResource;
use App\Models\Produto;
use App\Services\POS\ServicoMesasPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;

/**
 * M-15 — mesas do POS restaurante (decisão 14): /api/pos/terminais/{t}/mesas, /api/pos/mesas/{m}(/conta),
 * /api/pos/sessoes/{s}/mesas/{m}/cobrar. Criar mesa: pos_terminais_gerir ou pos_venda (no legado o operador criava
 * mesas no próprio POS); alterar/eliminar: pos_terminais_gerir; contas: pos_venda (desconto/preço: pos_desconto).
 */
final class MesasPOSController extends Controller
{
    public function __construct(private readonly ServicoMesasPOS $mesas) {}

    public function index(IndexRequest $r, int $terminal): JsonResponse
    {
        $d = $r->validated();

        return RespostaApi::sucesso($this->mesas->mesas($terminal, (bool) ($d['incluir_inactivas'] ?? false)), 'Mesas obtidas com sucesso.');
    }

    public function store(GuardarMesaRequest $r, int $terminal): JsonResponse
    {
        return RespostaApi::criado($this->mesas->guardarMesa($terminal, $r->validated()), 'Mesa criada.');
    }

    public function update(GuardarMesaRequest $r, int $terminal, int $mesa): JsonResponse
    {
        return RespostaApi::sucesso($this->mesas->guardarMesa($terminal, $r->validated(), $mesa), 'Mesa actualizada.');
    }

    public function destroy(int $mesa): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');
        $this->mesas->eliminarMesa($mesa);

        return RespostaApi::sucesso(null, 'Mesa eliminada (ou desactivada, se já tinha contas).');
    }

    public function conta(int $mesa): JsonResponse
    {
        $this->exigir('pos_venda');

        return RespostaApi::sucesso($this->mesas->conta($mesa), 'Conta da mesa obtida.');
    }

    /** PUT /mesas/{m}/conta — «Suspender»: grava a conta da mesa (sem linhas, liberta a mesa). */
    public function gravarConta(GravarContaRequest $r, int $mesa): JsonResponse
    {
        $d = $r->validated();
        $this->exigirDesconto($d);
        $c = $this->mesas->gravarConta($mesa, $d);

        return RespostaApi::sucesso($c, $c ? 'Conta da mesa guardada.' : 'Mesa libertada.');
    }

    /** POST /sessoes/{s}/mesas/{m}/cobrar — emite a factura-recibo da mesa e fecha a conta. */
    public function cobrar(CobrarRequest $r, int $sessao, int $mesa): JsonResponse
    {
        $d = $r->validated();
        $this->exigirDesconto($d);
        $v = $this->mesas->cobrar($sessao, $mesa, $d);

        return RespostaApi::criado(new VendaPOSResource($v), "Venda {$v->numero_documento} registada. Troco: ".number_format((float) $v->pos_troco, 2, ',', ' ').' Kz.');
    }

    /** Desconto global ou preço diferente do catálogo exigem pos_desconto (como na venda POS). */
    private function exigirDesconto(array $d): void
    {
        $precos = Produto::query()->whereIn('id', array_column($d['linhas'] ?? [], 'produto_id'))->pluck('preco_unitario', 'id');
        $alterado = (float) ($d['percentagem_desconto'] ?? 0) > 0 || collect($d['linhas'] ?? [])->contains(fn ($l) => isset($l['preco_unitario'])
            && bccomp(number_format((float) $l['preco_unitario'], 2, '.', ''), number_format((float) ($precos[$l['produto_id']] ?? 0), 2, '.', ''), 2) !== 0);
        if ($alterado) {
            $this->exigir('pos_desconto');
        }
    }
}
