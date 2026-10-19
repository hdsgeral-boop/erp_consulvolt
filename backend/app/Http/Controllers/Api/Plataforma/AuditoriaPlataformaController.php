<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Auditoria de todos os clientes (`logs_auditoria`, fora da RLS por ser imutável e transversal, ADR-009). Só leitura. */
final class AuditoriaPlataformaController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['modulo' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:100']]);
        $escapar = fn (string $t) => '%'.addcslashes($t, '%_\\').'%';

        $clientes = collect(DB::select('SELECT id, tenant_id FROM plataforma_empresas()'))->pluck('tenant_id', 'id');
        $nomes = DB::table('tenants')->pluck('nome', 'id');

        $registos = DB::table('logs_auditoria')
            ->when($d['modulo'] ?? null, fn ($q, $m) => $q->where('modulo', 'ilike', $escapar($m)))
            ->when($d['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w
                ->where('detalhes', 'ilike', $escapar($t))
                ->orWhere('nome_utilizador', 'ilike', $escapar($t))
                ->orWhere('acao', 'ilike', $escapar($t))))
            ->orderByDesc('ocorrido_em')
            ->limit(200)
            ->get(['ocorrido_em', 'nome_utilizador', 'modulo', 'acao', 'tabela', 'detalhes', 'endereco_ip', 'empresa_id'])
            ->map(function ($l) use ($clientes, $nomes) {
                $tenant = $l->empresa_id ? ($clientes[$l->empresa_id] ?? null) : null;

                return [
                    'ocorrido_em' => $l->ocorrido_em, 'utilizador' => $l->nome_utilizador,
                    'cliente' => $tenant ? ($nomes[$tenant] ?? null) : null, 'modulo' => $l->modulo, 'acao' => $l->acao,
                    'tabela' => $l->tabela, 'detalhes' => $l->detalhes, 'endereco_ip' => $l->endereco_ip,
                ];
            });

        return RespostaApi::sucesso($registos->values()->all(), 'Auditoria obtida com sucesso.');
    }
}