<?php

namespace App\Services\Estrutura;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\TarefaUnidadeOrganica;
use App\Services\RH\ServicoImportacaoRH;
use App\Services\Sistema\NotificadorRH;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Services\Sistema\ServicoPermissoes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Importação Excel de entregas de um entregável (Fase K, ADR-079; `origem = EXCEL`).
 *   - modelo .xlsx com cabeçalhos, linha de exemplo, folha «Instruções» e a lista de colaboradores;
 *   - lê o ficheiro com o mesmo leitor das outras importações (ServicoLeituraFolha, ADR-064): .xlsx, .xls ou .csv;
 *   - cada linha valida com as MESMAS regras de ServicoEntregaveis::gravarExecucao (colaborador activo da empresa, quantidade > 0,
 *     data não futura, endereço http/https, entregável activo); o colaborador resolve-se por n.º (id ou INSS), NIF ou nome, sem ambiguidade;
 *   - tudo ou nada: sem `simular` só grava se NÃO houver erros, numa transacção; as entregas ficam PENDENTE (por validar), registadas
 *     pelo utilizador;
 *   - idempotente: `origem_chave = XLS|<entregável>|<colaborador>|<data>|<hash curto das observações e quantidade>`; uma linha já
 *     importada (ou repetida no ficheiro) fica DUPLICADA e é ignorada — não é erro;
 *   - quem importa: `est_editar` ou responsável da cadeia da unidade (como registar entregas, mas não basta ser o executor).
 */
final class ServicoImportacaoEntregas
{
    public const MAX_LINHAS = 2000;

    public const MODULO_AUDITORIA = ServicoEntregaveis::MODULO_AUDITORIA;

    /** Cabeçalhos do modelo, na ordem. */
    public const CABECALHOS = ['Colaborador (nº, NIF ou nome)', 'Data (AAAA-MM-DD)', 'Quantidade', 'Observações', 'Evidência (URL)'];

    public function __construct(
        private readonly ServicoEntregaveis $entregaveis,
        private readonly ServicoLeituraFolha $leitura,
        private readonly ServicoAuditoria $auditoria,
        private readonly NotificadorRH $notificador,
    ) {}

    // ───────────── Permissão ─────────────

    /** `est_editar` ou responsável da cadeia da unidade do entregável (só ser o executor não chega para importar em lote). */
    public function exigirPode(EntregavelTarefaUnidade $e): void
    {
        if (Gate::allows('est_editar') || $this->entregaveis->papelNoEntregavel($e)['responsavel']) {
            return;
        }
        throw new ErroNegocio('Só quem edita a estrutura ou o responsável da unidade importa entregas deste entregável.', 'SEM_PERMISSAO', 403);
    }

    // ───────────── Modelo ─────────────

    public function modelo(EntregavelTarefaUnidade $e, string $caminho): void
    {
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Entregas');
        $exemplo = Colaborador::query()->where('estado', 'ACTIVO')->ordenarPorNome('nome_completo')->first(['nome_completo']);
        $folha->fromArray([self::CABECALHOS, [$exemplo?->nome_completo ?? 'Nome do colaborador', now()->toDateString(), 1, 'Exemplo — apague esta linha', 'https://exemplo.ao/evidencia']]);
        foreach (range(1, count(self::CABECALHOS)) as $i) {
            $folha->getColumnDimensionByColumn($i)->setWidth($i === 4 ? 40 : 28);
        }
        $ajuda = $livro->createSheet()->setTitle('Instruções');
        $ajuda->fromArray([
            ['Importação de entregas', "Entregável: {$e->nome} (unidade de medida: {$e->unidade})"],
            ['Colaborador', 'Número (id), NIF, número de INSS ou nome completo de um colaborador activo da empresa. Se o nome não for único, use o NIF.'],
            ['Data', 'Data da entrega, AAAA-MM-DD (ou DD/MM/AAAA). Obrigatória e não pode ser futura.'],
            ['Quantidade', 'Maior do que zero, na unidade de medida do entregável.'],
            ['Observações', 'Opcional. Duas entregas iguais (mesmo colaborador, data, quantidade e observações) contam como uma: distinga-as nas observações.'],
            ['Evidência (URL)', 'Opcional. Endereço http:// ou https:// da prova da entrega.'],
            ['Regras', 'Tudo ou nada: se alguma linha tiver erro, nada é importado. Use primeiro «Validar». As entregas importadas ficam por validar pelo responsável. Linhas já importadas são ignoradas (duplicadas).'],
            ['Nota', 'A linha de exemplo da folha «Entregas» tem de ser apagada ou substituída.'],
        ]);
        $ajuda->getColumnDimension('A')->setWidth(22);
        $ajuda->getColumnDimension('B')->setWidth(110);
        $lista = $livro->createSheet()->setTitle('Colaboradores');
        $lista->fromArray(array_merge([['N.º', 'Nome', 'NIF']], Colaborador::query()->where('estado', 'ACTIVO')->ordenarPorNome('nome_completo')->limit(5000)->get(['id', 'nome_completo', 'nif'])
            ->map(fn ($c) => [(int) $c->id, $c->nome_completo, $c->nif])->all()));
        $livro->setActiveSheetIndex(0);
        (new Xlsx($livro))->save($caminho);
    }

    // ───────────── Importação ─────────────

    /**
     * @return array{linhas: list<array<string, mixed>>, resumo: array{total: int, validas: int, erros: int, duplicadas: int}, simulacao: bool, importadas: int}
     */
    public function importar(EntregavelTarefaUnidade $e, UploadedFile $ficheiro, bool $simular = true): array
    {
        $this->exigirPode($e);
        if (! $e->ativo) {
            throw new ErroNegocio('Este entregável está inactivo.', 'ENTREGAVEL_INACTIVO', 422);
        }
        $brutas = $this->leitura->linhas($ficheiro, 'Entregas');
        $colunas = $this->colunas($brutas);
        if (count(array_filter($brutas)) > self::MAX_LINHAS) {
            throw new ErroNegocio('O ficheiro tem mais de '.self::MAX_LINHAS.' linhas de dados: divida-o em ficheiros mais pequenos.', 'LIMITE_EXCEDIDO', 422);
        }
        $activos = Colaborador::query()->where('estado', 'ACTIVO')->get(['id', 'nome_completo', 'nif', 'numero_inss']);
        $hoje = now()->toDateString();
        $chavesDoFicheiro = [];
        $linhas = [];
        $validas = [];
        foreach ($brutas as $idx => $bruta) {
            if ($bruta === []) {
                continue;
            }
            $n = $idx + 2;
            $erros = [];
            $colab = $this->colaborador((string) ($bruta[$colunas['colaborador']] ?? ''), $activos, $erros);
            $dataBruta = trim((string) ($bruta[$colunas['data']] ?? ''));
            $data = ServicoImportacaoRH::data($dataBruta);
            if ($data === null) {
                $erros[] = $dataBruta === '' ? 'Indique a data da entrega (AAAA-MM-DD).' : "A data «{$dataBruta}» não é válida (use AAAA-MM-DD).";
            } elseif ($data > $hoje) {
                $erros[] = 'A data da entrega não pode ser futura.';
            }
            $q = ServicoImportacaoRH::numero($bruta[$colunas['quantidade']] ?? null);
            $q = $q === null ? null : round($q, 4);
            if ($q === null || $q <= 0) {
                $erros[] = 'A quantidade entregue tem de ser maior do que zero.';
            }
            $observacoes = trim((string) ($colunas['observacoes'] !== null ? ($bruta[$colunas['observacoes']] ?? '') : ''));
            if (mb_strlen($observacoes) > 2000) {
                $erros[] = 'As observações têm mais de 2000 caracteres.';
            }
            $url = null;
            try {
                $url = $colunas['evidencia'] !== null ? ServicoEntregaveis::normalizarUrl((string) ($bruta[$colunas['evidencia']] ?? ''), 'Evidência da entrega') : null;
            } catch (ErroNegocio $erro) {
                $erros[] = $erro->getMessage();
            }
            $estado = $erros ? 'ERRO' : 'OK';
            $chave = null;
            if (! $erros) {
                $chave = $this->chave($e, (int) $colab->id, $data, $q, $observacoes);
                if (isset($chavesDoFicheiro[$chave]) || ExecucaoEntregavel::query()->where('origem_chave', $chave)->exists()) {
                    $estado = 'DUPLICADA';
                }
                $chavesDoFicheiro[$chave] = true;
            }
            $linhas[] = ['n' => $n, 'estado' => $estado, 'erros' => $erros, 'colaborador' => isset($colab) && $colab ? ['id' => (int) $colab->id, 'nome' => (string) $colab->nome_completo] : null,
                'data' => $data, 'quantidade' => $q];
            if ($estado === 'OK') {
                $validas[] = ['colaborador_id' => (int) $colab->id, 'quantidade' => $q, 'data' => $data, 'observacoes' => $observacoes ?: null, 'url_evidencia' => $url, 'origem_chave' => $chave];
            }
            unset($colab);
        }
        if ($linhas === []) {
            throw new ErroNegocio('O ficheiro não tem linhas de dados.', 'FICHEIRO_VAZIO', 422);
        }
        $resumo = ['total' => count($linhas), 'validas' => count($validas), 'erros' => count(array_filter($linhas, fn ($l) => $l['estado'] === 'ERRO')),
            'duplicadas' => count(array_filter($linhas, fn ($l) => $l['estado'] === 'DUPLICADA'))];
        if ($simular) {
            return ['linhas' => $linhas, 'resumo' => $resumo, 'simulacao' => true, 'importadas' => 0];
        }
        if ($resumo['erros'] > 0) {
            throw new ErroNegocio("Nada foi importado: {$resumo['erros']} linha(s) com erro. Corrija o ficheiro e importe de novo.", 'IMPORTACAO_COM_ERROS', 422, ['linhas' => $linhas, 'resumo' => $resumo]);
        }
        try {
            DB::transaction(function () use ($e, $validas, $resumo) {
                foreach ($validas as $v) {
                    $this->entregaveis->gravarExecucao($e, $v, null, 'EXCEL', false);
                }
                $this->auditoria->registar(self::MODULO_AUDITORIA, 'Importar entregas (Excel)', "{$e->nome}: ".count($validas)." entrega(s) importada(s), {$resumo['duplicadas']} duplicada(s) ignorada(s)",
                    'entregaveis_tarefa_unidade', $e->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Outra importação gravou entregas iguais ao mesmo tempo: nada foi importado. Valide o ficheiro de novo.', 'IMPORTACAO_CONCORRENTE', 409);
        }
        if ($validas) {
            $this->avisar($e, $validas);
        }

        return ['linhas' => $linhas, 'resumo' => $resumo, 'simulacao' => false, 'importadas' => count($validas)];
    }

    /** Uma notificação por responsável (não uma por linha): «N entregas importadas por validar». */
    private function avisar(EntregavelTarefaUnidade $e, array $validas): void
    {
        $t = $e->tarefa ?? TarefaUnidadeOrganica::query()->find($e->tarefa_unidade_id);
        if (! $t) {
            return;
        }
        $executores = array_column($validas, 'colaborador_id');
        $quem = $this->entregaveis->validadoresDe((int) $t->unidade_organica_id, [$this->entregaveis->colaboradorDe()]);
        foreach ($quem as $responsavel) {
            // quem também é executor de alguma linha não valida essas: aviso só com as que pode validar
            $n = count(array_filter($executores, fn ($c) => (int) $c !== (int) $responsavel));
            if ($n > 0) {
                $this->notificador->paraColaborador((int) $responsavel, 'Entregas importadas por validar', "Foram importadas {$n} entrega(s) de «{$e->nome}» por Excel e aguardam a sua validação.", '/m/rh/rh_portal', 'estrutura', 'AVISO');
            }
        }
    }

    // ───────────── Apoio ─────────────

    /** @return array{colaborador: string, data: string, quantidade: string, observacoes: ?string, evidencia: ?string} chaves dos cabeçalhos do ficheiro */
    private function colunas(array $linhas): array
    {
        $chaves = [];
        foreach ($linhas as $l) {
            foreach (array_keys($l) as $k) {
                $chaves[(string) $k] = true;
            }
        }
        $procurar = function (string $prefixo) use ($chaves): ?string {
            foreach (array_keys($chaves) as $k) {
                if (str_starts_with(ServicoPermissoes::norm($k), $prefixo)) {
                    return $k;
                }
            }

            return null;
        };
        $c = ['colaborador' => $procurar('colaborador'), 'data' => $procurar('data'), 'quantidade' => $procurar('quantidade'), 'observacoes' => $procurar('observ'), 'evidencia' => $procurar('evidencia')];
        $falta = array_filter(['Colaborador' => $c['colaborador'], 'Data' => $c['data'], 'Quantidade' => $c['quantidade']], fn ($v) => $v === null);
        if ($falta) {
            throw new ErroNegocio('Faltam os cabeçalhos: '.implode(', ', array_keys($falta)).'. Use o modelo descarregável.', 'CABECALHOS_EM_FALTA', 422, ['em_falta' => array_keys($falta)]);
        }

        return $c;
    }

    /** O colaborador activo por n.º (id ou INSS), NIF ou nome, sem ambiguidade; acrescenta o erro e devolve null se não resolver. */
    private function colaborador(string $valor, $activos, array &$erros): ?Colaborador
    {
        $v = trim($valor);
        if ($v === '') {
            $erros[] = 'Indique o colaborador (n.º, NIF ou nome).';

            return null;
        }
        $nv = ServicoPermissoes::norm($v);
        $achados = $activos->filter(fn ($c) => (string) $c->id === $v || (string) $c->nif === $v || ($c->numero_inss !== null && (string) $c->numero_inss === $v) || ServicoPermissoes::norm((string) $c->nome_completo) === $nv)->unique('id')->values();
        if ($achados->isEmpty()) {
            $erros[] = "Colaborador «{$v}» não encontrado entre os colaboradores activos da empresa.";

            return null;
        }
        if ($achados->count() > 1) {
            $erros[] = "Colaborador «{$v}» é ambíguo ({$achados->count()} correspondências): use o NIF ou o n.º.";

            return null;
        }

        return $achados->first();
    }

    private function chave(EntregavelTarefaUnidade $e, int $colaborador, string $data, float $quantidade, string $observacoes): string
    {
        $hash = substr(sha1(mb_strtolower($observacoes).'|'.number_format($quantidade, 4, '.', '')), 0, 10);

        return "XLS|{$e->id}|{$colaborador}|{$data}|{$hash}";
    }
}
