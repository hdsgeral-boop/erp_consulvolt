<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Services\Sistema\ParidadeConfigPlanoContas;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/config/plano-contas/* — paridade do ecrã «Plano de Contas» com o sistema anterior (verificação visual de
 * 2026-10-08, linha 14.3.1): coluna «Uso», «Copiar de outra empresa» e «Importar Excel». O modelo («Baixar Template»)
 * reutiliza GET /api/sistema/migracao/modelos/plano_contas.
 * Todas as escritas são só na empresa activa e exigem a tarefa «Criar, editar e importar contas» (contab_plano_gerir).
 */
final class ParidadeConfigController extends Controller
{
    /** Ficheiro de importação: até 5 MB, só XLSX/XLS/CSV (OWASP A04/A05 — limite de tamanho e de tipo). */
    private const MAX_KB = 5120;

    public function __construct(private readonly ParidadeConfigPlanoContas $plano) {}

    /** GET /config/plano-contas/uso — códigos das contas com lançamentos (coluna «Uso» = «Em Uso»). */
    public function uso(): JsonResponse
    {
        $this->exigir('config_plano_view', 'contab_plano_gerir', 'lancamentos_view');
        $codigos = $this->plano->contasEmUso();

        return RespostaApi::sucesso($codigos, 'Contas em uso.', 200, ['total' => count($codigos)]);
    }

    /** POST /config/plano-contas/copiar/simular — {empresa_origem_id}: pré-visualização (nada é gravado). */
    public function simularCopia(Request $r): JsonResponse
    {
        $this->exigir('contab_plano_gerir');
        $d = $r->validate(['empresa_origem_id' => ['required', 'integer', 'min:1']], [], ['empresa_origem_id' => 'empresa de origem']);

        return RespostaApi::sucesso($this->plano->simularCopia((int) $d['empresa_origem_id']), 'Pré-visualização da cópia.');
    }

    /** POST /config/plano-contas/copiar — {empresa_origem_id, confirmar: true}: copia só as contas que faltam. */
    public function copiar(Request $r): JsonResponse
    {
        $this->exigir('contab_plano_gerir');
        $d = $r->validate(['empresa_origem_id' => ['required', 'integer', 'min:1'], 'confirmar' => ['required', 'accepted']],
            ['confirmar.accepted' => 'Confirme a cópia das contas.', 'confirmar.required' => 'Confirme a cópia das contas.'], ['empresa_origem_id' => 'empresa de origem']);
        $res = $this->plano->copiar((int) $d['empresa_origem_id']);

        return RespostaApi::sucesso($res, $res['copiadas'] ? "{$res['copiadas']} conta(s) copiada(s); {$res['existentes']} já existia(m) e foi(ram) mantida(s)."
            : 'Todas as contas da empresa de origem já existem na empresa activa.');
    }

    /** POST /config/plano-contas/importar — multipart ficheiro (+ simular): valida cada linha; só cria contas novas. */
    public function importar(Request $r, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir('contab_plano_gerir');
        $d = $r->validate([
            'ficheiro' => ['required', 'file', 'max:'.self::MAX_KB, 'extensions:xlsx,xls,csv'],
            'simular' => ['nullable', 'boolean'],
        ], [
            'ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo «Baixar Template»).',
            'ficheiro.max' => 'O ficheiro não pode ter mais de 5 MB.',
        ]);
        $simular = (bool) ($d['simular'] ?? false);
        $res = $this->plano->importar($folha->linhas($r->file('ficheiro')), $simular);

        return RespostaApi::sucesso($res, $simular
            ? ($res['erros'] ? count($res['erros']).' linha(s) com erro: corrija o ficheiro.' : "Simulação: {$res['novas']} conta(s) nova(s), {$res['existentes']} já existente(s) (mantidas).")
            : "Importação concluída: {$res['criadas']} conta(s) criada(s); {$res['existentes']} já existente(s) mantida(s).");
    }
}
