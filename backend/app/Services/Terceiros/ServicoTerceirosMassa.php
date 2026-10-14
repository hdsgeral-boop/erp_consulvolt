<?php

namespace App\Services\Terceiros;

use App\Exceptions\ErroNegocio;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Operações em massa sobre as fichas de clientes e fornecedores do legado:
 *   - «Editar em massa (N)» (js/mapeamento_massa.js, entidades clientes/fornecedores): moeda por omissão, conta do papel
 *     (conta_cliente classe 31 nos clientes, conta_fornecedor 32/37.0 nos fornecedores — Fase A) e, nos fornecedores, conta
 *     de compras/trânsito (328) — «Manter como está»
 *     = campo ausente; a conta de terceiro é obrigatória (não se retira), a de trânsito pode retirar-se;
 *   - «Importar» clientes (openImportModal('clientes'), js/ui_sales.js:5536-5690, Template_Clientes_Vendas.xlsx com NIF, Nome,
 *     Endereco, Email, Conta_Contábil): linhas sem conta não entram (como no legado); o existente (mesmo NIF; sem NIF, o mesmo
 *     nome) só é actualizado a pedido; repetidos no ficheiro contam uma vez; simulação por omissão (nada é gravado).
 * Cada ficha passa por ServicoTerceiros::guardar ou pelas mesmas validações (contas de movimento, NIF único, papéis).
 */
final class ServicoTerceirosMassa
{
    public const COLUNAS_CLIENTES = ['NIF', 'Nome', 'Endereco', 'Email', 'Conta_Contábil'];

    public function __construct(
        private readonly ServicoTerceiros $terceiros,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  array{codigo_moeda?: ?string, codigo_conta?: ?string, conta_compra_transitoria?: ?string, retirar_conta_transitoria?: bool}  $campos
     * @return array{actualizados: int}
     */
    public function editar(string $papel, array $ids, array $campos): array
    {
        $dados = [];
        if (! empty($campos['codigo_moeda'])) {
            $dados['codigo_moeda'] = mb_strtoupper($campos['codigo_moeda']);
        }
        if (! empty($campos['codigo_conta'])) {
            // Fase A: a «conta de terceiro» da lista é a conta do papel (mapeamento_massa.js:24-32 → account_client / account_supplier)
            $conta = (string) $campos['codigo_conta'];
            $papelConta = $papel === Terceiro::FORNECEDOR ? ContaTerceiro::FORNECEDOR : ContaTerceiro::CLIENTE;
            if (! ContaTerceiro::classeCorrecta($conta, $papelConta)) {
                throw new ErroNegocio($papelConta === ContaTerceiro::CLIENTE ? "A conta de cliente {$conta} não é da classe 31."
                    : "A conta de fornecedor {$conta} não é da classe 32 nem 37.0.", 'CONTA_CLASSE_ERRADA', 422);
            }
            $this->plano->contaDeMovimento($conta);
            $dados[$papelConta === ContaTerceiro::FORNECEDOR ? 'conta_fornecedor' : 'conta_cliente'] = $conta;
        }
        if ($papel === Terceiro::FORNECEDOR) {
            if (! empty($campos['retirar_conta_transitoria'])) {
                $dados['conta_compra_transitoria'] = null;
            } elseif (! empty($campos['conta_compra_transitoria'])) {
                $this->plano->contaDeMovimento((string) $campos['conta_compra_transitoria']);
                $dados['conta_compra_transitoria'] = (string) $campos['conta_compra_transitoria'];
            }
        }
        if (! $dados) {
            throw new ErroNegocio('Escolha pelo menos um campo a alterar.', 'SEM_ALTERACOES', 422);
        }

        return DB::transaction(function () use ($ids, $dados, $papel) {
            $fichas = Terceiro::query()->whereIn('id', $ids)->lockForUpdate()->get();   // só da empresa activa (âmbito do modelo)
            if ($fichas->count() !== count(array_unique($ids))) {
                throw new ErroNegocio('Algumas fichas seleccionadas já não existem.', 'TERCEIRO_INEXISTENTE', 422);
            }
            foreach ($fichas as $t) {
                if ($t->tipo === Terceiro::COLABORADOR) {
                    throw new ErroNegocio("{$t->nome} é colaborador: não se altera aqui.", 'TIPO_TERCEIRO_INCOMPATIVEL', 422);
                }
                $t->update($dados);
            }
            app(ServicoAuditoria::class)->registar('Terceiros', 'Edição em massa', $fichas->count().' ficha(s) de '.mb_strtolower($papel).' alterada(s): '
                .implode(', ', array_keys($dados)).'.', 'terceiros', null, null, $dados + ['ids' => $fichas->pluck('id')->all()]);

            return ['actualizados' => $fichas->count()];
        });
    }

    /** Modelo .xlsx (dados + instruções), como downloadImportTemplate('clientes'). */
    public function modelo(): string
    {
        $livro = new Spreadsheet;
        $dados = $livro->getActiveSheet()->setTitle('Clientes');
        $dados->fromArray([self::COLUNAS_CLIENTES, ['000000000XX000', 'Cliente Exemplo Lda', 'Rua Exemplo, Luanda', 'cliente@exemplo.test', '31121']]);
        $dados->getStyle('A1:E1')->getFont()->setBold(true);
        $instr = $livro->createSheet()->setTitle('Instruções');
        $instr->fromArray([['Coluna', 'Descrição', 'Exemplo'],
            ['NIF', 'Número de Identificação Fiscal do cliente', 'Ex: 000000000XX000'],
            ['Nome', 'Nome completo ou Razão Social (obrigatório)', 'Ex: Cliente Exemplo Lda'],
            ['Endereco', 'Morada do cliente', 'Ex: Rua Exemplo, Luanda'],
            ['Email', 'Endereço de email', 'Ex: cliente@exemplo.test'],
            ['Conta_Contábil', 'Conta de movimento no Plano de Contas (obrigatória — linhas sem conta não são importadas)', 'Ex: 31121']]);
        $instr->getStyle('A1:C1')->getFont()->setBold(true);
        foreach (['A', 'B', 'C'] as $c) {
            $instr->getColumnDimension($c)->setAutoSize(true);
        }
        $livro->setActiveSheetIndex(0);
        $caminho = tempnam(sys_get_temp_dir(), 'modelo_cli').'.xlsx';
        (new Xlsx($livro))->save($caminho);

        return $caminho;
    }

    /**
     * @return array{simulacao: bool, linhas_lidas: int, criados: int, actualizados: int, ignorados: int, repetidos: int, erros: list<array{linha: int, mensagem: string}>}
     */
    public function importarClientes(string $ficheiro, bool $simular = true, bool $actualizarExistentes = false): array
    {
        try {
            $linhas = IOFactory::load($ficheiro)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use o modelo .xlsx).', 'FICHEIRO_INVALIDO', 422);
        }
        if (count($linhas) < 2) {
            throw new ErroNegocio('O ficheiro está vazio.', 'FICHEIRO_VAZIO', 422);
        }
        $norm = fn ($v) => mb_strtolower(trim(str_replace([' ', '-', '.'], '_', Str::ascii((string) $v))));
        $col = [];
        foreach ($linhas[0] as $i => $v) {
            $col[$norm($v)] ??= $i;
        }
        $col['conta'] = $col['conta_contabil'] ?? $col['conta_cliente'] ?? $col['conta'] ?? null;   // Conta_Contábil (ou Conta_Cliente) → conta de cliente
        if (! isset($col['nome'])) {
            throw new ErroNegocio('Coluna obrigatória em falta: «Nome». Use o modelo de importação.', 'CABECALHO_INVALIDO', 422);
        }
        $res = ['simulacao' => $simular, 'linhas_lidas' => 0, 'criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'repetidos' => 0, 'erros' => []];
        $vistos = [];

        DB::beginTransaction();
        try {
            foreach (array_slice($linhas, 1, null, true) as $i => $l) {
                if (! array_filter($l, fn ($v) => $v !== null && trim((string) $v) !== '')) {
                    continue;
                }
                $res['linhas_lidas']++;
                $n = $i + 1;
                $v = fn (string $nome) => isset($col[$nome]) ? trim((string) ($l[$col[$nome]] ?? '')) : '';
                $nome = $v('nome');
                $nif = $v('nif');
                $conta = str_replace(['.', ' '], '', $v('conta'));
                if ($nome === '') {
                    $res['erros'][] = ['linha' => $n, 'mensagem' => "Linha {$n}: o nome é obrigatório."];

                    continue;
                }
                if ($conta === '') {
                    $res['erros'][] = ['linha' => $n, 'mensagem' => "Linha {$n}: {$nome} sem conta contabilística (não importado)."];

                    continue;
                }
                $chave = $nif !== '' ? 'nif:'.mb_strtoupper($nif) : 'nome:'.mb_strtoupper($nome);
                if (isset($vistos[$chave])) {
                    $res['repetidos']++;

                    continue;
                }
                $vistos[$chave] = true;
                $existente = $nif !== '' ? Terceiro::query()->where('nif', $nif)->first()
                    : Terceiro::query()->whereNull('nif')->whereRaw('upper(trim(nome)) = upper(?)', [$nome])->first();
                if ($existente && ! $actualizarExistentes) {
                    $res['ignorados']++;

                    continue;
                }
                $dados = ['nome' => mb_substr($nome, 0, 255), 'nif' => $nif !== '' ? mb_substr($nif, 0, 30) : null, 'conta_cliente' => mb_substr($conta, 0, 20)]
                    + array_filter(['endereco' => mb_substr($v('endereco'), 0, 1000), 'email' => filter_var($v('email'), FILTER_VALIDATE_EMAIL) ? mb_substr($v('email'), 0, 150) : null],
                        fn ($x) => $x !== null && $x !== '');
                try {
                    DB::transaction(fn () => $this->terceiros->guardar(Terceiro::CLIENTE, $dados, $existente));
                    $existente ? $res['actualizados']++ : $res['criados']++;
                } catch (ErroNegocio $e) {
                    $res['erros'][] = ['linha' => $n, 'mensagem' => "Linha {$n}: {$e->getMessage()}"];
                }
            }
            if ($simular) {
                DB::rollBack();
            } else {
                DB::commit();
                app(ServicoAuditoria::class)->registar('Vendas/Clientes', 'Importação de clientes',
                    "{$res['criados']} criado(s), {$res['actualizados']} actualizado(s), ".count($res['erros']).' com erro.', 'terceiros');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $res;
    }
}
