<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/sistema/identidade — identidade da empresa activa (nome, NIF, contactos, rodapé e logótipo) para a barra do
 * menu e para o cabeçalho das impressões/PDF. Qualquer utilizador com acesso à empresa a pode ler (dados que já
 * constam dos documentos emitidos); a gestão continua em /sistema/gestao-empresas.
 */
final class IdentidadeEmpresaController extends Controller
{
    public function __invoke(ContextoEmpresa $contexto): JsonResponse
    {
        $e = DB::table('empresas')->where('id', $contexto->obrigatorio())
            ->first(['id', 'tenant_id', 'nome', 'nif', 'endereco', 'provincia', 'municipio', 'telefone', 'email', 'website', 'numero_registo_comercial', 'rodape_documento', 'logotipo']);
        abort_if($e === null, 404);
        $morada = implode(', ', array_filter([trim((string) $e->endereco), trim((string) $e->municipio), trim((string) $e->provincia)]));
        $nomeSistema = ! empty($e->tenant_id)
            ? DB::table('tenants')->where('id', $e->tenant_id)->value('nome_sistema')
            : null;

        return RespostaApi::sucesso([
            'id' => (int) $e->id, 'nome' => (string) $e->nome, 'nif' => $e->nif, 'morada' => $morada !== '' ? $morada : null,
            'telefone' => $e->telefone, 'email' => $e->email, 'website' => $e->website, 'registo_comercial' => $e->numero_registo_comercial,
            'rodape' => $e->rodape_documento,
            'nome_sistema' => $nomeSistema ?: null,
            // só imagens data URI (validadas na gravação por ServicoGestaoEmpresas::validarLogotipo)
            'logotipo' => is_string($e->logotipo) && str_starts_with($e->logotipo, 'data:image/') ? $e->logotipo : null,
        ], 'Identidade da empresa activa.');
    }
}
