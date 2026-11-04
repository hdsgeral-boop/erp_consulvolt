<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Services\Sistema\ServicoImagensPublicas;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Logótipo do sistema mostrado no ecrã de entrada (config_geral, só Super Administrador — handleSystemLogoUpload,
 * js/app_v2.js:8302-8316; chave "login_logo" de configuracoes_sistema). Correcção: só imagens raster até 1 MB (o legado
 * aceitava qualquer ficheiro). A leitura pública (antes de iniciar sessão) é logotipoLogin, a registar fora do grupo
 * autenticado. A escrita é directa (sem o log automático do model), para não copiar a imagem para a auditoria.
 */
final class ConfiguracaoSistemaController extends Controller
{
    public const CHAVE_LOGOTIPO = 'login_logo';

    /**
     * GET /api/sistema/logotipo-login — público (ecrã de entrada). Identidade do cliente do endereço (<slug>.<base>, a
     * plataforma ou, em localhost/IP, o cliente do utilizador com sessão): o logótipo e o nome do cliente, se os tiver;
     * senão a configuração global (`configuracoes_sistema`) e, por fim, «CONSULVOLT ERP».
     */
    public function logotipoLogin(Request $r): JsonResponse
    {
        $cliente = $this->clienteDoPedido($r);
        $logo = $cliente?->logotipo_sistema ?: DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->value('valor');
        $nomeSistema = $cliente?->nome_sistema
            ?: DB::table('configuracoes_sistema')->where('chave', 'sistema_nome')->value('valor') ?: 'CONSULVOLT ERP';

        return RespostaApi::sucesso([
            'logotipo' => $logo ?: null,
            'nome_sistema' => $nomeSistema,
        ], 'Logótipo do sistema.');
    }

    /**
     * PUT /api/sistema/configuracoes/logotipo-login — {logotipo?: data URI | URL | null, nome_sistema?: string | null}.
     * Grava na identidade do cliente do utilizador (`tenants.logotipo_sistema` / `nome_sistema`); no cliente que opera a
     * plataforma mantém também a configuração global (`login_logo`), que serve de reserva aos restantes endereços.
     * Com o Cloudflare R2 configurado (ServicoImagensPublicas), a imagem vai para o disco público e a base guarda só o URL.
     */
    public function gravarLogotipoLogin(Request $r, ServicoAuditoria $auditoria, ServicoImagensPublicas $imagens): JsonResponse
    {
        $utilizador = $r->user();
        if (! $utilizador->eSuperAdministrador()) {
            throw new ErroNegocio('Só o Super Administrador altera a identidade do sistema.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        $d = $r->validate([
            'logotipo' => ['sometimes', 'nullable', 'string', 'max:1500000'],
            'nome_sistema' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $cliente = $utilizador->tenant_id !== null ? Tenant::query()->find($utilizador->tenant_id) : null;
        $daPlataforma = $cliente === null || (int) $cliente->id === (int) config('erp.dominio.plataforma_tenant_id');
        $alteracoes = [];

        if (array_key_exists('logotipo', $d)) {
            $anterior = $cliente?->logotipo_sistema;
            $valor = null;
            if (filled($d['logotipo'])) {
                ServicoGestaoEmpresas::validarLogotipo($d['logotipo']);
                $valor = ServicoImagensPublicas::activo()
                    ? $imagens->guardarDataUri($d['logotipo'], 'sistema', 'logotipo-login', ServicoGestaoEmpresas::TAMANHO_MAXIMO_LOGOTIPO)
                    : $d['logotipo'];
            }
            if ($cliente !== null) {
                $alteracoes['logotipo_sistema'] = $valor;
            }
            if ($daPlataforma) {
                $anteriorGlobal = DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->value('valor');
                $valor !== null
                    ? DB::table('configuracoes_sistema')->updateOrInsert(['chave' => self::CHAVE_LOGOTIPO], ['valor' => $valor, 'atualizado_em' => now()])
                    : DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->delete();
                $anterior = $anterior ?: $anteriorGlobal;
            }
            $descricao = $valor === null ? 'Logótipo do sistema removido.' : 'Logótipo do sistema actualizado.';
        }
        if (array_key_exists('nome_sistema', $d) && $cliente !== null) {
            $alteracoes['nome_sistema'] = trim((string) $d['nome_sistema']) ?: null;
            if ($daPlataforma) {   // reserva global dos endereços sem cliente próprio
                $alteracoes['nome_sistema']
                    ? DB::table('configuracoes_sistema')->updateOrInsert(['chave' => 'sistema_nome'], ['valor' => $alteracoes['nome_sistema'], 'atualizado_em' => now()])
                    : DB::table('configuracoes_sistema')->where('chave', 'sistema_nome')->delete();
            }
            $descricao ??= 'Nome do sistema actualizado.';
        }
        if ($alteracoes !== []) {
            // directa (sem o log automático do model), para não copiar a imagem para a auditoria
            $cliente->forceFill($alteracoes)->saveQuietly();
        }
        if (ServicoImagensPublicas::activo() && ! empty($anterior) && ($valor ?? null) !== $anterior) {
            $imagens->apagarPorUrl($anterior);
        }
        if (isset($descricao)) {
            $auditoria->registar('Sistema/Configurações', 'Identidade do sistema', $descricao.($cliente ? " (cliente {$cliente->slug})" : ''));
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
