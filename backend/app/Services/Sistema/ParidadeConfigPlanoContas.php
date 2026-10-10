<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Configurações › Plano de contas — paridade com o ecrã do sistema anterior (renderPlanoContas, js/app_v2.js:8320-8402;
 * verificação visual de 2026-10-08, linha 14.3.1):
 *  - coluna «Uso»: contas com lançamentos na empresa activa («🔒 Em Uso»; o legado percorria journal_lines);
 *  - «Copiar de outra empresa» (openCopySimpleTableModal('chart_of_accounts','code'), app_v2.js:4850-4960): só copia as
 *    contas que NÃO existem na empresa activa (nunca substitui nem apaga), com pré-visualização, numa transacção e com
 *    registo na auditoria; exige acesso do utilizador à empresa de origem;
 *  - «Importar Excel» (importChartOfAccountsExcel, app_v2.js:8643): validação linha a linha (código, descrição e tipo M/T),
 *    relatório de erros antes de gravar, e as contas existentes nunca são alteradas nem apagadas (decisão IGNORAR do
 *    centro de migração, ServicoMigracaoDados, que grava pelas regras do plano de contas).
 * As escritas passam pelo model PlanoConta, que invalida a cache erp:{empresa}:contabilidade:plano_contas agora e depois
 * do commit (R2); a auditoria por registo é feita pelo trait Auditavel e o resumo da operação por ServicoAuditoria.
 */
final class ParidadeConfigPlanoContas
{
    /** Cabeçalhos aceites (normalizados por ServicoPermissoes::norm) → campo; inclui os do modelo do legado («Código», «Descriçcação»). */
    private const CABECALHOS = [
        'conta' => 'codigo', 'codigo' => 'codigo', 'codigo da conta' => 'codigo', 'codigo conta' => 'codigo',
        'descricao' => 'descricao', 'descriccacao' => 'descricao', 'designacao' => 'descricao', 'nome' => 'descricao',
        'tipo' => 'tipo', 'tipo de conta' => 'tipo',
    ];

    /** Linha de exemplo do modelo (ServicoMigracaoDados::ENTIDADES['plano_contas']): ignorada se ficar no ficheiro. */
    private const EXEMPLO = ['codigo' => '3111', 'descricao' => 'Clientes nacionais'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoMigracaoDados $migracao,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /**
     * Códigos das contas com lançamentos na empresa activa (coluna «Uso»). Só leitura; não usa cache porque muda a cada
     * lançamento (consulta DISTINCT sobre o índice empresa_id/codigo_conta).
     *
     * @return list<string>
     */
    public function contasEmUso(): array
    {
        return DB::table('lancamentos_contabeis')->where('empresa_id', $this->contexto->obrigatorio())
            ->whereNotNull('codigo_conta')->distinct()->orderBy('codigo_conta')->pluck('codigo_conta')
            ->map(fn ($c) => (string) $c)->values()->all();
    }

    /**
     * Pré-visualização da cópia: contas na origem, novas a copiar e já existentes (comparação do código sem
     * maiúsculas/espaços, como o legado).
     *
     * @return array{empresa_origem_id: int, empresa_origem: string, registos_origem: int, novas: int, existentes: int, amostra: list<array{codigo: string, descricao: ?string, tipo: string}>}
     */
    public function simularCopia(int $origem): array
    {
        [$nome, $contas, $novas] = $this->planoCopia($origem);

        return ['empresa_origem_id' => $origem, 'empresa_origem' => $nome, 'registos_origem' => count($contas), 'novas' => count($novas),
            'existentes' => count($contas) - count($novas), 'amostra' => array_slice(array_map(fn ($c) => ['codigo' => $c['codigo'], 'descricao' => $c['descricao'], 'tipo' => $c['tipo']], $novas), 0, 50)];
    }

    /** @return array{empresa_origem_id: int, empresa_origem: string, registos_origem: int, copiadas: int, existentes: int} */
    public function copiar(int $origem): array
    {
        $destino = $this->contexto->obrigatorio();
        [$nome, $contas] = $this->planoCopia($origem);
        $copiadas = DB::transaction(function () use ($origem, $nome, $destino) {
            // dentro da transacção: bloqueia o plano de destino e recalcula as novas (outro pedido pode ter criado contas)
            $existentes = PlanoConta::query()->lockForUpdate()->pluck('codigo')->mapWithKeys(fn ($c) => [self::chave($c) => true])->all();
            $n = 0;
            foreach ($this->contasDaOrigem($origem) as $c) {
                $k = self::chave($c['codigo']);
                if ($k === '' || isset($existentes[$k])) {
                    continue;
                }
                PlanoConta::create([
                    'codigo' => $c['codigo'], 'descricao' => $c['descricao'], 'tipo' => $c['tipo'],
                    // regra do plano: só a classe 4 tem moeda (ServicoPlanoContas::validarMoeda)
                    'codigo_moeda' => str_starts_with($c['codigo'], '4') ? $c['codigo_moeda'] : null,
                    'natureza_conta' => $c['natureza_conta'],
                ]);
                $existentes[$k] = true;
                $n++;
            }
            $this->auditoria->registar('Sistema/Plano de contas', 'Copiar de outra empresa',
                "{$n} conta(s) copiada(s) de «{$nome}» (empresa {$origem}); as existentes foram mantidas.", 'plano_contas', null, null,
                ['empresa_origem_id' => $origem, 'copiadas' => $n], $destino);

            return $n;
        });
        // o model já invalida em cada criação; reforço para o caso de não haver nenhuma (idempotente)
        InvalidacaoCache::agoraEDepoisDoCommit(fn () => Cache::forget(ChaveCache::empresa($destino, 'contabilidade', 'plano_contas')));

        return ['empresa_origem_id' => $origem, 'empresa_origem' => $nome, 'registos_origem' => count($contas), 'copiadas' => $copiadas,
            'existentes' => count($contas) - $copiadas];
    }

    /**
     * Importação do Excel do plano de contas: lê e valida cada linha; com erros, nada é gravado e o relatório indica a
     * linha e o motivo. Sem erros, grava (ou simula) só as contas novas — as existentes nunca são alteradas.
     *
     * @param  list<array<string, mixed>>  $brutas
     * @return array<string, mixed>
     */
    public function importar(array $brutas, bool $simular): array
    {
        $existentes = PlanoConta::query()->pluck('codigo')->mapWithKeys(fn ($c) => [self::chave($c) => (string) $c])->all();
        $erros = [];
        $validas = [];
        $vistos = [];
        $repetidas = [];
        $mantidas = [];
        $exemplos = 0;
        foreach ($brutas as $i => $bruta) {
            $n = $i + 2;   // linha do Excel (1 = cabeçalhos)
            $l = $this->normalizar(is_array($bruta) ? $bruta : []);
            if ($l['codigo'] === '' && $l['descricao'] === '' && $l['tipo'] === '') {
                continue;
            }
            if ($l['codigo'] === self::EXEMPLO['codigo'] && $l['descricao'] === self::EXEMPLO['descricao']) {
                $exemplos++;

                continue;
            }
            $motivos = $this->validarLinha($l);
            if ($motivos) {
                $erros[] = ['linha' => $n, 'codigo' => mb_substr($l['codigo'], 0, 40), 'motivo' => implode('; ', $motivos)];

                continue;
            }
            $k = self::chave($l['codigo']);
            if (isset($vistos[$k])) {
                $repetidas[] = ['linha' => $n, 'codigo' => $l['codigo'], 'primeira_linha' => $vistos[$k]];

                continue;
            }
            $vistos[$k] = $n;
            if (isset($existentes[$k])) {
                $mantidas[] = ['linha' => $n, 'codigo' => $l['codigo']];

                continue;
            }
            $validas[] = ['Conta' => $l['codigo'], 'Descrição' => $l['descricao'], 'Tipo' => $l['tipo'] === '' ? PlanoConta::TIPO_MOVIMENTO : $l['tipo']];
        }

        $r = ['simulacao' => $simular, 'linhas_lidas' => count(array_filter($brutas)), 'linhas_exemplo_ignoradas' => $exemplos,
            'novas' => count($validas), 'existentes' => count($mantidas), 'repetidas' => count($repetidas),
            'erros' => $erros, 'avisos' => array_merge(
                array_map(fn ($m) => ['linha' => $m['linha'], 'motivo' => "A conta {$m['codigo']} já existe: mantida sem alterações."], array_slice($mantidas, 0, 500)),
                array_map(fn ($m) => ['linha' => $m['linha'], 'motivo' => "Conta {$m['codigo']} repetida (já na linha {$m['primeira_linha']}): ignorada."], array_slice($repetidas, 0, 500)),
            ),
            'amostra' => array_slice(array_map(fn ($v) => ['codigo' => $v['Conta'], 'descricao' => $v['Descrição'], 'tipo' => $v['Tipo']], $validas), 0, 50),
            'criadas' => 0];

        if ($erros) {
            if (! $simular) {
                throw new ErroNegocio('Nada foi importado: '.count($erros).' linha(s) com erro. Corrija o ficheiro e importe de novo.', 'IMPORTACAO_COM_ERROS', 422,
                    ['linhas' => $erros, 'rejeitadas' => []]);
            }

            return $r;
        }
        if ($validas === []) {
            return $r;
        }
        // grava pelas regras do plano de contas (transacção; tudo ou nada; auditoria e cache tratadas pelo serviço)
        $m = $this->migracao->importar('plano_contas', $validas, 'IGNORAR', $simular);
        $r['erros'] = array_map(fn ($e) => ['linha' => $this->linhaOriginal($brutas, $validas, (int) $e['linha']), 'codigo' => '', 'motivo' => $e['motivo']], $m['erros']);
        $r['criadas'] = $simular ? 0 : (int) $m['criados'];

        return $r;
    }

    // ───────────── regras internas ─────────────

    /** @return array{0: string, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>} nome da origem, contas da origem, novas */
    private function planoCopia(int $origem): array
    {
        $destino = $this->contexto->obrigatorio();
        if ($origem === $destino) {
            throw new ErroNegocio('Escolha uma empresa de origem diferente da empresa activa.', 'EMPRESA_ORIGEM_INVALIDA', 422);
        }
        $u = Auth::user();
        if (! $u || ! $this->empresas->podeAceder($u, $origem)) {
            throw new ErroNegocio('Não tem acesso à empresa de origem.', 'SEM_ACESSO_EMPRESA', 403, ['empresa_id' => $origem]);
        }
        $nome = (string) Empresa::query()->whereKey($origem)->value('nome');
        $contas = $this->contasDaOrigem($origem);
        $existentes = PlanoConta::query()->pluck('codigo')->mapWithKeys(fn ($c) => [self::chave($c) => true])->all();
        $novas = array_values(array_filter($contas, fn ($c) => self::chave($c['codigo']) !== '' && ! isset($existentes[self::chave($c['codigo'])])));

        return [$nome, $contas, $novas];
    }

    /** @return list<array{codigo: string, descricao: ?string, tipo: string, codigo_moeda: ?string, natureza_conta: ?string}> */
    private function contasDaOrigem(int $origem): array
    {
        return $this->contexto->executarComo($origem, fn () => PlanoConta::query()->orderBy('codigo')
            ->get(['codigo', 'descricao', 'tipo', 'codigo_moeda', 'natureza_conta'])
            ->map(fn (PlanoConta $c) => ['codigo' => trim((string) $c->codigo), 'descricao' => $c->descricao,
                'tipo' => $c->tipo === PlanoConta::TIPO_TOTALIZADORA ? PlanoConta::TIPO_TOTALIZADORA : PlanoConta::TIPO_MOVIMENTO,
                'codigo_moeda' => $c->codigo_moeda, 'natureza_conta' => $c->natureza_conta])
            ->all());
    }

    /** @return array{codigo: string, descricao: string, tipo: string} */
    private function normalizar(array $bruta): array
    {
        $l = ['codigo' => '', 'descricao' => '', 'tipo' => ''];
        foreach ($bruta as $cabecalho => $valor) {
            $campo = self::CABECALHOS[ServicoPermissoes::norm((string) $cabecalho)] ?? null;
            if ($campo !== null && $l[$campo] === '') {
                $l[$campo] = is_scalar($valor) ? trim((string) $valor) : '';
            }
        }
        $l['tipo'] = mb_strtoupper($l['tipo']);

        return $l;
    }

    /** @return list<string> motivos (vazio = linha válida) — as mesmas regras da ficha (GuardarContaRequest) */
    private function validarLinha(array $l): array
    {
        $m = [];
        if ($l['codigo'] === '') {
            $m[] = 'falta o código da conta';
        } elseif (mb_strlen($l['codigo']) > 20) {
            $m[] = 'código com mais de 20 caracteres';
        } elseif (! preg_match('/^[0-9A-Za-z.]+$/', $l['codigo'])) {
            $m[] = 'código inválido (só algarismos, letras e pontos)';
        }
        if ($l['descricao'] === '') {
            $m[] = 'falta a descrição';
        } elseif (mb_strlen($l['descricao']) > 255) {
            $m[] = 'descrição com mais de 255 caracteres';
        }
        if ($l['tipo'] !== '' && ! in_array($l['tipo'], [PlanoConta::TIPO_MOVIMENTO, PlanoConta::TIPO_TOTALIZADORA], true)) {
            $m[] = 'tipo «'.mb_substr($l['tipo'], 0, 20).'» inválido (use M = movimento ou T = totalizadora)';
        }

        return $m;
    }

    /** Converte o n.º de linha devolvido pelo serviço de migração (posição nas válidas + 2) na linha do Excel. */
    private function linhaOriginal(array $brutas, array $validas, int $linhaMigracao): int
    {
        $v = $validas[$linhaMigracao - 2] ?? null;
        if ($v === null) {
            return $linhaMigracao;
        }
        foreach ($brutas as $i => $b) {
            if (is_array($b) && self::chave($this->normalizar($b)['codigo']) === self::chave($v['Conta'])) {
                return $i + 2;
            }
        }

        return $linhaMigracao;
    }

    private static function chave(mixed $codigo): string
    {
        return mb_strtolower(trim((string) $codigo));
    }
}
