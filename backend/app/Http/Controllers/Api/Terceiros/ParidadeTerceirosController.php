<?php

namespace App\Http\Controllers\Api\Terceiros;

use App\Http\Controllers\Controller;
use App\Services\Terceiros\ServicoTerceirosMassa;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/terceiros — «Editar em massa (N)» de clientes e fornecedores e «Importar» clientes (paridade 4.4.3 e 5.6.1 do
 * relatório de verificação visual). Mesmas permissões de gestão das fichas (TerceiroController::GERIR).
 */
final class ParidadeTerceirosController extends Controller
{
    private const GERIR = ['CLIENTE' => ['vendas_clientes_gerir', 'aux_gerir'], 'FORNECEDOR' => ['compras_forn_gerir', 'aux_gerir']];

    public function __construct(private readonly ServicoTerceirosMassa $massa) {}

    /** POST /terceiros/edicao-massa — {papel, ids[], codigo_moeda?, codigo_conta?, conta_compra_transitoria?, retirar_conta_transitoria?}. */
    public function edicaoMassa(Request $r): JsonResponse
    {
        $d = $r->validate([
            'papel' => ['required', Rule::in(['CLIENTE', 'FORNECEDOR'])],
            'ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['integer', 'distinct'],
            'codigo_moeda' => ['nullable', 'string', Rule::exists('moedas', 'codigo')->whereNull('eliminado_em')],
            'codigo_conta' => ['nullable', 'string', 'max:20'],
            'conta_compra_transitoria' => ['nullable', 'string', 'max:20'],
            'retirar_conta_transitoria' => ['nullable', 'boolean'],
        ], [], ['codigo_moeda' => 'moeda', 'codigo_conta' => 'conta de terceiro', 'conta_compra_transitoria' => 'conta de compras / trânsito']);
        $this->exigir(...self::GERIR[$d['papel']]);
        $res = $this->massa->editar($d['papel'], array_map('intval', $d['ids']), $d);

        return RespostaApi::sucesso($res, "{$res['actualizados']} ficha(s) actualizada(s).");
    }

    /** GET /terceiros/importacao/modelo — Template_Clientes_Vendas.xlsx. */
    public function modeloImportacao(): BinaryFileResponse
    {
        $this->exigir(...self::GERIR['CLIENTE']);

        return response()->download($this->massa->modelo(), 'Template_Clientes_Vendas.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** POST /terceiros/importacao — {ficheiro, simular (omissão: sim), actualizar_existentes}. */
    public function importar(Request $r): JsonResponse
    {
        $this->exigir(...self::GERIR['CLIENTE']);
        $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean'],
            'actualizar_existentes' => ['nullable', 'boolean']], [], ['ficheiro' => 'ficheiro']);
        $simular = $r->boolean('simular', true);
        $res = $this->massa->importarClientes($r->file('ficheiro')->getRealPath(), $simular, $r->boolean('actualizar_existentes'));

        return RespostaApi::sucesso($res, ($simular ? 'Simulação: ' : '')."{$res['criados']} criado(s), {$res['actualizados']} actualizado(s), "
            ."{$res['ignorados']} já existente(s), ".count($res['erros']).' com erro.'.($simular ? ' Nada foi gravado.' : ''));
    }
}
