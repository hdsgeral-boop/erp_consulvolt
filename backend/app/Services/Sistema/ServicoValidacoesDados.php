<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\LancamentoContabil;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Validações de dados (ADR-015): substituem as rotinas destrutivas escondidas do legado, que alteravam
 * ou apagavam dados ao abrir ecrãs, por relatórios só de leitura com drill-down. As correcções, quando
 * existirem, são acções explícitas e auditadas — nunca efeitos de uma leitura.
 *
 * Cada validação: código, módulo, gravidade, descrição, origem no legado e uma consulta que devolve
 * as linhas problemáticas da empresa activa (primeiro parâmetro: empresa_id).
 */
final class ServicoValidacoesDados
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /** @return array<string, array{titulo: string, modulo: string, gravidade: string, descricao: string, legado: string, sql: string}> */
    private function definicoes(): array
    {
        return [
            'lancamentos_desequilibrados' => [
                'titulo' => 'Lançamentos desequilibrados (Σ D ≠ Σ C)', 'modulo' => 'Contabilidade', 'gravidade' => 'ERRO',
                'descricao' => 'Lançamentos cujo total a débito difere do total a crédito. Os do legado foram importados como estão (decisão 2026-09-29).',
                'legado' => 'Alerta de desequilíbrio em ui_reports.js:381-408',
                'sql' => 'SELECT d.codigo AS diario, '.LancamentoContabil::chaveSql('l')." AS lancamento, MIN(l.data_documento) AS data,
                            SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) AS diferenca, MIN(l.id) AS linha_id
                          FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id WHERE l.empresa_id = ?
                          GROUP BY d.codigo, ".LancamentoContabil::chaveSql('l')."
                          HAVING SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) <> 0 ORDER BY 4",
            ],
            'lancamentos_em_contas_totalizadoras' => [
                'titulo' => 'Movimentos em contas totalizadoras', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Linhas lançadas em contas do tipo T, que só deviam agregar (regra js/db_v2.js:392-449).',
                'legado' => 'Hook de contas de movimento (não aplicado a dados antigos)',
                'sql' => "SELECT l.id AS linha_id, l.codigo_conta, l.numero_lan, l.data_documento, l.valor, l.tipo_dc
                          FROM lancamentos_contabeis l JOIN plano_contas p ON p.empresa_id = l.empresa_id AND p.codigo = l.codigo_conta AND p.eliminado_em IS NULL
                          WHERE l.empresa_id = ? AND p.tipo = 'T' ORDER BY l.data_documento, l.id",
            ],
            'lancamentos_conta_inexistente' => [
                'titulo' => 'Movimentos em contas que não existem no plano', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Linhas cujo código de conta não existe (ou foi eliminado) no plano de contas da empresa.',
                'legado' => 'O legado não validava o código de conta na importação',
                'sql' => "SELECT l.codigo_conta, COUNT(*) AS linhas, SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) AS saldo, MIN(l.id) AS linha_id
                          FROM lancamentos_contabeis l
                          WHERE l.empresa_id = ? AND NOT EXISTS (SELECT 1 FROM plano_contas p WHERE p.empresa_id = l.empresa_id AND p.codigo = l.codigo_conta AND p.eliminado_em IS NULL)
                          GROUP BY l.codigo_conta ORDER BY 2 DESC",
            ],
            'lancamentos_valor_zero' => [
                'titulo' => 'Linhas de lançamento com valor zero', 'modulo' => 'Contabilidade', 'gravidade' => 'INFO',
                'descricao' => 'Linhas sem efeito contabilístico (38 vieram do legado).', 'legado' => '—',
                'sql' => 'SELECT id AS linha_id, codigo_conta, numero_lan, numero_documento, data_documento FROM lancamentos_contabeis WHERE empresa_id = ? AND valor = 0 ORDER BY id',
            ],
            'folhas_salariais_vs_diario' => [
                'titulo' => 'Folhas de salários que não conferem com o diário', 'modulo' => 'RH', 'gravidade' => 'AVISO',
                'descricao' => 'Períodos contabilizados cuja fotografia (Σ vencimentos + INSS patronal) difere mais de 10 Kz dos débitos do lançamento SAL. '
                    .'No legado os resultados eram recalculados ao vivo: meses com contratos ou lançamentos alterados depois de contabilizados já não se reproduzem.',
                'legado' => 'calculatePeriodData (js/app_v2.js:5787) recalculava sempre; ROUNDING_DIFF absorvia até 10 Kz',
                'sql' => "SELECT p.id AS periodo_id, p.mes_ano, calc.debitos AS calculado, COALESCE(dia.debitos, 0) AS diario, calc.debitos - COALESCE(dia.debitos, 0) AS diferenca
                          FROM periodos_processamento_salarial p
                          JOIN LATERAL (SELECT COALESCE(SUM(r.inss_patronal), 0) + COALESCE(SUM((SELECT SUM((x->>'valor')::numeric) FROM jsonb_array_elements(r.rubricas) x
                                  WHERE x->>'tipo' = 'VENCIMENTO' AND COALESCE((x->>'informativa')::boolean, false) = false)), 0) AS debitos
                                FROM resultados_folha_salarial r WHERE r.periodo_processamento_salarial_id = p.id) calc ON true
                          LEFT JOIN LATERAL (SELECT SUM(l.valor) AS debitos FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id AND d.codigo = 'SAL'
                                WHERE l.empresa_id = p.empresa_id AND l.numero_documento = 'SAL' || replace(p.mes_ano, '/', '') AND l.tipo_dc = 'D' AND l.estorno_de_id IS NULL) dia ON true
                          WHERE p.empresa_id = ? AND p.contabilizado AND abs(calc.debitos - COALESCE(dia.debitos, 0)) > 10 ORDER BY p.mes_ano",
            ],
            'stock_acertos_migracao' => [
                'titulo' => 'Stock: acertos de saldo inicial na migração', 'modulo' => 'Logística', 'gravidade' => 'AVISO',
                'descricao' => 'No legado o stock por armazém, o stock total e os movimentos eram actualizados em sítios diferentes e divergiam. Manteve-se o saldo por armazém '
                    .'e criou-se um movimento de acerto por diferença; confirme estas quantidades num inventário.',
                'legado' => 'updateWarehouseStock (js/ui_warehouse.js:1010) e escritas directas em products.stock_qty sem movimento',
                'sql' => "SELECT m.id AS movimento_id, a.nome AS armazem, p.codigo, p.nome, m.sentido, m.quantidade, m.valor FROM movimentos_inventario m
                          JOIN produtos p ON p.id = m.produto_id JOIN armazens a ON a.id = m.armazem_id
                          WHERE m.empresa_id = ? AND m.documento_tipo = 'MIGRACAO' ORDER BY a.nome, p.codigo",
            ],
            'stock_negativo' => [
                'titulo' => 'Stock negativo', 'modulo' => 'Logística', 'gravidade' => 'AVISO',
                'descricao' => 'As vendas podem deixar o stock negativo (como no legado, para não bloquear documentos fiscais); regularize com a entrada em falta ou um inventário.',
                'legado' => 'FT/FR/GR baixavam o stock sem verificar (js/ui_sales.js:1888)',
                'sql' => 'SELECT s.armazem_id, a.nome AS armazem, p.codigo, p.nome, s.quantidade_stock FROM stock_armazem s JOIN produtos p ON p.id = s.produto_id
                          JOIN armazens a ON a.id = s.armazem_id WHERE s.empresa_id = ? AND s.quantidade_stock < 0 ORDER BY a.nome, p.codigo',
            ],
            'produtos_stock_sem_custo' => [
                'titulo' => 'Produtos com stock e sem custo médio', 'modulo' => 'Logística', 'gravidade' => 'AVISO',
                'descricao' => 'Sem custo, as saídas e o custo das vendas (CMV) ficam a zero. O legado nunca calculou o custo médio; o inicial foi o último custo de recepção, quando existia.',
                'legado' => 'calculateAverageCosts (js/ui_warehouse.js:1) recalculava ao vivo e caía no preço de venda',
                'sql' => 'SELECT id AS produto_id, codigo, nome, quantidade_stock FROM produtos
                          WHERE empresa_id = ? AND movimenta_stock AND eliminado_em IS NULL AND quantidade_stock <> 0 AND COALESCE(custo_medio, 0) = 0 ORDER BY codigo',
            ],
            'colaboradores_activos_sem_iban' => [
                'titulo' => 'Colaboradores activos sem IBAN', 'modulo' => 'RH', 'gravidade' => 'AVISO',
                'descricao' => 'Sem coordenadas bancárias não entram numa carta de pagamento. No legado os botões do ecrã de IBAN não funcionavam: só a importação gravava.',
                'legado' => 'saveEmployeeIBANº (js/app_v2.js:9536) — nome da função com «º», nunca chamada',
                'sql' => "SELECT c.id AS colaborador_id, c.nome_completo, c.nif FROM colaboradores c
                          WHERE c.empresa_id = ? AND c.eliminado_em IS NULL AND c.estado = 'ACTIVO'
                            AND NOT EXISTS (SELECT 1 FROM coordenadas_bancarias_colaboradores b WHERE b.colaborador_id = c.id) ORDER BY c.nome_completo",
            ],
            'colaboradores_iban_invalido' => [
                'titulo' => 'IBAN de colaboradores com formato inválido', 'modulo' => 'RH', 'gravidade' => 'AVISO',
                'descricao' => 'IBAN que não é AO + 23 dígitos, NIB de 21 dígitos nem um IBAN estrangeiro: corrija-os no ecrã Coordenadas bancárias (a gravação valida também os dígitos de controlo).',
                'legado' => 'O formulário só retirava espaços; a importação validava o formato',
                'sql' => "SELECT b.colaborador_id, c.nome_completo, b.iban FROM coordenadas_bancarias_colaboradores b JOIN colaboradores c ON c.id = b.colaborador_id
                          WHERE b.empresa_id = ? AND NOT (b.iban ~ '^AO[0-9]{23}$' OR b.iban ~ '^[0-9]{21}$' OR (b.iban ~ '^[A-Z]{2}[0-9]{2}[A-Z0-9]{10,30}$' AND b.iban !~ '^AO')) ORDER BY c.nome_completo",
            ],
            'mapeamentos_salarios_duplicados' => [
                'titulo' => 'Mapeamentos contabilísticos de salários repetidos', 'modulo' => 'RH', 'gravidade' => 'INFO',
                'descricao' => 'A mesma rubrica/conta do sistema e tipo de organização mapeada mais de uma vez; a próxima gravação no ecrã Mapeamento deixa só uma.',
                'legado' => 'showPayrollMappingFixer (js/app_v2.js:10451) só acrescentava linhas',
                'sql' => "SELECT 'RUBRICA' AS tipo, infotipo_salarial_id::text AS chave, tipo_organizacao_id, avencado, COUNT(*) AS registos, string_agg(COALESCE(NULLIF(numero_conta, ''), '(vazia)'), ', ') AS contas
                          FROM mapeamentos_contabeis_rh WHERE empresa_id = ? GROUP BY 2, 3, 4 HAVING COUNT(*) > 1
                          UNION ALL
                          SELECT 'SISTEMA', codigo, tipo_organizacao_id, avencado, COUNT(*), string_agg(COALESCE(NULLIF(numero_conta, ''), '(vazia)'), ', ')
                          FROM mapeamentos_contabeis_sistema_rh WHERE empresa_id = ? GROUP BY 2, 3, 4 HAVING COUNT(*) > 1",
            ],
            'terceiros_nif_duplicado' => [
                'titulo' => 'Terceiros duplicados (mesmo NIF e tipo)', 'modulo' => 'Terceiros', 'gravidade' => 'AVISO',
                'descricao' => 'Clientes/fornecedores registados mais de uma vez com o mesmo NIF (408 no legado).', 'legado' => '—',
                'sql' => "SELECT nif, tipo, COUNT(*) AS registos, string_agg(id::text, ', ' ORDER BY id) AS ids, string_agg(DISTINCT nome, ' | ') AS nomes
                          FROM terceiros WHERE empresa_id = ? AND eliminado_em IS NULL AND nif IS NOT NULL AND nif <> '' AND nif NOT IN ('999999999', '000000000')
                          GROUP BY nif, tipo HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'terceiros_nome_com_espacos' => [
                'titulo' => 'Terceiros com espaços ou tabulações no início/fim do nome', 'modulo' => 'Terceiros', 'gravidade' => 'INFO',
                'descricao' => 'Nomes gravados pelo legado sem limpeza; afectam a ordenação e a pesquisa. Os dados novos já são limpos na entrada.',
                'legado' => '—',
                'sql' => "SELECT id, tipo, '[' || nome || ']' AS nome_gravado, nif FROM terceiros
                          WHERE empresa_id = ? AND eliminado_em IS NULL AND nome <> btrim(nome, E' \\t\\r\\n') ORDER BY id",
            ],
            'mapeamentos_rh_duplicados' => [
                'titulo' => 'Mapeamentos contabilísticos de RH ambíguos', 'modulo' => 'RH', 'gravidade' => 'ERRO',
                'descricao' => 'Mais de uma conta para o mesmo infotipo e tipo de órgão: a integração salarial fica ambígua.', 'legado' => '—',
                'sql' => "SELECT infotipo_salarial_id, tipo_organizacao_id, avencado, COUNT(*) AS registos, string_agg(DISTINCT numero_conta, ', ') AS contas,
                            string_agg(id::text, ', ' ORDER BY id) AS ids
                          FROM mapeamentos_contabeis_rh WHERE empresa_id = ? GROUP BY 1, 2, 3 HAVING COUNT(*) > 1 ORDER BY 4 DESC",
            ],
            'faturas_fornecedor_duplicadas' => [
                'titulo' => 'Facturas de fornecedor registadas em duplicado', 'modulo' => 'Compras', 'gravidade' => 'ERRO',
                'descricao' => 'O mesmo número de factura do mesmo fornecedor registado mais de uma vez (risco de pagamento em duplicado).', 'legado' => '—',
                'sql' => "SELECT fornecedor_id, numero_fatura, COUNT(*) AS registos, string_agg(id::text, ', ' ORDER BY id) AS ids
                          FROM faturas_compra WHERE empresa_id = ? AND numero_fatura IS NOT NULL
                          GROUP BY 1, 2 HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'vendas_numeracao_repetida' => [
                'titulo' => 'Documentos comerciais não fiscais com número repetido', 'modulo' => 'Vendas', 'gravidade' => 'AVISO',
                'descricao' => 'Orçamentos/proformas com o mesmo número (o legado tratava "Orçamento" e "Orcamento" como séries distintas).', 'legado' => '—',
                'sql' => "SELECT tipo_documento, numero_documento, COUNT(*) AS registos, string_agg(id::text || ' (' || COALESCE(tipo_documento_original, '') || ')', ', ') AS documentos
                          FROM vendas WHERE empresa_id = ? GROUP BY 1, 2 HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'documentos_tesouraria_sem_data' => [
                'titulo' => 'Documentos de tesouraria sem data', 'modulo' => 'Tesouraria', 'gravidade' => 'AVISO',
                'descricao' => 'O legado APAGAVA estes documentos e as suas linhas ao abrir a Tesouraria (ui_tesouraria.js:275-286). Agora são listados para decisão.',
                'legado' => 'renderTesouraria: eliminação automática',
                'sql' => 'SELECT id, referencia, tipo, valor_total FROM documentos_tesouraria WHERE empresa_id = ? AND data_documento IS NULL ORDER BY id',
            ],
            'amortizacoes_duplicadas' => [
                'titulo' => 'Amortizações duplicadas no mesmo período', 'modulo' => 'Activos', 'gravidade' => 'AVISO',
                'descricao' => 'O legado apagava duplicados e recalculava o acumulado de TODAS as empresas ao abrir Activos (ui_assets.js:3-37). Agora são listados.',
                'legado' => 'renderAssets: "correcção" automática',
                'sql' => "SELECT ativo_imobilizado_id, periodo_codigo, COUNT(*) AS registos, string_agg(id::text, ', ' ORDER BY id) AS ids, SUM(valor) AS valor_total
                          FROM amortizacoes_ativos WHERE empresa_id = ? GROUP BY 1, 2 HAVING COUNT(*) > 1 ORDER BY 3 DESC",
            ],
            'contas_por_omissao' => [
                'titulo' => 'Contas por omissão de clientes/produtos (3112, 71.1, 34.5)', 'modulo' => 'Vendas', 'gravidade' => 'INFO',
                'descricao' => 'O legado removia estas contas por omissão uma vez por empresa ao abrir Vendas (ui_sales.js:366-393). Listadas para revisão manual.',
                'legado' => 'Limpeza automática de contas por omissão',
                'sql' => "SELECT 'terceiros' AS origem, id, nome AS registo, codigo_conta FROM terceiros WHERE empresa_id = ? AND codigo_conta IN ('3112')
                          ORDER BY id",
            ],
        ];
    }

    /** @return list<array<string, mixed>> resumo de todas as validações da empresa activa */
    public function resumo(): array
    {
        $empresa = $this->contexto->obrigatorio();
        $saida = [];
        foreach ($this->definicoes() as $codigo => $d) {
            $n = (int) DB::selectOne("SELECT COUNT(*) AS n FROM ({$d['sql']}) v", array_fill(0, substr_count($d['sql'], '?'), $empresa))->n;
            $saida[] = ['codigo' => $codigo] + array_diff_key($d, ['sql' => true]) + ['ocorrencias' => $n];
        }

        return $saida;
    }

    /** @return array<string, mixed> detalhe (linhas) de uma validação */
    public function detalhe(string $codigo, int $limite = 500): array
    {
        $d = $this->definicoes()[$codigo] ?? throw new ErroNegocio("Validação desconhecida: {$codigo}", 'VALIDACAO_DESCONHECIDA', 404);
        $linhas = DB::select("SELECT * FROM ({$d['sql']}) v LIMIT ".max(1, min($limite, 5000)), array_fill(0, substr_count($d['sql'], '?'), $this->contexto->obrigatorio()));

        return ['codigo' => $codigo] + array_diff_key($d, ['sql' => true]) + ['linhas' => $linhas, 'total_mostrado' => count($linhas)];
    }
}
