<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Services\Sistema\ServicoImagensPublicas;
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
        $logo = DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->value('valor');
        $nomeSistema = DB::table('configuracoes_sistema')->where('chave', 'sistema_nome')->value('valor') ?: 'CONSULVOLT ERP';

        return RespostaApi::sucesso([
            'logotipo' => $logo,
            'nome_sistema' => $nomeSistema,
        ], 'Logótipo do sistema.');
    }

    /**
     * PUT /api/sistema/configuracoes/logotipo-login — {logotipo: data URI | null}.
     * Com o Cloudflare R2 configurado (ServicoImagensPublicas), a imagem vai para o disco público e a base guarda só o URL.
     */
    public function gravarLogotipoLogin(Request $r, ServicoAuditoria $auditoria, ServicoImagensPublicas $imagens): JsonResponse
    {
        if (! $r->user()->eSuperAdministrador()) {
            throw new ErroNegocio('Só o Super Administrador altera o logótipo do sistema.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        $d = $r->validate(['logotipo' => ['present', 'nullable', 'string', 'max:1500000']]);
        $anterior = DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->value('valor');
        if ($d['logotipo']) {
            ServicoGestaoEmpresas::validarLogotipo($d['logotipo']);
            $valor = ServicoImagensPublicas::activo()
                ? $imagens->guardarDataUri($d['logotipo'], 'sistema', 'logotipo-login', ServicoGestaoEmpresas::TAMANHO_MAXIMO_LOGOTIPO)
                : $d['logotipo'];
            DB::table('configuracoes_sistema')->updateOrInsert(['chave' => self::CHAVE_LOGOTIPO], ['valor' => $valor, 'atualizado_em' => now()]);
        } else {
            $valor = null;
            DB::table('configuracoes_sistema')->where('chave', self::CHAVE_LOGOTIPO)->delete();
        }
        if (ServicoImagensPublicas::activo() && $anterior && $anterior !== $valor) {
            $imagens->apagarPorUrl($anterior);
        }
        $auditoria->registar('Sistema/Configurações', 'Logótipo do sistema', $d['logotipo'] ? 'Logótipo do ecrã de entrada actualizado.' : 'Logótipo do ecrã de entrada removido.');

        return RespostaApi::sucesso(null, 'Logótipo do sistema actualizado.');
    }
}
