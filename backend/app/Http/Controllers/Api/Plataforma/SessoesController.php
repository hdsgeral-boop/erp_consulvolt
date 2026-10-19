<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Sessões activas de todos os clientes (tokens ainda válidos) e revogação. Só a plataforma (middleware `plataforma`). */
final class SessoesController extends Controller
{
    public function index(): JsonResponse
    {
        $sessoes = DB::table('tokens_acesso as t')
            ->join('utilizadores as u', fn ($j) => $j->on('u.id', '=', 't.portador_id')->where('t.portador_tipo', '=', 'utilizador'))
            ->join('tenants as c', 'c.id', '=', 'u.tenant_id')
            ->where('t.expira_em', '>', now())
            ->orderByDesc('t.ultimo_uso_em')
            ->limit(300)
            ->get(['t.id', 't.nome as dispositivo', 't.endereco_ip', 't.ultimo_uso_em', 't.expira_em', 'u.nome_utilizador', 'c.nome as cliente']);

        return RespostaApi::sucesso($sessoes->all(), 'Sessões obtidas com sucesso.');
    }

    public function revogar(Request $r, int $id): JsonResponse
    {
        if ((int) $r->user()->currentAccessToken()?->getKey() === $id) {
            throw new ErroNegocio('Não pode terminar a sessão que está a usar; use "Sair".', 'SESSAO_PROPRIA', 422);
        }

        DB::table('tokens_acesso')->where('id', $id)->delete();

        return RespostaApi::sucesso(null, 'Sessão terminada.');
    }
}