<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\AnexoComprovativoPagamento;
use App\Models\TransacaoGateway;
use App\Services\Pagamentos\ServicoComprovativosPagamento;
use App\Services\Sistema\ServicoLicenca;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Backoffice de transferências bancárias da subscrição SaaS (motor de precificação, Módulo C/D): o super-
 * administrador da plataforma vê os comprovativos anexados por qualquer cliente (entre tenants — por isso em
 * `DB::table`, nunca pelo âmbito automático de `PertenceEmpresa`) e confirma o pagamento com um único clique, o que
 * já desencadeia a emissão automática da factura AGT (ServicoLicenca::confirmarPagamento → ServicoFaturacaoSaas).
 */
final class TransferenciasBancariasController extends Controller
{
    public function __construct(
        private readonly ServicoLicenca $licenca,
        private readonly ServicoComprovativosPagamento $comprovativos,
        private readonly ContextoEmpresa $contexto,
    ) {}

    /** GET /api/plataforma/transferencias/pendentes */
    public function pendentes(): JsonResponse
    {
        $linhas = DB::table('transacoes_gateway as t')
            ->join('subscricoes_empresa as s', 's.id', '=', 't.subscricao_id')
            ->join('empresas as e', 'e.id', '=', 's.empresa_id')
            ->leftJoin('tenants as tn', 'tn.id', '=', 'e.tenant_id')
            ->where('t.gateway', TransacaoGateway::GATEWAY_MANUAL)->where('t.estado', TransacaoGateway::ESTADO_PENDENTE)
            ->where('s.estado', 'PENDENTE')
            ->orderByDesc('t.criado_em')
            ->get([
                's.id as subscricao_id', 's.referencia', 's.valor', 's.moeda', 's.meses',
                'e.id as empresa_id', 'e.nome as empresa_nome', 'tn.nome as tenant_nome',
                't.id as transacao_id', 't.criado_em as transacao_criado_em',
            ])
            ->map(function ($l) {
                // O cliente pode reenviar o comprovativo (o pedido continua pendente até o super-admin confirmar);
                // mostra-se sempre o mais recente — nunca uma soma/junção, para não duplicar a linha por pedido.
                $anexo = DB::table('anexos_comprovativo_pagamento')->where('transacao_id', $l->transacao_id)->orderByDesc('id')->first();

                return [
                    'subscricao_id' => (int) $l->subscricao_id, 'referencia' => $l->referencia, 'valor' => (float) $l->valor, 'moeda' => $l->moeda, 'meses' => (int) $l->meses,
                    'empresa_id' => (int) $l->empresa_id, 'empresa_nome' => $l->empresa_nome, 'tenant_nome' => $l->tenant_nome,
                    'transacao_id' => (int) $l->transacao_id, 'pedido_em' => $l->transacao_criado_em,
                    'anexo_id' => $anexo?->id, 'anexo_nome' => $anexo?->nome_original, 'anexo_em' => $anexo?->criado_em,
                ];
            })->values()->all();

        return RespostaApi::sucesso($linhas, 'Transferências bancárias pendentes obtidas com sucesso.');
    }

    /** GET /api/plataforma/transferencias/comprovativo/{anexo} — entre tenants, por isso sem o âmbito de PertenceEmpresa. */
    public function comprovativo(int $anexo): Response
    {
        $a = $this->contexto->semIsolamento(fn () => AnexoComprovativoPagamento::query()->findOrFail($anexo));
        $f = $this->comprovativos->conteudo($a) ?? throw new NotFoundHttpException('Comprovativo não encontrado.');

        return response($f['conteudo'], 200, [
            'Content-Type' => $f['mime'], 'Content-Disposition' => 'inline; filename="comprovativo-'.$a->id.'"',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /** POST /api/plataforma/transferencias/{subscricao}/confirmar */
    public function confirmar(Request $request, int $subscricao): JsonResponse
    {
        $empresaId = (int) DB::table('subscricoes_empresa')->where('id', $subscricao)->value('empresa_id');
        if (! $empresaId) {
            throw new NotFoundHttpException('Pedido de subscrição não encontrado.');
        }
        $d = $request->validate(['notas' => ['nullable', 'string', 'max:1000']]);

        $resultado = $this->contexto->executarComo($empresaId, fn () => $this->licenca->confirmarPagamento($subscricao, 'TRANSFERENCIA', $d['notas'] ?? null, $request->user()));

        return RespostaApi::sucesso($resultado, 'Pagamento confirmado. Licença prolongada e factura emitida (se configurada).');
    }
}
