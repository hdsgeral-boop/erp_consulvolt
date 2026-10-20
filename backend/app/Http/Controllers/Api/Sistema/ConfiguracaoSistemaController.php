<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identidade do sistema (logótipo e nome) mostrada no ecrã de entrada e no menu, por cliente SaaS: cada endereço
 * <slug>.<base> mostra a do seu cliente e nunca afecta os outros (colunas tenants.logotipo_sistema e nome_sistema).
 * Sem personalização: logótipo SVG e nome «MEU ERP» por omissão (no frontend). Só imagens raster até 1 MB. A escrita é
 * directa (sem o log automático do model), para não copiar a imagem para a auditoria.
 */
final class ConfiguracaoSistemaController extends Controller
{
    /** GET /api/sistema/logotipo-login — público (ecrã de entrada): a identidade do cliente do endereço. */
    public function logotipoLogin(Request $r): JsonResponse
    {
        $cliente = $this->clienteDoPedido($r);

        return RespostaApi::sucesso([
            'logotipo' => $cliente?->logotipo_sistema ?: null,
            'nome_sistema' => $cliente?->nome_sistema ?: null,
        ], 'Identidade do sistema.');
    }

    /** PUT /api/sistema/configuracoes/logotipo-login — {logotipo?: data URI | null, nome_sistema?: string | null}, no cliente do utilizador. */
    public function gravarLogotipoLogin(Request $r, ServicoAuditoria $auditoria): JsonResponse
    {
        $utilizador = $r->user();
        if (! $utilizador->eSuperAdministrador()) {
            throw new ErroNegocio('Só o Super Administrador altera a identidade do sistema.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        $cliente = $utilizador->tenant_id !== null ? Tenant::query()->find($utilizador->tenant_id) : null;
        if ($cliente === null) {
            throw new ErroNegocio('Este utilizador não pertence a nenhum cliente.', 'SEM_CLIENTE', 422);
        }
        $d = $r->validate([
            'logotipo' => ['sometimes', 'nullable', 'string', 'max:1500000'],
            'nome_sistema' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        if (! empty($d['logotipo'])) {
            ServicoGestaoEmpresas::validarLogotipo($d['logotipo']);
        }

        $alteracoes = [];
        if (array_key_exists('logotipo', $d)) {
            $alteracoes['logotipo_sistema'] = $d['logotipo'] ?: null;
        }
        if (array_key_exists('nome_sistema', $d)) {
            $alteracoes['nome_sistema'] = trim((string) $d['nome_sistema']) ?: null;
        }
        if ($alteracoes !== []) {
            $cliente->forceFill($alteracoes)->saveQuietly();
            $descricao = match (true) {
                ! array_key_exists('logotipo_sistema', $alteracoes) => 'Nome do sistema actualizado.',
                $alteracoes['logotipo_sistema'] === null => 'Logótipo do sistema removido.',
                default => 'Logótipo do sistema actualizado.',
            };
            $auditoria->registar('Sistema/Configurações', 'Identidade do sistema', "{$descricao} (cliente {$cliente->slug})");
        }

        return RespostaApi::sucesso(null, 'Identidade do sistema actualizada.');
    }

    /** Cliente do endereço (<slug>.<base> ou o da plataforma); em localhost/IP, o do utilizador com sessão, se houver. */
    private function clienteDoPedido(Request $r): ?Tenant
    {
        $dominio = DominioPedido::de($r);

        return match ($dominio->area) {
            DominioPedido::CLIENTE => $dominio->cliente(),
            DominioPedido::PLATAFORMA => Tenant::query()->find((int) config('erp.dominio.plataforma_tenant_id')),
            DominioPedido::LIVRE => ($id = $r->user('sanctum')?->tenant_id) ? Tenant::query()->find($id) : null,
            default => null,
        };
    }
}
