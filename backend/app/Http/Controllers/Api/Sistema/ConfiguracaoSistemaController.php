<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Support\Api\RespostaApi;
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

    /** GET /api/sistema/logotipo-login — público (ecrã de entrada). */
    public function logotipoLogin(): JsonResponse
    {
        return RespostaApi::sucesso(['logotipo' => DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->value('valor')], 'Logótipo do sistema.');
    }

    /** PUT /api/sistema/configuracoes/logotipo-login — {logotipo: data URI | null}. */
    public function gravarLogotipoLogin(Request $r, ServicoAuditoria $auditoria): JsonResponse
    {
        if (! $r->user()->eSuperAdministrador()) {
            throw new ErroNegocio('Só o Super Administrador altera o logótipo do sistema.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        $d = $r->validate(['logotipo' => ['present', 'nullable', 'string', 'max:1500000']]);
        if ($d['logotipo']) {
            ServicoGestaoEmpresas::validarLogotipo($d['logotipo']);
            DB::table('configuracoes_sistema')->updateOrInsert(['chave' => self::CHAVE_LOGOTIPO], ['valor' => $d['logotipo'], 'atualizado_em' => now()]);
        } else {
            DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->delete();
        }
        $auditoria->registar('Sistema/Configurações', 'Logótipo do sistema', $d['logotipo'] ? 'Logótipo do ecrã de entrada actualizado.' : 'Logótipo do ecrã de entrada removido.');

        return RespostaApi::sucesso(null, 'Logótipo do sistema actualizado.');
    }
}
