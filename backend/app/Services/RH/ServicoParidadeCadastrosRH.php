<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Banco;
use App\Models\CargoFuncao;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoEmpresas;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Template, «Importar Excel» e «Copiar de outra empresa» das tabelas de suporte dos salários — Funções (cargos),
 * Infotipos (rubricas) e Bancos — com o comportamento do sistema anterior (downloadSimpleTemplate/importSimpleExcel/
 * openCopySimpleTableModal, js/app_v2.js:4034 e 4168; downloadInfotypeTemplate/importInfotypesExcel), mas com as regras
 * do sistema novo:
 *   - cada registo passa pelo serviço da ficha (nome único, domínios validados, conta de movimento nos bancos);
 *   - os registos que já existem (mesmo nome, sem maiúsculas/espaços) são IGNORADOS — nunca alterados;
 *   - importação TUDO OU NADA: uma linha com erro e nada é gravado (relatório com a linha e o motivo); «Simular» faz as
 *     mesmas validações numa transacção desfeita;
 *   - cópia: só de empresas a que o utilizador tem acesso (ServicoEmpresas), lida na empresa de origem com o
 *     isolamento dessa empresa (ContextoEmpresa::executarComo) e gravada na empresa activa; auditoria em ambas.
 */
final class ServicoParidadeCadastrosRH
{
    /** [cabeçalho, campo, obrigatório, instrução, exemplo] por entidade */
    public const ENTIDADES = [
        'funcoes' => ['rotulo' => 'Funções', 'modelo' => 'Template_Funcoes', 'colunas' => [
            ['Nome', 'nome', true, 'Nome da função (único na empresa)', 'Técnico Administrativo'],
            // #10 (Versão Actual, app_v2.js:4071-4105): as 3 colunas do legado novo; o «Descrição» dos ficheiros antigos vale como responsabilidades
            ['Objectivo Geral do Cargo', 'objetivo_geral', false, 'Para que existe o cargo, numa frase ou duas (sai na Cláusula 1.ª do contrato)',
                'Assegurar o apoio administrativo da direcção, garantindo o arquivo e o expediente em dia.'],
            ['Principais Responsabilidades', 'responsabilidades', false, 'Uma responsabilidade por linha (Alt+Enter na mesma célula); saem numeradas no contrato',
                "Tratar o expediente;\nOrganizar o arquivo;\nApoiar a direcção."],
        ]],
        'infotipos' => ['rotulo' => 'Infotipos', 'modelo' => 'Template_Infotipos', 'colunas' => [
            ['Nome', 'nome', true, 'Nome da rubrica (único na empresa)', 'Subsídio de Alimentação'],
            ['Tipo', 'tipo', false, 'VENCIMENTO, DESCONTO ou OUTROS (vazio = VENCIMENTO)', 'VENCIMENTO'],
            ['INSS', 'sujeito_inss', false, 'Sujeito a INSS (3%): Sim ou Não (vazio = Sim)', 'Sim'],
            ['IRT', 'irt', false, 'Sujeito a IRT: Sim, Não ou «Até 30.000 Kz Isento» (vazio = Sim)', 'Sim'],
            ['Base dia/hora', 'base_horaria', false, 'Entra no valor dia/hora (faltas e horas extra): Sim ou Não', 'Não'],
            ['Por horas', 'calculo_horas', false, 'Horas extra, Faltas ou vazio', ''],
        ]],
        'bancos' => ['rotulo' => 'Bancos', 'modelo' => 'Template_Bancos', 'colunas' => [
            ['Nome', 'nome', true, 'Nome do banco (único na empresa)', 'Banco Exemplo'],
            ['Código', 'codigo', false, 'Código do banco (único)', 'BEX'],
            ['NIF', 'nif', false, 'NIF do banco', ''],
            ['Endereço', 'endereco', false, 'Endereço', ''],
            ['Conta Contab.', 'codigo_conta', false, 'Conta de movimento do plano (ex.: 43…)', ''],
        ]],
    ];

    private const MAX_LINHAS = 2000;

    public function __construct(
        private readonly ServicoCadastrosRH $cadastros,
        private readonly ServicoEstruturaOrg $estrutura,
        private readonly ServicoEmpresas $empresas,
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Template ─────────────

    public function modeloExcel(string $entidade, string $caminho): void
    {
        $def = $this->definicao($entidade);
        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Template');
        $folha->fromArray([array_column($def['colunas'], 0), array_column($def['colunas'], 4)]);
        foreach (range(1, count($def['colunas'])) as $i) {
            $folha->getColumnDimensionByColumn($i)->setWidth(26);
        }
        $ajuda = $livro->createSheet()->setTitle('Como_Preencher');
        $ajuda->fromArray(array_merge([['Campo', 'O que preencher', 'Obrigatório']], array_map(fn ($c) => [$c[0], $c[3], $c[2] ? 'Sim' : 'Não'], $def['colunas']),
            [['Nota', 'Os registos que já existem (mesmo nome) são ignorados. Uma linha com erro e nada é gravado. Use primeiro «Simular».', '']]));
        foreach (['A' => 22, 'B' => 90, 'C' => 14] as $col => $largura) {
            $ajuda->getColumnDimension($col)->setWidth($largura);
        }
        (new Xlsx($livro))->save($caminho);
    }

    // ───────────── Importar Excel ─────────────

    /**
     * @param  list<array<string, mixed>>  $linhas  linhas lidas pelo ServicoLeituraFolha (chave = cabeçalho)
     * @return array<string, mixed> relatório no formato das importações do RH (RelatorioImportacao)
     */
    public function importar(string $entidade, array $linhas, bool $simular): array
    {
        $def = $this->definicao($entidade);
        if (count($linhas) > self::MAX_LINHAS) {
            throw new ErroNegocio('O ficheiro tem demasiadas linhas (máximo '.self::MAX_LINHAS.').', 'IMPORTACAO_GRANDE', 422);
        }
        $r = ['novos' => 0, 'existentes' => [], 'repetidos' => 0, 'rejeitadas' => [], 'criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'erros' => []];
        $existentes = $this->nomesExistentes($entidade);
        $vistos = [];
        $validas = [];
        foreach ($linhas as $idx => $bruta) {
            $n = $idx + 2;
            $l = $this->normalizarLinha($def, (array) $bruta);
            if (array_filter($l, fn ($v) => $v !== '') === []) {
                continue;
            }
            if ($l['nome'] === '') {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => 'falta Nome'];

                continue;
            }
            $chave = self::chave($l['nome']);
            if (isset($vistos[$chave])) {
                $r['repetidos']++;

                continue;
            }
            $vistos[$chave] = true;
            try {
                $dados = $this->dados($entidade, $l);
            } catch (ErroNegocio $e) {
                $r['rejeitadas'][] = ['linha' => $n, 'motivo' => $e->getMessage()];

                continue;
            }
            if (isset($existentes[$chave])) {
                $r['existentes'][] = $l['nome'];
                $r['ignorados']++;

                continue;
            }
            $r['novos']++;
            $validas[] = ['linha' => $n, 'dados' => $dados];
        }
        if ($r['rejeitadas'] && ! $simular) {
            throw new ErroNegocio('Nada foi importado: '.count($r['rejeitadas']).' linha(s) com erro. Corrija o ficheiro e importe de novo.', 'IMPORTACAO_COM_ERROS', 422,
                ['rejeitadas' => $r['rejeitadas']]);
        }
        $this->gravarTudo($entidade, $validas, $r, $simular, "Importação Excel ({$def['rotulo']})");

        return $r + ['simulacao' => $simular];
    }

    // ───────────── Copiar de outra empresa ─────────────

    /** @return list<array{id: int, nome: string}> empresas acessíveis ao utilizador (sem a activa) */
    public function empresasOrigem(Utilizador $u): array
    {
        $activa = $this->contexto->obrigatorio();

        return $this->empresas->acessiveis($u)->reject(fn (Empresa $e) => (int) $e->id === $activa)
            ->map(fn (Empresa $e) => ['id' => (int) $e->id, 'nome' => (string) $e->nome])->values()->all();
    }

    /** @return array<string, mixed> relatório: criados, ignorados (já existiam), nomes */
    public function copiar(string $entidade, int $origemId, Utilizador $u, bool $simular): array
    {
        $def = $this->definicao($entidade);
        $destino = $this->contexto->obrigatorio();
        if ($origemId === $destino) {
            throw new ErroNegocio('Escolha uma empresa diferente da empresa activa.', 'ORIGEM_IGUAL_DESTINO', 422);
        }
        if (! $this->empresas->podeAceder($u, $origemId)) {
            // OWASP A01: não revela se a empresa existe
            throw new ErroNegocio('Não tem acesso à empresa de origem.', 'SEM_ACESSO_EMPRESA', 403);
        }
        $registos = $this->contexto->executarComo($origemId, fn () => $this->lerOrigem($entidade));
        $existentes = $this->nomesExistentes($entidade);
        $r = ['novos' => 0, 'existentes' => [], 'repetidos' => 0, 'rejeitadas' => [], 'criados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'erros' => []];
        $validas = [];
        foreach ($registos as $i => $d) {
            $chave = self::chave((string) $d['nome']);
            if (isset($existentes[$chave])) {
                $r['existentes'][] = $d['nome'];
                $r['ignorados']++;

                continue;
            }
            $existentes[$chave] = true;
            $r['novos']++;
            $validas[] = ['linha' => $i + 1, 'dados' => $d];
        }
        $origemNome = (string) (Empresa::query()->whereKey($origemId)->value('nome') ?? "#{$origemId}");
        $this->gravarTudo($entidade, $validas, $r, $simular, "Copiar de outra empresa ({$def['rotulo']}, origem: {$origemNome})");

        return $r + ['simulacao' => $simular, 'origem' => $origemNome];
    }

    // ───────────── Interno ─────────────

    /** @return array<string, mixed> */
    private function definicao(string $entidade): array
    {
        return self::ENTIDADES[$entidade] ?? throw new ErroNegocio('Tabela desconhecida.', 'ENTIDADE_DESCONHECIDA', 404);
    }

    /** @return array<string, true> nomes (normalizados) já registados na empresa activa */
    private function nomesExistentes(string $entidade): array
    {
        return $this->modelo($entidade)::query()->pluck('nome')->mapWithKeys(fn ($n) => [self::chave((string) $n) => true])->all();
    }

    /** @return class-string<Model> */
    private function modelo(string $entidade): string
    {
        return match ($entidade) {
            'funcoes' => CargoFuncao::class,
            'infotipos' => InfotipoSalarial::class,
            'bancos' => Banco::class,
        };
    }

    /** @return list<array<string, mixed>> campos copiáveis (nunca ids, empresa ou contas de outro plano sem validação) */
    private function lerOrigem(string $entidade): array
    {
        return match ($entidade) {
            'funcoes' => CargoFuncao::query()->ordenarPorNome()->get(['nome', 'objetivo_geral', 'responsabilidades', 'descricao'])
                ->map(fn ($c) => ['nome' => $c->nome, 'objetivo_geral' => $c->objetivo_geral, 'responsabilidades' => $c->responsabilidades ?: $c->descricao])->all(),
            'infotipos' => InfotipoSalarial::query()->ordenarPorNome()->get()->map(fn ($i) => ['nome' => $i->nome, 'tipo' => $i->tipo, 'sujeito_inss' => (bool) $i->sujeito_inss,
                'irt' => $i->irt ?? 'true', 'base_horaria' => (bool) $i->base_horaria, 'calculo_horas' => $i->calculo_horas])->all(),
            'bancos' => Banco::query()->ordenarPorNome()->get()->map(fn ($b) => ['nome' => $b->nome, 'codigo' => $b->codigo, 'nif' => $b->nif, 'endereco' => $b->endereco,
                'codigo_conta' => $b->codigo_conta])->all(),
        };
    }

    /** Grava numa transacção (desfeita na simulação ou se alguma falhar — e então recusa com o relatório). */
    private function gravarTudo(string $entidade, array $validas, array &$r, bool $simular, string $rotulo): void
    {
        DB::beginTransaction();
        try {
            foreach ($validas as $v) {
                try {
                    DB::transaction(fn () => $this->guardar($entidade, $v['dados']));
                    $r['criados']++;
                } catch (ErroNegocio $e) {
                    $r['erros'][] = ['linha' => $v['linha'], 'motivo' => $e->getMessage()];
                }
            }
            if ($simular || $r['erros']) {
                DB::rollBack();
            } else {
                $this->auditoria->registar('RH', $rotulo, "{$r['criados']} criado(s), {$r['ignorados']} ignorado(s) (já existiam).");
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $simular && $r['erros']) {
            throw new ErroNegocio('Nada foi gravado: '.count($r['erros']).' registo(s) com erro.', 'IMPORTACAO_COM_ERROS', 422, ['linhas' => $r['erros'], 'rejeitadas' => $r['rejeitadas']]);
        }
    }

    /** @param  array<string, mixed>  $d */
    private function guardar(string $entidade, array $d): void
    {
        match ($entidade) {
            'funcoes' => $this->estrutura->guardarCargo($d),
            'infotipos' => $this->cadastros->guardarInfotipo($d),
            'bancos' => $this->guardarBanco($d),
        };
    }

    /** Banco: a conta contabilística só se copia se existir como conta de movimento no plano da empresa activa. */
    private function guardarBanco(array $d): void
    {
        try {
            $this->cadastros->guardarBanco($d);
        } catch (ErroNegocio $e) {
            if (empty($d['codigo_conta']) || in_array($e->codigo ?? null, ['BANCO_DUPLICADO'], true)) {
                throw $e;
            }
            $d['codigo_conta'] = null;
            $this->cadastros->guardarBanco($d);
        }
    }

    /** @return array<string, string> */
    private function normalizarLinha(array $def, array $bruta): array
    {
        $porNome = [];
        foreach ($bruta as $k => $v) {
            $porNome[ServicoPermissoes::norm((string) $k)] = is_scalar($v) ? trim((string) $v) : '';
        }
        $l = [];
        foreach ($def['colunas'] as [$cab, $campo]) {
            $l[$campo] = mb_substr($porNome[ServicoPermissoes::norm($cab)] ?? $porNome[$campo] ?? '', 0, 5000);
        }
        // Funções: ficheiros do template antigo (coluna «Descrição») — vale como responsabilidades, como no legado novo
        if (array_key_exists('responsabilidades', $l) && $l['responsabilidades'] === '') {
            $l['responsabilidades'] = mb_substr($porNome[ServicoPermissoes::norm('Descrição')] ?? $porNome['descricao'] ?? '', 0, 5000);
        }

        return $l;
    }

    /** @return array<string, mixed> dados validados para o serviço da ficha */
    private function dados(string $entidade, array $l): array
    {
        if (mb_strlen($l['nome']) > 255) {
            throw new ErroNegocio('Nome demasiado longo (máximo 255).', 'VALOR_INVALIDO', 422);
        }

        return match ($entidade) {
            'funcoes' => ['nome' => $l['nome'], 'objetivo_geral' => $l['objetivo_geral'] !== '' ? $l['objetivo_geral'] : null,
                'responsabilidades' => $l['responsabilidades'] !== '' ? $l['responsabilidades'] : null],
            'infotipos' => [
                'nome' => $l['nome'],
                'tipo' => $this->dominio($l['tipo'], ['VENCIMENTO' => 'VENCIMENTO', 'PROVENTO' => 'VENCIMENTO', 'DESCONTO' => 'DESCONTO', 'OUTROS' => 'OUTROS'], 'VENCIMENTO', 'Tipo'),
                'sujeito_inss' => $this->simNao($l['sujeito_inss'], true, 'INSS'),
                'irt' => $this->dominio($l['irt'], ['SIM' => 'true', 'TRUE' => 'true', 'NAO' => 'false', 'FALSE' => 'false', 'ATE 30.000 KZ ISENTO' => 'conditional_30k',
                    'CONDITIONAL_30K' => 'conditional_30k', 'ISENTO ATE 30.000' => 'conditional_30k'], 'true', 'IRT'),
                'base_horaria' => $this->simNao($l['base_horaria'], false, 'Base dia/hora'),
                'calculo_horas' => $this->dominio($l['calculo_horas'], ['HORAS EXTRA' => 'EXTRA', 'EXTRA' => 'EXTRA', 'FALTAS' => 'FALTA', 'FALTA' => 'FALTA', 'NAO' => null], null, 'Por horas'),
            ],
            'bancos' => [
                'nome' => $l['nome'],
                'codigo' => $this->limite($l['codigo'], 50, 'Código'),
                'nif' => $this->limite($l['nif'], 30, 'NIF'),
                'endereco' => $this->limite($l['endereco'], 1000, 'Endereço'),
                'codigo_conta' => $this->limite($l['codigo_conta'], 20, 'Conta Contab.'),
            ],
        };
    }

    private function limite(string $v, int $max, string $campo): ?string
    {
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $max) {
            throw new ErroNegocio("{$campo} demasiado longo (máximo {$max}).", 'VALOR_INVALIDO', 422);
        }

        return $v;
    }

    private function simNao(string $v, bool $omissao, string $campo): bool
    {
        if ($v === '') {
            return $omissao;
        }

        return match (ServicoPermissoes::norm($v)) {
            'sim', 's', 'true', '1', 'yes' => true,
            'nao', 'n', 'false', '0', 'no' => false,
            default => throw new ErroNegocio("{$campo}: use Sim ou Não.", 'VALOR_INVALIDO', 422),
        };
    }

    /** @param  array<string, string|null>  $mapa */
    private function dominio(string $v, array $mapa, ?string $omissao, string $campo): ?string
    {
        if ($v === '') {
            return $omissao;
        }
        $k = mb_strtoupper(ServicoPermissoes::norm($v));
        if (! array_key_exists($k, $mapa)) {
            throw new ErroNegocio("{$campo}: valor «{$v}» inválido.", 'VALOR_INVALIDO', 422);
        }

        return $mapa[$k];
    }

    private static function chave(string $nome): string
    {
        return ServicoPermissoes::norm(preg_replace('/\s+/u', ' ', trim($nome)) ?? '');
    }
}
