<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoReciclagemLancamentos;
use App\Services\Contabilidade\ServicoTabelasAuxiliares;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/contabilidade/tabelas/{tabela}, /reciclagem (as unidades de negócio estão em /api/sistema/unidades-negocio) — ecrã "tabelas_aux" (js/ui_aux.js:100), ADR-055.
 * {tabela} ∈ diarios | notas-demonstracao | notas-fluxo-caixa | centros-custo. Os terceiros têm o seu próprio módulo.
 */
final class TabelasAuxiliaresController extends Controller
{
    public function __construct(
        private readonly ServicoTabelasAuxiliares $tabelas,
        private readonly ServicoReciclagemLancamentos $reciclagem,
    ) {}

    public function index(string $tabela): JsonResponse
    {
        $this->exigir('tabelas_aux_view', 'lancamentos_view', 'relatorios_contabeis_view');

        return RespostaApi::sucesso($this->tabelas->listar($tabela), 'Registos obtidos com sucesso.');
    }

    public function store(Request $request, string $tabela): JsonResponse
    {
        $this->exigir('aux_gerir');

        return RespostaApi::criado($this->tabelas->guardar($tabela, null, $this->dados($request)), 'Registo criado com sucesso.');
    }

    public function update(Request $request, string $tabela, int $id): JsonResponse
    {
        $this->exigir('aux_gerir');

        return RespostaApi::sucesso($this->tabelas->guardar($tabela, $id, $this->dados($request)), 'Registo actualizado com sucesso.');
    }

    public function destroy(string $tabela, int $id): JsonResponse
    {
        $this->exigir('aux_eliminar');
        $this->tabelas->eliminar($tabela, $id);

        return RespostaApi::sucesso(null, 'Registo eliminado com sucesso.');
    }

    public function importar(Request $request, string $tabela): JsonResponse
    {
        $this->exigir('aux_gerir');
        $d = $request->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'actualizar_existentes' => ['nullable', 'boolean']]);
        $r = $this->tabelas->importar($tabela, $request->file('ficheiro')->getRealPath(), (bool) ($d['actualizar_existentes'] ?? false));

        return RespostaApi::sucesso($r, "Importação concluída: {$r['criados']} criado(s), {$r['actualizados']} actualizado(s), {$r['ignorados']} ignorado(s).");
    }

    public function copiar(Request $request, string $tabela): JsonResponse
    {
        $this->exigir('aux_gerir');
        $d = $request->validate(['empresa_origem_id' => ['required', 'integer']], [], ['empresa_origem_id' => 'empresa de origem']);
        $r = $this->tabelas->copiarDe($tabela, (int) $d['empresa_origem_id']);

        return RespostaApi::sucesso($r, "{$r['copiados']} registo(s) copiado(s).");
    }

    public function enviar(Request $request, string $tabela, int $id): JsonResponse
    {
        $this->exigir('aux_gerir');
        $d = $request->validate(['empresa_destino_id' => ['required', 'integer'], 'substituir' => ['nullable', 'boolean']], [], ['empresa_destino_id' => 'empresa de destino']);

        return RespostaApi::sucesso($this->tabelas->enviar($tabela, $id, (int) $d['empresa_destino_id'], (bool) ($d['substituir'] ?? false)), 'Registo enviado com sucesso.');
    }

    public function sincronizarCentrosCusto(): JsonResponse
    {
        $this->exigir('aux_gerir');
        $r = $this->tabelas->sincronizarCentrosCusto();

        return RespostaApi::sucesso($r, $r['criados'] ? "{$r['criados']} centro(s) de custo importado(s) do plano de contas." : 'Todos os centros de custo já existem.');
    }

    // ---------------------------------------------------------------- Reciclagem

    public function reciclagem(): JsonResponse
    {
        $this->exigir('tabelas_aux_view', 'aux_reciclagem');

        return RespostaApi::sucesso($this->reciclagem->listar(), 'Reciclagem obtida com sucesso.');
    }

    public function restaurar(Request $request): JsonResponse
    {
        $this->exigir('aux_reciclagem');
        $r = $this->reciclagem->restaurar($this->grupos($request));

        return RespostaApi::sucesso($r, count($r).' documento(s) restaurado(s) com sucesso.');
    }

    public function eliminarReciclagem(Request $request): JsonResponse
    {
        $this->exigir('aux_reciclagem');
        $n = $this->reciclagem->eliminar($this->grupos($request));

        return RespostaApi::sucesso(['linhas' => $n], "{$n} linha(s) eliminada(s) definitivamente.");
    }

    public function esvaziarReciclagem(Request $request): JsonResponse
    {
        $this->exigir('aux_reciclagem');
        $request->validate(['confirmar' => ['required', 'accepted']], [], ['confirmar' => 'confirmação']);
        $n = $this->reciclagem->esvaziar();

        return RespostaApi::sucesso(['linhas' => $n], "Reciclagem esvaziada: {$n} linha(s) eliminada(s).");
    }

    private function dados(Request $request): array
    {
        return $request->validate(['codigo' => ['required', 'string', 'max:20'], 'descricao' => ['required', 'string', 'max:255']],
            [], ['codigo' => 'código', 'descricao' => 'descrição']);
    }

    /** @return list<array{diario_id: int, chave: string}> */
    private function grupos(Request $request): array
    {
        $d = $request->validate(['grupos' => ['required', 'array', 'min:1', 'max:500'], 'grupos.*.diario_id' => ['required', 'integer'],
            'grupos.*.chave' => ['required', 'string', 'max:100']], [], ['grupos' => 'documentos']);

        return array_values($d['grupos']);
    }
}
