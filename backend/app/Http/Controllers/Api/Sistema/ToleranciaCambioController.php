<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoCambios;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tolerância do câmbio manual face ao câmbio do dia (decisão 9 do utilizador): GET para quem consulta as moedas ou emite
 * documentos com câmbio (o ecrã de emissão mostra o aviso antes de gravar), PUT para quem gere moedas e câmbios.
 * POST /validar — pré-verificação de um câmbio manual (não grava nada): referência, desvio e se exige a permissão.
 */
final class ToleranciaCambioController extends Controller
{
    public function __construct(private readonly ServicoCambios $cambios) {}

    public function show(Request $request): JsonResponse
    {
        $this->exigir('config_moedas_view', 'vendas_fat_emitir', 'compras_new_proposal', 'compras_enc_criar', 'compras_fact_registar', 'teso_doc_emitir');

        return RespostaApi::sucesso([
            'tolerancia_pct' => $this->cambios->tolerancia(),
            'pode_exceder' => $request->user()->can(ServicoCambios::PERMISSAO_FORA_TOLERANCIA),
        ], 'Tolerância do câmbio manual.');
    }

    public function update(Request $request): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $request->validate(['tolerancia_pct' => ['required', 'numeric', 'min:0', 'max:100']], [], ['tolerancia_pct' => 'tolerância']);

        return RespostaApi::sucesso(['tolerancia_pct' => $this->cambios->definirTolerancia($d['tolerancia_pct'])], 'Tolerância do câmbio manual actualizada.');
    }

    /** Pré-verificação (sem gravar e sem auditoria): o ecrã avisa antes de emitir. */
    public function validar(Request $request, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_moedas_view', 'vendas_fat_emitir', 'compras_new_proposal', 'compras_enc_criar', 'compras_fact_registar', 'teso_doc_emitir');
        $d = $request->validate(['codigo_moeda' => ['required', 'string', 'regex:/^[A-Z]{3}$/'], 'data' => ['required', 'date_format:Y-m-d'],
            'taxa_cambio' => ['required', 'numeric', 'gt:0']]);
        $tol = $this->cambios->tolerancia();
        $ref = $this->cambios->obter($contexto->obrigatorio(), $d['codigo_moeda'], $d['data']);
        if (! $ref) {
            return RespostaApi::sucesso(['referencia' => null, 'desvio_pct' => null, 'tolerancia_pct' => $tol, 'fora_tolerancia' => false,
                'pode_exceder' => $request->user()->can(ServicoCambios::PERMISSAO_FORA_TOLERANCIA)], 'Sem câmbio de referência registado.');
        }
        $desvio = bcmul(bcdiv(bcsub(number_format((float) $d['taxa_cambio'], 6, '.', ''), $ref['taxa'], 8), $ref['taxa'], 8), '100', 4);

        return RespostaApi::sucesso([
            'referencia' => $ref['taxa'], 'data_referencia' => $ref['data'], 'desvio_pct' => number_format((float) $desvio, 2, '.', ''),
            'tolerancia_pct' => $tol, 'fora_tolerancia' => bccomp(ltrim($desvio, '-'), $tol, 4) > 0,
            'pode_exceder' => $request->user()->can(ServicoCambios::PERMISSAO_FORA_TOLERANCIA),
        ], 'Câmbio manual verificado.');
    }
}
