<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoCopiaEmpresa;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/sistema/copias — Configurações › Geral: cópia de segurança por empresa (config_backup), importação para
 * empresa nova/vazia e clonagem da estrutura (config_ferramentas; criar empresa exige também config_empresas_gerir).
 * Os ficheiros temporários ficam em storage/app/copias e são apagados depois de enviados/lidos.
 */
final class CopiaEmpresaController extends Controller
{
    public function __construct(
        private readonly ServicoCopiaEmpresa $copias,
        private readonly ServicoEmpresas $empresas,
    ) {}

    /** GET /api/sistema/copias/exportar — descarrega a cópia da empresa activa (JSON). */
    public function exportar(ContextoEmpresa $contexto): BinaryFileResponse
    {
        $this->exigir('config_backup');
        $empresa = $contexto->obrigatorio();
        $caminho = $this->pastaTemporaria().'/copia_'.$empresa.'_'.Str::random(12).'.json';
        $this->copias->exportar($empresa, $caminho);

        return response()->download($caminho, 'copia_empresa_'.$empresa.'_'.now()->format('Y-m-d_His').'.json', ['Content-Type' => 'application/json'])
            ->deleteFileAfterSend();
    }

    /** POST /api/sistema/copias/importar — ficheiro + (empresa_destino_id vazia | nome/nif da nova empresa) + simular. */
    public function importar(Request $r): JsonResponse
    {
        $this->exigir('config_ferramentas');
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:512000'], 'empresa_destino_id' => ['nullable', 'integer'], 'nome' => ['nullable', 'string', 'max:255'],
            'nif' => ['nullable', 'string', 'max:30'], 'simular' => ['nullable', 'boolean']]);
        if (empty($d['empresa_destino_id']) && ! Gate::allows('config_empresas_gerir')) {
            throw new ErroNegocio('Importar para uma empresa nova exige também a permissão de gerir empresas.', 'SEM_PERMISSAO', 403);
        }
        $caminho = $this->pastaTemporaria().'/importar_'.Str::random(16).'.json';
        $r->file('ficheiro')->move(dirname($caminho), basename($caminho));
        try {
            $simular = (bool) ($d['simular'] ?? false);
            $res = $this->copias->importar($caminho, isset($d['empresa_destino_id']) ? (int) $d['empresa_destino_id'] : null,
                ['nome' => $d['nome'] ?? null, 'nif' => $d['nif'] ?? null], $r->user(), $simular);
        } finally {
            @unlink($caminho);
        }

        return $simular ? RespostaApi::sucesso($res, 'Cópia válida: simulação da importação.')
            : RespostaApi::criado($res, "Cópia importada para a empresa #{$res['empresa_destino']['id']}.");
    }

    /** POST /api/sistema/copias/clonar — estrutura da empresa activa (ou de empresa_origem_id) para uma empresa nova. */
    public function clonar(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_ferramentas');
        $this->exigir('config_empresas_gerir');
        $d = $r->validate(['empresa_origem_id' => ['nullable', 'integer'], 'nome' => ['required', 'string', 'max:255'], 'nif' => ['required', 'string', 'max:30'],
            'simular' => ['nullable', 'boolean']]);
        $origem = (int) ($d['empresa_origem_id'] ?? $contexto->obrigatorio());
        if (! $this->empresas->podeAceder($r->user(), $origem)) {
            throw new ErroNegocio('Empresa de origem não encontrada.', 'NAO_ENCONTRADO', 404);
        }
        $simular = (bool) ($d['simular'] ?? false);
        $res = $this->copias->clonar($origem, ['nome' => $d['nome'], 'nif' => $d['nif']], $r->user(), $simular);

        return $simular ? RespostaApi::sucesso($res, 'Simulação da clonagem.') : RespostaApi::criado($res, "Estrutura clonada para a empresa #{$res['empresa_destino']['id']}.");
    }

    private function pastaTemporaria(): string
    {
        $pasta = storage_path('app/copias');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }

        return $pasta;
    }
}
