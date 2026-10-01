<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Services\Sistema\ServicoMigracaoDados;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/sistema/migracao — Centro de migração de dados (modelos e importação em massa; cada entidade exige a tarefa do
 * seu módulo) e edição em massa de clientes, fornecedores e produtos.
 */
final class MigracaoDadosController extends Controller
{
    public function __construct(private readonly ServicoMigracaoDados $migracao) {}

    /** GET .../modelos — entidades importáveis, colunas e instruções (só as que o utilizador pode importar). */
    public function modelos(Request $r): JsonResponse
    {
        $this->exigir('config_migracao_view', ...array_column(ServicoMigracaoDados::ENTIDADES, 'permissao'));
        $lista = array_values(array_filter($this->migracao->modelos(), fn ($m) => $r->user()->can($m['permissao'])));

        return RespostaApi::sucesso($lista, 'Modelos de importação.');
    }

    /** GET .../modelos/{entidade} — modelo Excel com a folha de instruções. */
    public function modeloExcel(string $entidade): BinaryFileResponse
    {
        $this->exigir($this->permissao($entidade));
        $pasta = storage_path('app/copias');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }
        $caminho = $pasta.'/modelo_'.Str::random(12).'.xlsx';
        $this->migracao->modeloExcel($entidade, $caminho);

        return response()->download($caminho, 'Template_'.$entidade.'.xlsx')->deleteFileAfterSend();
    }

    /**
     * POST .../importar/{entidade} — decisão IGNORAR|ACTUALIZAR, simular e conta_omissao, com as linhas num de dois formatos:
     *  - JSON `linhas` (objectos com os cabeçalhos do modelo), como até aqui;
     *  - multipart `ficheiro` (o próprio modelo XLSX preenchido, ou XLS/CSV), lido no servidor. A linha de exemplo do
     *    modelo, se ficar por apagar, é ignorada e contada em `linhas_exemplo_ignoradas`.
     */
    public function importar(Request $r, string $entidade, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir($this->permissao($entidade));
        $d = $r->validate(['linhas' => ['required_without:ficheiro', 'array', 'min:1', 'max:20000'], 'linhas.*' => ['array'],
            'ficheiro' => ['required_without:linhas', 'file', 'max:20480', 'extensions:xlsx,xls,csv,txt'],
            'decisao' => ['nullable', Rule::in(['IGNORAR', 'ACTUALIZAR'])],
            'simular' => ['nullable', 'boolean'], 'conta_omissao' => ['nullable', 'string', 'max:20']],
            ['ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo Excel).']);
        $simular = (bool) ($d['simular'] ?? false);
        $exemplos = 0;
        if ($r->hasFile('ficheiro')) {
            [$linhas, $exemplos] = $this->semLinhaExemplo($entidade, $folha->linhas($r->file('ficheiro')));
        } else {
            $linhas = $r->input('linhas');
        }
        $res = $this->migracao->importar($entidade, $linhas, $d['decisao'] ?? 'IGNORAR', $simular, ['conta_omissao' => $d['conta_omissao'] ?? null]);
        $res['linhas_lidas'] = count(array_filter($linhas));
        $res['linhas_exemplo_ignoradas'] = $exemplos;

        return RespostaApi::sucesso($res, $simular ? 'Simulação da importação.'
            : "Importação concluída: {$res['criados']} criado(s), {$res['actualizados']} actualizado(s), {$res['ignorados']} ignorado(s).");
    }

    /** POST .../edicao-massa/{entidade} — clientes | fornecedores | produtos. */
    public function editarEmMassa(Request $r, string $entidade): JsonResponse
    {
        abort_unless(isset(ServicoMigracaoDados::EDICAO_MASSA[$entidade]), 404);
        $this->exigir(ServicoMigracaoDados::EDICAO_MASSA[$entidade]['permissao']);
        $d = $r->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer'],
            'dados' => ['nullable', 'array'], 'dados.codigo_moeda' => ['sometimes', 'string', 'size:3'], 'dados.taxa_imposto' => ['sometimes', Rule::in([14, 7, 5, 2, 0])],
            'dados.categoria_produto_id' => ['sometimes', 'nullable', 'integer'], 'dados.movimenta_stock' => ['sometimes', 'boolean'], 'dados.e_servico' => ['sometimes', 'boolean'],
            'dados.bloqueado' => ['sometimes', 'boolean'],
            'contas' => ['nullable', 'array'], 'contas.*' => ['string', 'max:20'],
            'preco' => ['nullable', 'array'], 'preco.modo' => ['required_with:preco', Rule::in(['DEFINIR', 'PERCENTAGEM', 'SOMAR'])], 'preco.valor' => ['required_with:preco', 'numeric'],
        ]);
        if (($d['preco']['modo'] ?? null) === 'DEFINIR' && $d['preco']['valor'] < 0) {
            throw new ErroNegocio('O preço não pode ser negativo.', 'PRECO_INVALIDO', 422);
        }
        $res = $this->migracao->editarEmMassa($entidade, array_map('intval', $d['ids']), $d['dados'] ?? [], $r->input('contas', []), $d['preco'] ?? null);

        return RespostaApi::sucesso($res, "Alterações aplicadas a {$res['alterados']} registo(s).");
    }

    /**
     * Troca por [] (mantendo a numeração das linhas) as linhas iguais à linha de exemplo do modelo.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function semLinhaExemplo(string $entidade, array $linhas): array
    {
        $colunas = array_filter(ServicoMigracaoDados::ENTIDADES[$entidade]['colunas'], fn ($c) => (string) ($c[4] ?? '') !== '');
        if ($colunas === []) {
            return [$linhas, 0];
        }
        $n = 0;
        foreach ($linhas as $i => $l) {
            $igual = $l !== [];
            foreach ($colunas as $c) {
                if (! $igual) {
                    break;
                }
                $igual = trim((string) ($l[$c[0]] ?? '')) === trim((string) $c[4]);
            }
            if ($igual) {
                $linhas[$i] = [];
                $n++;
            }
        }

        return [$linhas, $n];
    }

    private function permissao(string $entidade): string
    {
        abort_unless(isset(ServicoMigracaoDados::ENTIDADES[$entidade]), 404);

        return ServicoMigracaoDados::ENTIDADES[$entidade]['permissao'];
    }
}
