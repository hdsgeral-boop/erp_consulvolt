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
            'pos_sessoes_abertas_antigas' => [
                'titulo' => 'POS: sessões abertas de dias anteriores', 'modulo' => 'POS', 'gravidade' => 'AVISO',
                'descricao' => 'Sessões de caixa por fechar desde um dia anterior: as vendas continuam a entrar nelas e o Z fica com vários dias. Feche-as (fecho Z).',
                'legado' => 'O legado só avisava ao abrir o POS (js/pos_gestao.js:147)',
                'sql' => "SELECT s.id AS sessao_id, s.codigo_sessao, s.codigo_terminal, s.nome_operador, s.aberto_em FROM sessoes_pos s
                          WHERE s.empresa_id = ? AND s.estado = 'ABERTA' AND s.aberto_em < date_trunc('day', now()) ORDER BY s.aberto_em",
            ],
            'pos_sessoes_por_integrar' => [
                'titulo' => 'POS: sessões fechadas por integrar ou com desvio por deliberar', 'modulo' => 'POS', 'gravidade' => 'AVISO',
                'descricao' => 'Sessões com o Z feito cujas vendas ainda não estão na contabilidade, ou cujo desvio de caixa acima da tolerância aguarda decisão.',
                'legado' => 'Painel de integração e desvios (js/pos_prestacao.js)',
                'sql' => "SELECT s.id AS sessao_id, s.numero_z, s.codigo_terminal, s.fechado_em, s.total_vendas, s.estado_contabilizacao, s.estado_desvio, s.desvio FROM sessoes_pos s
                          WHERE s.empresa_id = ? AND s.estado = 'FECHADA' AND (s.estado_contabilizacao = 'PENDENTE' OR s.estado_desvio = 'PENDENTE') ORDER BY s.fechado_em",
            ],
            'pos_sessoes_por_prestar' => [
                'titulo' => 'POS: sessões integradas com prestação de contas por fazer', 'modulo' => 'POS', 'gravidade' => 'AVISO',
                'descricao' => 'O numerário, o TPA e as transferências ainda estão nas contas transitórias: registe a prestação de contas (folha de caixa / recebimentos).',
                'legado' => 'Separador Prestação de contas (js/pos_prestacao.js:558)',
                'sql' => "SELECT s.id AS sessao_id, s.numero_z, s.codigo_terminal, s.fechado_em, s.total_vendas, s.estado_liquidacao FROM sessoes_pos s
                          WHERE s.empresa_id = ? AND s.estado = 'FECHADA' AND s.estado_contabilizacao IN ('CONTABILIZADA', 'SEM_MOVIMENTO') AND s.estado_liquidacao IN ('PENDENTE', 'PARCIAL') ORDER BY s.fechado_em",
            ],
            'pos_liquidacoes_orfas' => [
                'titulo' => 'POS: liquidações registadas cujo movimento ou documento desapareceu ou foi anulado', 'modulo' => 'POS', 'gravidade' => 'ERRO',
                'descricao' => 'A liquidação conta como feita mas o movimento da folha de caixa não existe ou o recebimento está anulado: a transitória não fica saldada. Anule a liquidação e volte a prestá-la.',
                'legado' => 'O legado apagava linhas de caixa e documentos sem verificar a liquidação (js/pos_prestacao.js:771)',
                'sql' => "SELECT l.id AS liquidacao_id, l.numero_z, l.chave_item, l.alvo, l.movimento_caixa_id, l.documento_tesouraria_id, d.estado AS estado_documento FROM liquidacoes_pos l
                          LEFT JOIN movimentos_caixa m ON m.id = l.movimento_caixa_id LEFT JOIN documentos_tesouraria d ON d.id = l.documento_tesouraria_id
                          WHERE l.empresa_id = ? AND l.estado = 'REGISTADO' AND ((l.alvo = 'FOLHA_CAIXA' AND m.id IS NULL) OR (l.alvo = 'TESOURARIA' AND (d.id IS NULL OR d.estado = 'ANULADO'))) ORDER BY l.id",
            ],
            'pos_tpa_talao_difere' => [
                'titulo' => 'POS: talão do TPA diferente do sistema', 'modulo' => 'POS', 'gravidade' => 'AVISO',
                'descricao' => 'A prestação regulariza a transitória pelo valor do sistema; a diferença para o talão tem de ser conferida com o extracto bancário e regularizada manualmente.',
                'legado' => 'O legado só avisava no modal de liquidação (js/pos_prestacao.js:661)',
                'sql' => "SELECT s.id AS sessao_id, s.numero_z, f->>'nome' AS meio, (f->>'valor_sistema')::numeric AS valor_sistema, (f->>'valor_talao')::numeric AS valor_talao,
                          (f->>'diferenca')::numeric AS diferenca
                          FROM sessoes_pos s CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(s.fechos_tpa) = 'array' THEN s.fechos_tpa ELSE '[]'::jsonb END) f
                          WHERE s.empresa_id = ? AND COALESCE((f->>'diferenca')::numeric, 0) <> 0 ORDER BY s.fechado_em",
            ],
            'hotel_estadias_saida_atrasada' => [
                'titulo' => 'Hotelaria: estadias abertas com a saída prevista ultrapassada', 'modulo' => 'POS', 'gravidade' => 'AVISO',
                'descricao' => 'O hóspede já devia ter saído (saída prevista + tolerância do terminal): faça o check-out (recálculo ou manter o contratado) ou corrija a estadia.',
                'legado' => 'O mapa de quartos só assinalava «atrasado» no ecrã (js/hotelaria.js:229)',
                'sql' => "SELECT e.id AS estadia_id, e.nome_quarto, e.entrada_em, e.saida_prevista_em FROM estadias_hotel e LEFT JOIN terminais_pos t ON t.id = e.terminal_pos_id
                          WHERE e.empresa_id = ? AND e.estado = 'ABERTA' AND e.saida_prevista_em + make_interval(mins => COALESCE(t.hotel_tolerancia_atraso_min, 60)) < now() ORDER BY e.saida_prevista_em",
            ],
            'pos_armazem_vendas_por_contabilizar' => [
                'titulo' => 'POS de armazém: vendas ao balcão com CMV por contabilizar', 'modulo' => 'Logística', 'gravidade' => 'AVISO',
                'descricao' => 'A guia saiu do stock mas o custo não foi lançado (contas da logística em falta ou exercício fechado na emissão). Contabilize-a em Logística › Guias de saída.',
                'legado' => 'O legado lançava num diário adivinhado ou em nenhum (js/ui_pos_armazem.js:492-496)',
                'sql' => "SELECT g.id AS guia_id, g.numero_documento, g.data, SUM(i.valor_kz) AS valor FROM guias_saida g JOIN itens_guia_saida i ON i.guia_saida_id = g.id
                          WHERE g.empresa_id = ? AND g.tipo = 'VENDA_BALCAO' AND g.estado <> 'ANULADA' AND NOT COALESCE(g.contabilizado, false)
                          GROUP BY g.id HAVING SUM(i.valor_kz) > 0 ORDER BY g.data",
            ],
            'lavandaria_servicos_sem_conta_iva' => [
                'titulo' => 'Lavandaria: serviços com IVA sem conta de IVA liquidado', 'modulo' => 'POS', 'gravidade' => 'ERRO',
                'descricao' => 'Serviços e taxas de lavandaria com IVA > 0 sem conta de IVA liquidado própria nem «IVA liquidado» nas contas de vendas: a integração da sessão POS falha.',
                'legado' => "Conta fixa '34.5.3' em linhasIntegracao (js/lavandaria.js:2388)",
                'sql' => "SELECT p.id AS produto_id, p.codigo, p.nome, p.taxa_imposto FROM produtos p
                          WHERE p.empresa_id = ? AND p.lavandaria_grupo IS NOT NULL AND p.eliminado_em IS NULL AND p.taxa_imposto > 0
                            AND COALESCE(p.conta_iva_liquidado, p.conta_iva, '') = ''
                            AND NOT EXISTS (SELECT 1 FROM configuracoes_contabeis_vendas c WHERE c.empresa_id = p.empresa_id AND c.chave = 'iva_vendas' AND COALESCE(c.codigo_conta, '') <> '')
                          ORDER BY p.codigo",
            ],
            'lavandaria_servicos_conta_nao_62' => [
                'titulo' => 'Lavandaria: serviços sem conta de proveitos da classe 62', 'modulo' => 'POS', 'gravidade' => 'AVISO',
                'descricao' => 'Os serviços de lavandaria/alfaiataria creditam a conta de proveitos do produto (classe 62).',
                'legado' => 'Validação só no ecrã (js/lavandaria.js:1427)',
                'sql' => "SELECT id AS produto_id, codigo, nome, codigo_conta FROM produtos WHERE empresa_id = ? AND lavandaria_grupo IN ('LAVANDARIA','ALFAIATARIA')
                          AND eliminado_em IS NULL AND COALESCE(codigo_conta, '') NOT LIKE '62%' ORDER BY codigo",
            ],
            'lavandaria_clientes_sem_conta' => [
                'titulo' => 'Lavandaria: clientes sem conta contabilística', 'modulo' => 'POS', 'gravidade' => 'ERRO',
                'descricao' => 'Clientes com ordens de lavandaria sem conta própria e sem «conta de clientes por omissão»: não se emitem facturas nem se integra a sessão.',
                'legado' => "Conta fixa '31.1' (js/lavandaria.js:2386)",
                'sql' => "SELECT DISTINCT t.id AS terceiro_id, t.nome FROM pedidos_lavandaria o JOIN terceiros t ON t.id = o.cliente_id
                          WHERE o.empresa_id = ? AND COALESCE(t.codigo_conta, '') = ''
                            AND NOT EXISTS (SELECT 1 FROM configuracoes_contabeis_vendas c WHERE c.empresa_id = o.empresa_id AND c.chave = 'clientes_default' AND COALESCE(c.codigo_conta, '') <> '')",
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
