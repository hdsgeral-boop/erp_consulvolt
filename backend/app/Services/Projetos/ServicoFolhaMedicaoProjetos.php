<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\RH\ServicoImportacaoRH;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoPermissoes;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Folha de medição por pessoa — modelo Excel e importação (2026-10-16, a pedido do utilizador).
 * Base no legado: folha de medição de js/projectos_metas.js:462-628 (uma linha por tarefa com meta, data e nota comuns,
 * «colar do Excel» com código/nome + quantidade, :631-680). O legado não tinha modelo nem importação de ficheiro: o
 * modelo traz as colunas, as tarefas medíveis (já filtradas pela pessoa, se escolhida) e as listas de referência
 * (tarefas e equipa, com validação de dados na coluna Executante); a importação valida linha a linha e grava tudo numa
 * transacção — uma linha com erro e nada é gravado, com o relatório (linha e motivo).
 * Regras:
 *   - linhas sem quantidade são ignoradas (o modelo vem com todas as tarefas medíveis); quantidade ≠ 0 e no limite;
 *   - tarefa pelo código ou pela designação, entre as medíveis (meta própria, sem subtarefas com meta);
 *   - executante pelo nome ou NIF de um membro da equipa do projecto; vazio = responsável directo da tarefa;
 *   - data DD/MM/AAAA ou AAAA-MM-DD, não futura (vazia = hoje);
 *   - sem duplicados: a mesma linha repetida no ficheiro conta uma vez («repetidos») e uma medição igual já registada
 *     (tarefa, data, quantidade, executante e nota) é ignorada — importar duas vezes o mesmo ficheiro não duplica;
 *   - cada medição passa pela mesma regra do registo individual (ServicoMetasProjetos::registar: projecto aberto,
 *     tarefa bloqueada, realizado nunca negativo, conclusão automática).
 */
final class ServicoFolhaMedicaoProjetos
{
    /** Colunas do modelo: [cabeçalho, campo, obrigatório, ajuda, aliases]. */
    public const COLUNAS = [
        ['Código', 'codigo', false, 'Código da tarefa (ou deixe vazio e preencha a designação)', ['codigo tarefa', 'cod']],
        ['Tarefa', 'tarefa', false, 'Designação da tarefa (usada quando não há código)', ['designacao', 'nome']],
        ['Fase', 'fase', false, 'Só referência (não é lida)', []],
        ['Unidade', 'unidade', false, 'Só referência (não é lida)', []],
        ['Meta', 'meta', false, 'Só referência (não é lida)', []],
        ['Realizado', 'realizado', false, 'Só referência (não é lida)', []],
        ['Falta', 'falta', false, 'Só referência (não é lida)', []],
        ['Executante', 'executante', false, 'Nome ou NIF de um membro da equipa (lista na folha «Equipa»); vazio = responsável da tarefa', ['pessoa', 'colaborador', 'responsavel']],
        ['Data', 'data', false, 'DD/MM/AAAA, não futura; vazia = hoje', ['data da medicao']],
        ['Quantidade', 'quantidade', true, 'Quantidade executada (negativa para corrigir). Linhas sem quantidade são ignoradas', ['qtd', 'a registar']],
        ['Nota', 'nota', false, 'Frente, turno, auto de medição… (até 500 caracteres)', ['observacoes', 'obs']],
    ];

    public function __construct(
        private readonly ServicoMetasProjetos $metas,
        private readonly ServicoEquipasProjetos $equipas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** Modelo Excel: «Medicoes» (tarefas medíveis, filtradas pela pessoa), «Como_Preencher», «Tarefas» e «Equipa». */
    public function modeloExcel(Projeto $p, string $caminho, ?int $membroId = null): void
    {
        $membros = collect($this->equipas->membros($p))->keyBy('id');
        if ($membroId !== null && ! $membros->has($membroId)) {
            throw new ErroNegocio('A pessoa escolhida não pertence à equipa do projecto.', 'EXECUTANTE_INVALIDO', 422);
        }
        $linhas = collect($this->metas->folhaMedicao($p));
        $daPessoa = $membroId !== null ? $linhas->filter(fn ($l) => in_array($membroId, $l['pessoas'], true))->values() : $linhas;
        $nome = fn (?int $id) => $id !== null && $membros->has($id) ? (string) $membros[$id]['nome'] : '';

        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Medicoes');
        $dados = [array_column(self::COLUNAS, 0)];
        foreach ($daPessoa as $l) {
            $exec = $membroId !== null ? $nome($membroId) : (count($l['pessoas']) === 1 ? $nome($l['pessoas'][0]) : '');
            $dados[] = [(string) $l['codigo'], $l['nome'], (string) $l['fase'], $l['unidade'], $l['meta'], $l['realizado'], max(0, $l['falta']), $exec, '', null, ''];
        }
        $folha->fromArray($dados, null, 'A1', true);
        $n = count($dados);
        foreach ([12, 40, 18, 10, 12, 12, 12, 30, 13, 13, 30] as $i => $largura) {
            $folha->getColumnDimensionByColumn($i + 1)->setWidth($largura);
        }
        $folha->getStyle('A1:K1')->getFont()->setBold(true);
        $folha->getStyle('H1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $folha->freezePane('A2');
        // colunas de texto: o código «1.10» não pode virar número
        $folha->getStyle('A2:A'.max(2, $n + 200))->getNumberFormat()->setFormatCode('@');
        $folha->getStyle('I2:I'.max(2, $n + 200))->getNumberFormat()->setFormatCode('dd/mm/yyyy');

        $ajuda = $livro->createSheet()->setTitle('Como_Preencher');
        $ajuda->fromArray(array_merge([['Campo', 'O que preencher', 'Obrigatório']], array_map(fn ($c) => [$c[0], $c[3], $c[2] ? 'Sim' : 'Não'], self::COLUNAS), [
            ['Projecto', trim(($p->codigo ? "{$p->codigo} · " : '').$p->nome), ''],
            ['Pessoa', $membroId !== null ? $nome($membroId).' (só as tarefas desta pessoa: as directas e as da sua área do organigrama)' : 'Todas as tarefas medíveis', ''],
            ['Nota', 'Uma linha com erro e nada é gravado: corrija o ficheiro e importe de novo. Use primeiro «Simular». Importar o mesmo ficheiro duas vezes não duplica as medições.', ''],
        ]));
        foreach (['A' => 14, 'B' => 100, 'C' => 12] as $col => $largura) {
            $ajuda->getColumnDimension($col)->setWidth($largura);
        }
        $ajuda->getStyle('A1:C1')->getFont()->setBold(true);

        $tarefas = $livro->createSheet()->setTitle('Tarefas');
        $tarefas->fromArray(array_merge([['Código', 'Tarefa', 'Fase', 'Unidade', 'Meta', 'Realizado', 'Falta', 'Responsável']],
            $linhas->map(fn ($l) => [(string) $l['codigo'], $l['nome'], (string) $l['fase'], $l['unidade'], $l['meta'], $l['realizado'], max(0, $l['falta']),
                implode(', ', array_map($nome, $l['pessoas']))])->all()), null, 'A1', true);
        $tarefas->getStyle('A1:H1')->getFont()->setBold(true);
        foreach ([12, 40, 18, 10, 12, 12, 12, 40] as $i => $largura) {
            $tarefas->getColumnDimensionByColumn($i + 1)->setWidth($largura);
        }

        $equipa = $livro->createSheet()->setTitle('Equipa');
        $equipa->fromArray(array_merge([['Nome', 'NIF', 'Papel', 'Tipo']], $membros->values()->map(fn ($m) => [$m['nome'], (string) ($m['nif'] ?? ''), (string) ($m['papel'] ?? ''), $m['tipo']])->all()),
            null, 'A1', true);
        $equipa->getStyle('A1:D1')->getFont()->setBold(true);
        foreach (['A' => 36, 'B' => 16, 'C' => 24, 'D' => 12] as $col => $largura) {
            $equipa->getColumnDimension($col)->setWidth($largura);
        }
        // lista de referência na coluna Executante (aceita também o NIF escrito à mão: aviso, não bloqueio)
        if ($membros->isNotEmpty()) {
            $v = new DataValidation;
            $v->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_INFORMATION)->setAllowBlank(true)->setShowDropDown(true)
                ->setShowErrorMessage(true)->setErrorTitle('Executante')->setError('Escolha um membro da folha «Equipa» (ou escreva o NIF).')
                ->setFormula1("'Equipa'!\$A\$2:\$A\$".($membros->count() + 1));
            $folha->setDataValidation('H2:H'.max(2, $n + 200), $v);
        }
        $livro->setActiveSheetIndex(0);
        (new Xlsx($livro))->save($caminho);
        $livro->disconnectWorksheets();
    }

    /**
     * Importa as medições do modelo. Relatório no formato das importações do RH (criados, ignorados, repetidos,
     * rejeitadas, erros, simulacao) e as medições a registar por pessoa.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array<string, mixed>
     */
    public function importar(Projeto $p, array $linhas, bool $simular): array
    {
        $r = ['novos' => 0, 'repetidos' => 0, 'rejeitadas' => [], 'criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'erros' => [], 'por_pessoa' => []];
        $medidas = collect($this->metas->folhaMedicao($p));
        $porCodigo = $medidas->filter(fn ($l) => trim((string) $l['codigo']) !== '')->groupBy(fn ($l) => mb_strtolower(trim((string) $l['codigo'])));
        $porNome = $medidas->groupBy(fn ($l) => ServicoImportacaoRH::chave($l['nome']));
        $outras = TarefaProjeto::query()->where('projeto_id', $p->id)->get(['id', 'codigo', 'nome']);
        $membros = collect($this->equipas->membros($p));
        $membroPorNome = $membros->groupBy(fn ($m) => ServicoImportacaoRH::chave($m['nome']));
        $membroPorNif = $membros->filter(fn ($m) => trim((string) ($m['nif'] ?? '')) !== '')->groupBy(fn ($m) => ServicoImportacaoRH::chave($m['nif']));
        $nomeMembro = $membros->pluck('nome', 'id');
        $existentes = [];
        foreach (TarefaProjeto::query()->where('projeto_id', $p->id)->get() as $t) {
            foreach ($t->medicoes() as $x) {
                $existentes[self::assinatura($t->id, (string) ($x['data'] ?? ''), (float) ($x['q'] ?? 0), isset($x['membro_id']) ? (int) $x['membro_id'] : null, (string) ($x['nota'] ?? ''))] = true;
            }
        }
        $hoje = now()->toDateString();
        $validas = [];
        $vistas = [];
        foreach ($linhas as $idx => $bruta) {
            $n = $idx + 2;
            $l = self::normalizar((array) $bruta);
            if ($l === null || $l['quantidade'] === '') {
                continue;   // linha vazia ou só com a referência da tarefa
            }
            $q = ServicoImportacaoRH::numero($l['quantidade']);
            $tarefa = null;
            if ($l['codigo'] !== '') {
                $c = $porCodigo[mb_strtolower($l['codigo'])] ?? collect();
                $tarefa = $c->count() === 1 ? $c->first() : null;
                $ambigua = $c->count() > 1;
            } else {
                $c = $porNome[ServicoImportacaoRH::chave($l['tarefa'])] ?? collect();
                $tarefa = $c->count() === 1 ? $c->first() : null;
                $ambigua = $c->count() > 1;
            }
            $ref = $l['codigo'] !== '' ? $l['codigo'] : $l['tarefa'];
            $existeNoProjecto = $l['codigo'] !== '' ? $outras->contains(fn ($t) => mb_strtolower(trim((string) $t->codigo)) === mb_strtolower($l['codigo']))
                : $outras->contains(fn ($t) => ServicoImportacaoRH::chave($t->nome) === ServicoImportacaoRH::chave($l['tarefa']));
            $membroId = null;
            $erroExec = null;
            if ($l['executante'] !== '') {
                $k = ServicoImportacaoRH::chave($l['executante']);
                $cand = ($membroPorNif[$k] ?? collect())->merge($membroPorNome[$k] ?? collect())->unique('id');
                if ($cand->count() === 1) {
                    $membroId = (int) $cand->first()['id'];
                } else {
                    $erroExec = $cand->isEmpty() ? "executante «{$l['executante']}» não é membro da equipa do projecto" : "executante «{$l['executante']}» ambíguo (há mais do que um membro com esse nome: use o NIF)";
                }
            } elseif ($tarefa) {
                $membroId = $tarefa['atribuido_a_id'] ? (int) $tarefa['atribuido_a_id'] : null;
            }
            $data = $l['data'] === '' ? $hoje : ServicoImportacaoRH::data($l['data']);
            $erro = match (true) {
                $ref === '' => 'indique o código ou a designação da tarefa',
                $ambigua => "tarefa «{$ref}» ambígua (há mais do que uma com essa designação: use o código)",
                ! $tarefa && $existeNoProjecto => "a tarefa «{$ref}» não aceita medição (sem meta por quantidade, ou a meta está nas subtarefas)",
                ! $tarefa => "tarefa «{$ref}» não encontrada no projecto",
                $q === null || round($q, 3) == 0.0 => 'quantidade inválida (indique um número diferente de zero)',
                abs($q) > ServicoMetasProjetos::LIMITE_QTD => 'quantidade fora do limite',
                $erroExec !== null => $erroExec,
                $data === null => "data «{$l['data']}» inválida (use DD/MM/AAAA)",
                $data > $hoje => 'a data da medição não pode ser futura',
                mb_strlen($l['nota']) > 500 => 'nota com mais de 500 caracteres',
                default => null,
            };
            if ($erro) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => $erro];

                continue;
            }
            $q = round((float) $q, 3);
            $assin = self::assinatura((int) $tarefa['id'], (string) $data, $q, $membroId, $l['nota']);
            if (isset($vistas[$assin])) {
                $r['repetidos']++;

                continue;
            }
            $vistas[$assin] = true;
            if (isset($existentes[$assin])) {
                $r['ignorados']++;   // já registada (ficheiro importado antes)

                continue;
            }
            $validas[] = ['linha' => $n, 'tarefa_id' => (int) $tarefa['id'], 'quantidade' => $q, 'data' => $data, 'nota' => $l['nota'], 'membro_id' => $membroId];
        }
        $r['novos'] = count($validas);
        if ($r['rejeitadas'] && ! $simular) {
            throw new ErroNegocio('Nada foi importado: '.count($r['rejeitadas']).' linha(s) com erro. Corrija o ficheiro e importe de novo.', 'IMPORTACAO_COM_ERROS', 422,
                ['rejeitadas' => $r['rejeitadas']]);
        }
        DB::beginTransaction();
        try {
            foreach ($validas as $v) {
                try {
                    $this->metas->registar($p, $v['tarefa_id'], $v['quantidade'], $v['data'], $v['nota'] !== '' ? $v['nota'] : null, $v['membro_id']);
                    $r['criados']++;
                    $chave = $v['membro_id'] !== null ? (string) ($nomeMembro[$v['membro_id']] ?? "#{$v['membro_id']}") : '(sem executante)';
                    $r['por_pessoa'][$chave] = ($r['por_pessoa'][$chave] ?? 0) + 1;
                } catch (ErroNegocio $e) {
                    $r['erros'][] = ['linha' => $v['linha'], 'motivo' => $e->getMessage()];
                }
            }
            if ($simular || $r['erros']) {
                DB::rollBack();
            } else {
                $this->auditoria->registar('Projectos', 'Importação da folha de medição', trim(($p->codigo ? "{$p->codigo} " : '').$p->nome).": {$r['criados']} medição(ões), "
                    ."{$r['ignorados']} já registada(s), {$r['repetidos']} repetida(s).");
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $simular && $r['erros']) {
            throw new ErroNegocio('Nada foi importado: '.count($r['erros']).' linha(s) com erro. Corrija o ficheiro e importe de novo.', 'IMPORTACAO_COM_ERROS', 422,
                ['linhas' => $r['erros'], 'rejeitadas' => $r['rejeitadas']]);
        }

        return $r + ['simulacao' => $simular];
    }

    /** Campos da linha pelos cabeçalhos do modelo (sem acentos/maiúsculas) e aliases; null se a linha está vazia. */
    private static function normalizar(array $bruta): ?array
    {
        $porNome = [];
        foreach ($bruta as $k => $v) {
            $porNome[ServicoPermissoes::norm((string) $k)] = is_string($v) ? trim($v) : $v;
        }
        $l = [];
        foreach (self::COLUNAS as [$cab, $campo, , , $aliases]) {
            $v = $porNome[ServicoPermissoes::norm($cab)] ?? $porNome[$campo] ?? null;
            foreach ($aliases as $a) {
                $v ??= $porNome[ServicoPermissoes::norm($a)] ?? null;
            }
            $l[$campo] = $v === null ? '' : trim((string) $v);
        }

        return array_filter($l, fn ($v) => $v !== '') === [] ? null : $l;
    }

    private static function assinatura(int $tarefa, string $data, float $q, ?int $membro, string $nota): string
    {
        return implode('|', [$tarefa, substr($data, 0, 10), number_format(round($q, 3), 3, '.', ''), $membro ?? '-', mb_strtolower(trim($nota))]);
    }
}
