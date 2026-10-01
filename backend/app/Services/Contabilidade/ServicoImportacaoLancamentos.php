<?php

namespace App\Services\Contabilidade;

use App\Exceptions\ErroNegocio;
use App\Models\CentroCusto;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Models\NotaDemonstracao;
use App\Models\NotaFluxoCaixa;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use Throwable;

/**
 * Importação de lançamentos por Excel (importJournalExcel, js/ui_lancamentos.js:1811-2085; template :1811-1833).
 * Um lançamento por "N.º do documento"; colunas reconhecidas pelo nome normalizado (como o legado): Diário, Data Fiscal
 * (data do lançamento), Data Documento (data do documento original → data_lancamento), N.º do documento, Referência, Conta,
 * D-C, Valor, NIF terceiro, Nota demonstrações, Nota fluxo caixa, Unidade de Negócio, Centro de Custo, Descrição movimento, URL.
 * Notas, UN e CC procuradas por código tolerante (maiúsculas, prefixo "Nota"/"N.º", "4.10" = "4.1" se não for ambíguo).
 *
 * Correcções face ao legado:
 *   - valida TUDO antes de gravar e grava numa só transacção (o legado inseria linha a linha e, ao encontrar um diário
 *     inexistente a meio, deixava o ficheiro meio importado);
 *   - cada lançamento passa pelo ServicoLancamentos: equilíbrio em decimal exacto, só contas de movimento existentes
 *     (o legado só recusava as totalizadoras), exercício aberto, n.º de lançamento com numeração transaccional;
 *   - um documento tem de ter um só diário e uma só data fiscal (o legado misturava-os num lançamento com o diário da
 *     primeira linha); D-C só D ou C; valores > 0 lidos também no formato "1.500,50";
 *   - os códigos/NIF inexistentes são avisos que exigem confirmação (`aceitar_avisos`), como o legado; `simular` só valida.
 */
final class ServicoImportacaoLancamentos
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoExercicios $exercicios,
    ) {}

    /** @return array<string, mixed> */
    public function importar(string $ficheiro, bool $simular, bool $aceitarAvisos): array
    {
        $analise = $this->analisar($ficheiro);
        $resumo = ['documentos' => count($analise['documentos']), 'linhas' => $analise['linhas'], 'erros' => $analise['erros'], 'avisos' => $analise['avisos']];
        if ($analise['erros']) {
            throw new ErroNegocio('O ficheiro tem erros: nada foi importado.', 'IMPORTACAO_INVALIDA', 422, $resumo);
        }
        if ($simular) {
            return $resumo + ['simulacao' => true, 'lancamentos' => []];
        }
        if ($analise['avisos'] && ! $aceitarAvisos) {
            throw new ErroNegocio('Há códigos do ficheiro que não existem nesta empresa (as linhas ficam sem esse campo): confirme para importar.',
                'IMPORTACAO_COM_AVISOS', 422, $resumo);
        }

        $numeros = DB::transaction(function () use ($analise) {
            $numeros = [];
            foreach ($analise['documentos'] as $doc) {
                $criadas = $this->lancamentos->criar($doc['dados']);
                foreach ($criadas->values() as $i => $linha) {
                    $extra = array_filter(['url_documento' => $doc['extras'][$i]['url'] ?? null, 'data_lancamento' => $doc['extras'][$i]['data_lancamento'] ?? null]);
                    if ($extra) {
                        LancamentoContabil::query()->whereKey($linha->id)->update($extra);
                    }
                }
                $numeros[] = $criadas->first()->numero_lan;
            }

            return $numeros;
        });

        return $resumo + ['simulacao' => false, 'lancamentos' => $numeros];
    }

    /** @return array{documentos: list<array<string, mixed>>, linhas: int, erros: list<string>, avisos: list<string>} */
    private function analisar(string $ficheiro): array
    {
        $empresa = $this->contexto->obrigatorio();
        $folha = $this->ler($ficheiro);
        $diarios = DiarioContabil::query()->pluck('id', 'codigo')->all();
        $terceiros = Terceiro::query()->whereNotNull('nif')->pluck('id', 'nif')->all();
        $contas = PlanoConta::query()->get(['codigo', 'tipo'])->keyBy('codigo');
        $procura = [
            'demo' => $this->procura(NotaDemonstracao::query()->get(['id', 'codigo'])->all()),
            'fluxo' => $this->procura(NotaFluxoCaixa::query()->get(['id', 'codigo'])->all()),
            'un' => $this->procura(UnidadeNegocio::query()->get(['id', 'codigo'])->all()),
            'cc' => $this->procura(CentroCusto::query()->get(['id', 'codigo'])->all()),
        ];
        $rotulos = ['demo' => 'Notas às demonstrações', 'fluxo' => 'Notas de fluxo de caixa', 'un' => 'Unidades de negócio', 'cc' => 'Centros de custo'];

        $erros = [];
        $faltas = ['demo' => [], 'fluxo' => [], 'un' => [], 'cc' => [], 'nif' => []];
        $docs = [];
        foreach ($folha as $n => $row) {
            $linha = $n + 2;
            $doc = trim((string) ($row['documento'] ?? ''));
            if ($doc === '') {
                $erros[] = "Linha {$linha}: sem \"N.º do documento\" (use o template).";

                continue;
            }
            $dc = mb_strtoupper(trim((string) ($row['dc'] ?? '')));
            $valor = $this->numero($row['valor'] ?? null);
            $conta = trim((string) ($row['conta'] ?? ''));
            $diario = trim((string) ($row['diario'] ?? ''));
            $data = $this->data($row['data_fiscal'] ?? null);
            if (! in_array($dc, ['D', 'C'], true)) {
                $erros[] = "Linha {$linha}: D-C tem de ser D ou C.";
            }
            if ($valor === null || bccomp($valor, '0', 2) <= 0) {
                $erros[] = "Linha {$linha}: valor inválido.";
            }
            if (! isset($diarios[$diario])) {
                $erros[] = "Linha {$linha}: o diário \"{$diario}\" não existe (crie-o nas tabelas auxiliares).";
            }
            if (! $data) {
                $erros[] = "Linha {$linha}: data fiscal inválida.";
            } elseif ($this->exercicios->encerrado($empresa, (int) substr($data, 0, 4))) {
                $erros[] = "Linha {$linha}: o exercício de ".substr($data, 0, 4).' está encerrado.';
            }
            $c = $contas[$conta] ?? null;
            if (! $c) {
                $erros[] = "Linha {$linha}: a conta \"{$conta}\" não existe no plano de contas.";
            } elseif ($c->tipo === PlanoConta::TIPO_TOTALIZADORA) {
                $erros[] = "Linha {$linha}: a conta {$conta} é totalizadora e não pode ser movimentada.";
            }
            $ids = [];
            foreach (['demo', 'fluxo', 'un', 'cc'] as $k) {
                $v = $row[$k] ?? null;
                $ids[$k] = ($v !== null && trim((string) $v) !== '') ? $procura[$k]($v) : null;
                if ($v !== null && trim((string) $v) !== '' && $ids[$k] === null) {
                    $faltas[$k][trim((string) $v)] = true;
                }
            }
            $nif = trim((string) ($row['nif'] ?? ''));
            if ($nif !== '' && ! isset($terceiros[$nif])) {
                $faltas['nif'][$nif] = true;
            }
            $d = &$docs[$doc];
            $d ??= ['diario' => $diario, 'data' => $data, 'debito' => '0.00', 'credito' => '0.00', 'linhas' => [], 'extras' => [], 'primeira' => $linha,
                'referencia' => trim((string) ($row['referencia'] ?? '')) ?: null];
            if ($d['diario'] !== $diario || $d['data'] !== $data) {
                $erros[] = "Documento {$doc}: as linhas têm de ter o mesmo diário e a mesma data fiscal (linha {$linha}).";
            }
            if ($valor !== null && in_array($dc, ['D', 'C'], true)) {
                $d[$dc === 'D' ? 'debito' : 'credito'] = bcadd($d[$dc === 'D' ? 'debito' : 'credito'], $valor, 2);
            }
            $d['linhas'][] = ['codigo_conta' => $conta, 'tipo_dc' => $dc, 'valor' => $valor ?? '0', 'descricao' => trim((string) ($row['descricao'] ?? '')) ?: null,
                'terceiro_id' => $terceiros[$nif] ?? null, 'nota_demonstracao_id' => $ids['demo'], 'nota_fluxo_caixa_id' => $ids['fluxo'],
                'unidade_negocio_id' => $ids['un'], 'centro_custo_id' => $ids['cc']];
            $d['extras'][] = ['url' => trim((string) ($row['url'] ?? '')) ?: null, 'data_lancamento' => $this->data($row['data_documento'] ?? null)];
            unset($d);
        }

        $documentos = [];
        foreach ($docs as $numero => $d) {
            if (bccomp($d['debito'], $d['credito'], 2) !== 0) {
                $erros[] = "Documento {$numero}: desequilibrado (débito {$d['debito']}, crédito {$d['credito']}).";
            }
            if (count($d['linhas']) < 2) {
                $erros[] = "Documento {$numero}: um lançamento tem de ter pelo menos duas linhas.";
            }
            $documentos[] = ['dados' => ['diario_id' => $diarios[$d['diario']] ?? null, 'data_documento' => $d['data'], 'numero_documento' => (string) $numero,
                'referencia' => $d['referencia'], 'linhas' => $d['linhas']], 'extras' => $d['extras']];
        }
        $avisos = [];
        foreach ($faltas as $k => $codigos) {
            if ($codigos) {
                $lista = array_keys($codigos);
                $avisos[] = ($k === 'nif' ? 'Terceiros (NIF)' : $rotulos[$k]).': '.implode(', ', array_slice($lista, 0, 15)).(count($lista) > 15 ? ' e mais '.(count($lista) - 15) : '');
            }
        }

        return ['documentos' => $documentos, 'linhas' => count($folha), 'erros' => array_values(array_unique($erros)), 'avisos' => $avisos];
    }

    /** @return list<array<string, mixed>> linhas com os campos normalizados */
    private function ler(string $ficheiro): array
    {
        try {
            $folha = IOFactory::load($ficheiro)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ErroNegocio('Não foi possível ler o ficheiro (use XLSX, XLS ou CSV).', 'FICHEIRO_INVALIDO', 422);
        }
        $cab = array_map([ServicoTabelasAuxiliares::class, 'chave'], array_shift($folha) ?? []);
        $mapa = [];
        foreach ($cab as $i => $c) {
            $campo = match (true) {
                $c === 'diario' => 'diario',
                in_array($c, ['datafiscal', 'datadodocumento'], true) => 'data_fiscal',
                in_array($c, ['datadocumento', 'datadelancamento'], true) => 'data_documento',
                in_array($c, ['nododocumento', 'naododocumento', 'ndodocumento', 'numerododocumento'], true) => 'documento',
                $c === 'referencia' => 'referencia',
                $c === 'conta' => 'conta',
                $c === 'dc' => 'dc',
                $c === 'valor' => 'valor',
                $c === 'nifterceiro' => 'nif',
                (bool) preg_match('/^notas?(as|das|de|do)?demo/', $c) || $c === 'demo' => 'demo',
                (bool) preg_match('/^notas?(do|de|da)?fluxo/', $c) || str_starts_with($c, 'fluxodecaixa') || $c === 'flow' => 'fluxo',
                str_starts_with($c, 'unidadedeneg') || $c === 'un' => 'un',
                str_starts_with($c, 'centrodecusto') || $c === 'cc' => 'cc',
                str_starts_with($c, 'descri') => 'descricao',
                in_array($c, ['url', 'urldoc', 'link', 'anexo'], true) => 'url',
                default => null,
            };
            if ($campo && ! isset($mapa[$campo])) {
                $mapa[$campo] = $i;
            }
        }
        if (! isset($mapa['documento'], $mapa['conta'], $mapa['dc'], $mapa['valor'])) {
            throw new ErroNegocio('Cabeçalhos inválidos: use o template de importação de lançamentos.', 'FICHEIRO_INVALIDO', 422);
        }
        $linhas = [];
        foreach ($folha as $row) {
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $linhas[] = array_map(fn ($i) => $row[$i] ?? null, $mapa);
        }
        if (! $linhas) {
            throw new ErroNegocio('O ficheiro está vazio.', 'FICHEIRO_VAZIO', 422);
        }

        return $linhas;
    }

    /** criarProcura (js/ui_lancamentos.js:1889-1910). @param list<object> $lista */
    private function procura(array $lista): \Closure
    {
        $exacto = [];
        $numerico = [];
        foreach ($lista as $x) {
            $k = mb_strtolower(trim((string) $x->codigo));
            if ($k === '') {
                continue;
            }
            $exacto[$k] ??= $x->id;
            $n = str_replace(',', '.', $k);
            if (is_numeric($n)) {
                $numerico[(string) (float) $n][] = $x->id;
            }
        }

        return function (mixed $v) use ($exacto, $numerico): ?int {
            $k = mb_strtolower(trim((string) $v));
            if ($k === '') {
                return null;
            }
            $semPrefixo = trim((string) preg_replace('/^(nota|n\.?\s*º|nº|n\.)\s*/u', '', $k));
            foreach ([$k, $semPrefixo] as $c) {
                if (isset($exacto[$c])) {
                    return $exacto[$c];
                }
                $n = str_replace(',', '.', $c);
                if ($c !== '' && is_numeric($n) && count($numerico[(string) (float) $n] ?? []) === 1) {
                    return $numerico[(string) (float) $n][0];
                }
            }

            return null;
        };
    }

    private function numero(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return number_format(round((float) $v, 2, PHP_ROUND_HALF_UP), 2, '.', '');
        }
        $s = preg_replace('/\s+/', '', (string) $v);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, '.') && str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
        }
        $s = str_replace(',', '.', $s);

        return is_numeric($s) ? number_format(round((float) $s, 2, PHP_ROUND_HALF_UP), 2, '.', '') : null;
    }

    /** normalizeDate (js/ui_lancamentos.js:1941-1956): número de série do Excel, dd/mm/aaaa, aaaa/mm/dd ou aaaa-mm-dd. */
    private function data(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v) || (is_string($v) && is_numeric($v))) {
            return DataExcel::excelToDateTimeObject((float) $v)->format('Y-m-d');
        }
        $s = trim((string) $v);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $s, $m)) {
            $s = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})#', $s, $m)) {
            $s = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && checkdate((int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4)) ? $s : null;
    }
}
