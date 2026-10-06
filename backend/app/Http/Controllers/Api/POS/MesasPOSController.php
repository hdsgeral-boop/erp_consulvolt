<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Models\Produto;
use App\Services\POS\ServicoMesasPOS;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * M-15 — mesas do POS restaurante (decisão 14): /api/pos/terminais/{t}/mesas, /api/pos/mesas/{m}(/conta),
 * /api/pos/sessoes/{s}/mesas/{m}/cobrar. Criar mesa: pos_terminais_gerir ou pos_venda (no legado o operador criava
 * mesas no próprio POS); alterar/eliminar: pos_terminais_gerir; contas: pos_venda (desconto/preço: pos_desconto).
 */
final class MesasPOSController extends Controller
{
    public function __construct(private readonly ServicoMesasPOS $mesas) {}

    public function index(Request $r, int $terminal): JsonResponse
    {
        $this->exigir('pos_venda', 'pos_terminais_gerir', 'pos_terminais_view', 'pos_view');
        $d = $r->validate(['incluir_inactivas' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->mesas->mesas($terminal, (bool) ($d['incluir_inactivas'] ?? false)), 'Mesas obtidas com sucesso.');
    }

    public function store(Request $r, int $terminal): JsonResponse
    {
        $this->exigir('pos_terminais_gerir', 'pos_venda');

        return RespostaApi::criado($this->mesas->guardarMesa($terminal, $this->dadosMesa($r)), 'Mesa criada.');
    }

    public function update(Request $r, int $terminal, int $mesa): JsonResponse
    {
        $this->exigir('pos_terminais_gerir');

        return RespostaApi::sucesso($this->mesas->guardarMesa($terminal, $this->dadosMesa($r), $mesa), 'Mesa actualizada.');
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
    public function gravarConta(Request $r, int $mesa): JsonResponse
    {
        $this->exigir('pos_venda');
        $d = $r->validate($this->regrasCarrinho(false) + ['versao' => ['nullable', 'integer']]);
        $this->exigirDesconto($d);
        $c = $this->mesas->gravarConta($mesa, $d);

        return RespostaApi::sucesso($c, $c ? 'Conta da mesa guardada.' : 'Mesa libertada.');
    }

    /** POST /sessoes/{s}/mesas/{m}/cobrar — emite a factura-recibo da mesa e fecha a conta. */
    public function cobrar(Request $r, int $sessao, int $mesa): JsonResponse
    {
        $this->exigir('pos_venda');
        $d = $r->validate($this->regrasCarrinho(true) + [
            'pagamentos' => ['required', 'array', 'min:1', 'max:50'], 'pagamentos.*.meio_id' => ['required', 'string', 'max:40'], 'pagamentos.*.valor' => ['required', 'numeric', 'gt:0'],
            'pagamentos.*.referencia' => ['nullable', 'string', 'max:100'], 'versao' => ['nullable', 'integer'],
        ]);
        $this->exigirDesconto($d);
        $v = $this->mesas->cobrar($sessao, $mesa, $d);

        return RespostaApi::criado($v->load('itensVenda'), "Venda {$v->numero_documento} registada. Troco: ".number_format((float) $v->pos_troco, 2, ',', ' ').' Kz.');
    }

    /** @return array{nome: string, ordem?: ?int, ativo?: ?bool} */
    private function dadosMesa(Request $r): array
    {
        return $r->validate(['nome' => ['required', 'string', 'max:60'], 'ordem' => ['nullable', 'integer', 'min:0', 'max:100000'], 'ativo' => ['nullable', 'boolean']],
            [], ['nome' => 'nome da mesa']);
    }

    /** @return array<string, list<mixed>> */
    private function regrasCarrinho(bool $obrigatorio): array
    {
        $empresa = app(ContextoEmpresa::class)->obrigatorio();

        return [
            'cliente_id' => ['nullable', 'integer', Rule::exists('terceiros', 'id')->where('empresa_id', $empresa)], 'observacoes' => ['nullable', 'string', 'max:2000'],
            'percentagem_desconto' => ['nullable', 'numeric', 'between:0,100'],
            'linhas' => [$obrigatorio ? 'required' : 'present', 'array', $obrigatorio ? 'min:1' : 'min:0', 'max:1000'],
            'linhas.*.produto_id' => ['required', 'integer'], 'linhas.*.quantidade' => ['required', 'numeric', 'gt:0'],
            'linhas.*.preco_unitario' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'], 'linhas.*.descricao' => ['nullable', 'string', 'max:500'],
        ];
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
