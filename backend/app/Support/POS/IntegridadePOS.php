<?php

namespace App\Support\POS;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * F0 do POS — integridade na base de dados, com segurança para dados reais (que não se podem ver antes do deploy).
 *
 * Catálogo declarativo das restrições que faltavam nas tabelas do POS (docs/paridade/POS_ACTUAL_INVENTARIO.md §8.2, D-01/D-02):
 *   - `unico`  : índice único por empresa (chaves de numeração; o serviço de numeração continua a ser a fonte, a base passa a ser a barreira);
 *   - `check`  : CHECK nas colunas de estado/enumeração, com os valores realmente usados pelo código (NULL continua permitido);
 *   - `fk`     : chave estrangeira em falta (justificação em cada entrada).
 * Os índices de desempenho (sem risco para os dados) estão em `indices()`.
 *
 * Regra de ouro: NUNCA se altera nem apaga dados. `auditar()` só lê. `aplicar()` só cria a restrição quando a auditoria não
 * encontra duplicados/valores fora da lista/órfãos; caso contrário não cria, não falha (o deploy segue) e regista um aviso no
 * log — o comando `erp:pos:auditar-integridade` mostra o que corrigir. É idempotente: uma restrição já existente não é tocada
 * (um CHECK que ficou NOT VALID é validado quando os dados já o permitirem).
 *
 * O resultado nunca contém dados pessoais: só ids de linhas, códigos de numeração (sessão, Z, ordem, recibo) e valores de
 * enumerações (truncados).
 */
final class IntegridadePOS
{
    /** Máximo de ids e de grupos devolvidos por restrição (o suficiente para localizar e corrigir). */
    public const LIMITE = 20;

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string, mixed>> */
    public static function definicoes(): array
    {
        $unico = fn (string $tabela, array $colunas, string $porque, ?string $onde = null) => [
            'tipo' => 'unico', 'nome' => 'uq_'.$tabela.'_'.trim(preg_replace('/[^a-z0-9]+/', '_', implode('_', $colunas)), '_'),
            'tabela' => $tabela, 'colunas' => $colunas, 'onde' => $onde, 'porque' => $porque,
        ];
        $check = fn (string $tabela, string $coluna, array $valores, string $porque) => [
            'tipo' => 'check', 'nome' => "ck_{$tabela}_{$coluna}", 'tabela' => $tabela, 'coluna' => $coluna, 'valores' => $valores, 'porque' => $porque,
        ];

        return [
            // ───── unicidade (chaves de numeração e de configuração) ─────
            $unico('sessoes_pos', ['empresa_id', 'codigo_sessao'], 'código da sessão (T01-AAAA-NNNN) único por empresa'),
            $unico('sessoes_pos', ['empresa_id', 'numero_z'], 'número do Z (Z-T01-AAAA-NNNN) único por empresa'),
            $unico('pedidos_lavandaria', ['empresa_id', 'numero_encomenda'], 'número da ordem de lavandaria (OS/…) único por empresa'),
            $unico('pagamentos_lavandaria', ['empresa_id', 'numero_recibo'], 'número do recibo da lavandaria (RC-LAV/… ou a FR) único por empresa'),
            $unico('configuracoes_lavandaria', ['empresa_id'], 'uma configuração de lavandaria por empresa (hoje só por convenção do serviço)'),
            $unico('pecas_lavandaria', ['empresa_id', 'codigo'], 'código da peça (PC####) único por empresa (atribuído por ServicoNumeracao)'),
            $unico('mesas_pos', ['terminal_pos_id', 'lower(nome)'], 'o serviço compara o nome da mesa sem distinguir maiúsculas; a base só impedia o nome exacto'),
            $unico('contas_mesa_pos', ['venda_id'], 'uma venda só fecha uma conta de mesa', 'venda_id IS NOT NULL'),

            // ───── estados e enumerações (valores usados pelo código; ver docs/paridade/POS_F0_RELATORIO.md) ─────
            $check('sessoes_pos', 'estado', ['ABERTA', 'FECHADA'], 'ServicoSessoesPOS: abrir → ABERTA, fechar (Z) → FECHADA'),
            $check('sessoes_pos', 'estado_contabilizacao', ['PENDENTE', 'CONTABILIZADA', 'SEM_MOVIMENTO'], 'ServicoSessoesPOS/ServicoContabilizacaoPOS'),
            $check('sessoes_pos', 'estado_liquidacao', ['PENDENTE', 'PARCIAL', 'LIQUIDADA', 'SEM_MOVIMENTO'], 'ServicoSessoesPOS/ServicoPrestacaoContasPOS'),
            $check('liquidacoes_pos', 'estado', ['REGISTADO', 'ANULADO'], 'ServicoPrestacaoContasPOS: criar → REGISTADO, anular → ANULADO'),
            $check('liquidacoes_pos', 'natureza_registo', ['NUMERARIO', 'TPA', 'TRANSFERENCIA'], 'ServicoPrestacaoContasPOS::NATUREZAS'),
            $check('liquidacoes_pos', 'alvo', ['TESOURARIA', 'FOLHA_CAIXA'], 'ServicoPrestacaoContasPOS: destino da liquidação'),
            $check('senhas_pos_armazem', 'estado', ['AGUARDA', 'CHAMADA'], 'ServicoSenhasPOSArmazem (SenhaPOSArmazem::AGUARDA/CHAMADA)'),
            $check('estadias_hotel', 'estado', ['ABERTA', 'FECHADA', 'ANULADA'], 'EstadiaHotel::ABERTA/FECHADA/ANULADA'),
            $check('estadias_hotel', 'modo', ['DIA', 'HORA'], 'EstadiaHotel::MODOS'),
            $check('pedidos_lavandaria', 'estado', ['ORCAMENTO', 'RECEBIDA', 'EM_EXECUCAO', 'PRONTA', 'ENTREGA_PARCIAL', 'ENTREGUE', 'ANULADA'], 'RegrasLavandaria::ESTADOS'),
            $check('pedidos_lavandaria', 'modo_faturacao', ['RECEPCAO', 'ENTREGA'], 'LavandariaController: modo_faturacao in:RECEPCAO,ENTREGA'),
            $check('pagamentos_lavandaria', 'estado', ['REGISTADO', 'ANULADO'], 'ServicoCaixaLavandaria: REGISTADO → ANULADO'),
            $check('pagamentos_lavandaria', 'natureza_registo', ['ADIANTAMENTO', 'PAGAMENTO', 'FR'], 'ServicoCaixaLavandaria: recibo ADIANTAMENTO/PAGAMENTO ou FR'),
            $check('reclamacoes_lavandaria', 'estado', ['AGUARDA_COMPROVATIVO', 'COMPROVADO', 'APROVADA', 'RECUSADA', 'PAGA'], 'ServicoReclamacoesLavandaria::ESTADOS'),

            // ───── chaves estrangeiras em falta ─────
            [
                'tipo' => 'fk', 'nome' => 'fk_sessoes_pos_operador_id', 'tabela' => 'sessoes_pos', 'coluna' => 'operador_id', 'ref_tabela' => 'utilizadores', 'ref_coluna' => 'id',
                'apagar' => 'SET NULL',
                'porque' => 'operador da sessão: o nome fica em nome_operador, por isso SET NULL preserva o histórico se o utilizador for apagado (sem índice: só serve a eliminação de utilizadores)',
            ],
        ];
    }

    /**
     * Índices de desempenho (aditivos, não dependem dos dados). Criam-se com CONCURRENTLY, fora de transacção.
     *
     * @return list<array{nome: string, tabela: string, sql: string, porque: string}>
     */
    public static function indices(): array
    {
        return [
            ['nome' => 'idx_sessoes_pos_empresa_aberto', 'tabela' => 'sessoes_pos',
                'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sessoes_pos_empresa_aberto ON sessoes_pos (empresa_id, aberto_em DESC, id DESC)',
                'porque' => 'a listagem de sessões ordena por aberto_em, id desc dentro da empresa (hoje só há índice por empresa_id)'],
            ['nome' => 'idx_estadias_hotel_abertas', 'tabela' => 'estadias_hotel',
                'sql' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_estadias_hotel_abertas ON estadias_hotel (empresa_id, terminal_pos_id) WHERE estado = 'ABERTA'",
                'porque' => 'o mapa de quartos e o check-out só leem estadias abertas (pequeno subconjunto de uma tabela que cresce)'],
            ['nome' => 'idx_pedidos_lavandaria_estado', 'tabela' => 'pedidos_lavandaria',
                'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pedidos_lavandaria_estado ON pedidos_lavandaria (empresa_id, estado, recebido_em DESC)',
                'porque' => 'as listas e indicadores da lavandaria filtram por estado e ordenam pela data de recepção'],
        ];
    }

    // ───────────── auditoria (só leitura) ─────────────

    /**
     * Estado de cada restrição do catálogo.
     * `estado`: ok · em_falta (pode criar-se) · bloqueada (faltam e há dados a corrigir) · nao_validada (CHECK/FK NOT VALID) · tabela_inexistente.
     *
     * @return list<array<string, mixed>>
     */
    public function auditar(): array
    {
        $saida = [];
        foreach (self::definicoes() as $d) {
            $saida[] = $d + $this->estado($d);
        }

        return $saida;
    }

    /** @return list<array<string, mixed>> estado dos índices de desempenho: nome, tabela, estado (ok|em_falta|tabela_inexistente), porque */
    public function auditarIndices(): array
    {
        return array_map(fn (array $i) => ['tipo' => 'indice', 'nome' => $i['nome'], 'tabela' => $i['tabela'], 'porque' => $i['porque'],
            'estado' => ! $this->tabelaExiste($i['tabela']) ? 'tabela_inexistente' : ($this->indiceValido($i['nome']) ? 'ok' : 'em_falta')], self::indices());
    }

    /** @return array{estado: string, problemas: list<array<string, mixed>>} */
    private function estado(array $d): array
    {
        if (! $this->tabelaExiste($d['tabela']) || ($d['tipo'] === 'fk' && ! $this->tabelaExiste($d['ref_tabela']))) {
            return ['estado' => 'tabela_inexistente', 'problemas' => []];
        }
        $existe = match ($d['tipo']) {
            'unico' => $this->indiceValido($d['nome']),
            default => $this->restricaoExiste($d),
        };
        if ($existe === true && ($d['tipo'] === 'unico' || $this->restricaoValidada($d))) {
            return ['estado' => 'ok', 'problemas' => []];
        }
        $problemas = $this->problemas($d);

        return ['estado' => $existe ? 'nao_validada' : ($problemas ? 'bloqueada' : 'em_falta'), 'problemas' => $problemas];
    }

    /**
     * Linhas que impedem a restrição. Cada item: `chave` (valor/código/ids), `linhas` (quantas) e `ids` (até LIMITE).
     *
     * @return list<array<string, mixed>>
     */
    private function problemas(array $d): array
    {
        $t = $d['tabela'];
        $lim = self::LIMITE;
        if ($d['tipo'] === 'unico') {
            $cols = $d['colunas'];
            $nulos = implode(' AND ', array_map(fn ($c) => "{$c} IS NOT NULL", $cols));
            $onde = $nulos.($d['onde'] ? " AND ({$d['onde']})" : '');
            $sel = implode(', ', array_map(fn ($c, $i) => "{$c}::text AS c{$i}", $cols, array_keys($cols)));
            $grp = implode(', ', $cols);
            $linhas = $this->db->select("SELECT {$sel}, count(*) AS n, (array_agg(id ORDER BY id))[1:{$lim}] AS ids FROM {$t} WHERE {$onde} GROUP BY {$grp} HAVING count(*) > 1 ORDER BY n DESC, 1 LIMIT {$lim}");

            return array_map(fn ($l) => [
                'chave' => implode(' | ', array_map(fn ($c, $i) => $c.'='.$l->{'c'.$i}, $cols, array_keys($cols))),
                'linhas' => (int) $l->n, 'ids' => self::ids($l->ids),
            ], $linhas);
        }
        if ($d['tipo'] === 'check') {
            $c = $d['coluna'];
            $lista = implode(', ', array_map(fn ($v) => $this->db->getPdo()->quote($v), $d['valores']));
            $linhas = $this->db->select("SELECT left({$c}::text, 40) AS v, count(*) AS n, (array_agg(id ORDER BY id))[1:{$lim}] AS ids FROM {$t} WHERE {$c} IS NOT NULL AND {$c}::text NOT IN ({$lista}) GROUP BY {$c}::text ORDER BY n DESC LIMIT {$lim}");

            return array_map(fn ($l) => ['chave' => "{$c}='{$l->v}'", 'linhas' => (int) $l->n, 'ids' => self::ids($l->ids)], $linhas);
        }
        $c = $d['coluna'];   // fk: órfãos
        $linhas = $this->db->select("SELECT count(*) AS n, (array_agg(t.id ORDER BY t.id))[1:{$lim}] AS ids FROM {$t} t WHERE t.{$c} IS NOT NULL AND NOT EXISTS (SELECT 1 FROM {$d['ref_tabela']} r WHERE r.{$d['ref_coluna']} = t.{$c})");

        return $linhas && (int) $linhas[0]->n > 0 ? [['chave' => "{$c} sem registo em {$d['ref_tabela']}", 'linhas' => (int) $linhas[0]->n, 'ids' => self::ids($linhas[0]->ids)]] : [];
    }

    // ───────────── aplicação (migrações) ─────────────

    /**
     * Cria o que a auditoria permitir; nunca falha por causa dos dados.
     *
     * @param  list<string>|null  $tipos  'unico', 'check', 'fk' (null = todos)
     * @return list<array{nome: string, accao: string, motivo: ?string}> accao: criada · validada · existente · ignorada · erro
     */
    public function aplicar(?array $tipos = null): array
    {
        $res = [];
        foreach ($this->auditar() as $d) {
            if ($tipos !== null && ! in_array($d['tipo'], $tipos, true)) {
                continue;
            }
            $res[] = ['nome' => $d['nome']] + $this->aplicarUma($d);
        }

        return $res;
    }

    /** @return array{accao: string, motivo: ?string} */
    private function aplicarUma(array $d): array
    {
        $nome = $d['nome'];
        if ($d['estado'] === 'ok') {
            return ['accao' => 'existente', 'motivo' => null];
        }
        if ($d['estado'] === 'tabela_inexistente') {
            return ['accao' => 'ignorada', 'motivo' => 'tabela inexistente'];
        }
        if ($d['estado'] === 'bloqueada' || ($d['estado'] === 'nao_validada' && $d['problemas'])) {
            $n = array_sum(array_column($d['problemas'], 'linhas'));
            $motivo = "{$n} linha(s) impedem a restrição ({$d['porque']}); corrija os dados (erp:pos:auditar-integridade) e volte a correr a migração/comando";
            Log::warning("POS/integridade: restrição {$nome} em {$d['tabela']} NÃO criada — {$motivo}.", ['problemas' => $d['problemas']]);

            return ['accao' => 'ignorada', 'motivo' => $motivo];
        }
        try {
            if ($d['estado'] === 'em_falta') {
                $this->db->transaction(fn () => $this->db->statement($this->sqlCriar($d)));
                $accao = 'criada';
            } else {
                $accao = 'validada';
            }
            if ($d['tipo'] !== 'unico') {
                $this->db->transaction(fn () => $this->db->statement("ALTER TABLE {$d['tabela']} VALIDATE CONSTRAINT {$nome}"));
            }

            return ['accao' => $accao, 'motivo' => null];
        } catch (Throwable $e) {   // p.ex. linha inserida entre a auditoria e a criação: a migração não pode falhar por isso
            Log::warning("POS/integridade: restrição {$nome} em {$d['tabela']} não ficou completa: ".$e->getMessage());

            return ['accao' => 'erro', 'motivo' => $e->getMessage()];
        }
    }

    /** Cria os índices de desempenho em falta (sem transacção: CONCURRENTLY). */
    public function aplicarIndices(): array
    {
        $res = [];
        foreach (self::indices() as $i) {
            if (! $this->tabelaExiste($i['tabela'])) {
                $res[] = ['nome' => $i['nome'], 'accao' => 'ignorada', 'motivo' => 'tabela inexistente'];

                continue;
            }
            if ($this->indiceValido($i['nome'])) {
                $res[] = ['nome' => $i['nome'], 'accao' => 'existente', 'motivo' => null];

                continue;
            }
            try {
                $this->db->statement("DROP INDEX CONCURRENTLY IF EXISTS {$i['nome']}");   // resto inválido de uma criação interrompida
                $this->db->statement($i['sql']);
                $res[] = ['nome' => $i['nome'], 'accao' => 'criada', 'motivo' => null];
            } catch (Throwable $e) {
                Log::warning("POS/integridade: índice {$i['nome']} não criado: ".$e->getMessage());
                $res[] = ['nome' => $i['nome'], 'accao' => 'erro', 'motivo' => $e->getMessage()];
            }
        }

        return $res;
    }

    private function sqlCriar(array $d): string
    {
        return match ($d['tipo']) {
            'unico' => "CREATE UNIQUE INDEX IF NOT EXISTS {$d['nome']} ON {$d['tabela']} (".implode(', ', $d['colunas']).')'
                .($d['onde'] ? " WHERE {$d['onde']}" : ''),
            'check' => "ALTER TABLE {$d['tabela']} ADD CONSTRAINT {$d['nome']} CHECK ({$d['coluna']} IS NULL OR {$d['coluna']}::text IN ("
                .implode(', ', array_map(fn ($v) => $this->db->getPdo()->quote($v), $d['valores'])).')) NOT VALID',
            'fk' => "ALTER TABLE {$d['tabela']} ADD CONSTRAINT {$d['nome']} FOREIGN KEY ({$d['coluna']}) REFERENCES {$d['ref_tabela']} ({$d['ref_coluna']}) ON DELETE {$d['apagar']} NOT VALID",
        };
    }

    // ───────────── catálogo do PostgreSQL ─────────────

    private function tabelaExiste(string $t): bool
    {
        return (bool) $this->db->selectOne('SELECT 1 AS x FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ? AND c.relkind IN (\'r\', \'p\')', [$t]);
    }

    private function indiceValido(string $nome): bool
    {
        return (bool) $this->db->selectOne('SELECT 1 AS x FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ? AND i.indisvalid', [$nome]);
    }

    private function restricaoExiste(array $d): bool
    {
        return (bool) $this->db->selectOne('SELECT 1 AS x FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$d['nome'], $d['tabela']]);
    }

    private function restricaoValidada(array $d): bool
    {
        return (bool) $this->db->selectOne('SELECT 1 AS x FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass AND convalidated', [$d['nome'], $d['tabela']]);
    }

    /** @return list<int> */
    private static function ids(mixed $pg): array
    {
        return $pg === null ? [] : array_map('intval', explode(',', trim((string) $pg, '{}')));
    }
}
