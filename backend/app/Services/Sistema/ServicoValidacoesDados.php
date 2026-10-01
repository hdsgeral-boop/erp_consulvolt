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
            'ativos_vida_esgotada_por_amortizar' => [
                'titulo' => 'Activos com a vida útil esgotada e valor por amortizar', 'modulo' => 'Activos', 'gravidade' => 'AVISO',
                'descricao' => 'O legado parava no fim da vida útil sem absorver o resto (arredondamentos ou amortização inicial migrada); agora o último mês absorve-o, mas estes já passaram esse mês.',
                'legado' => 'processAmortizationCalculations (ui_assets.js:1356): quota nunca ajustada no último mês',
                'sql' => "SELECT a.id AS ativo_id, a.codigo, a.data_aquisicao, a.vida_util, a.valor_aquisicao - COALESCE(a.valor_residual, 0) - COALESCE(a.amortizacao_acumulada, 0) AS por_amortizar
                          FROM ativos_imobilizados a WHERE a.empresa_id = ? AND a.eliminado_em IS NULL AND a.estado = 'ACTIVO' AND a.vida_util > 0
                           AND date_trunc('month', a.data_aquisicao) + make_interval(months => a.vida_util) <= date_trunc('month', current_date)
                           AND a.valor_aquisicao - COALESCE(a.valor_residual, 0) - COALESCE(a.amortizacao_acumulada, 0) > 0 ORDER BY 5 DESC",
            ],
            'ativos_sem_categoria_ou_contas' => [
                'titulo' => 'Activos sem categoria ou com categoria sem contas de amortização', 'modulo' => 'Activos', 'gravidade' => 'ERRO',
                'descricao' => 'Não se integram amortizações destes activos. O legado lançava em 73.1/18.1 (inexistentes) e apagava categorias em uso.',
                'legado' => 'ui_assets.js:1407 (fallback 73.1/18.1), deleteAssetCategory sem verificação',
                'sql' => "SELECT a.id AS ativo_id, a.codigo, a.categoria_ativo_id, c.nome AS categoria, c.conta_gasto, c.conta_amortizacao_acumulada
                          FROM ativos_imobilizados a LEFT JOIN categorias_ativos c ON c.id = a.categoria_ativo_id AND c.eliminado_em IS NULL
                          WHERE a.empresa_id = ? AND a.eliminado_em IS NULL AND a.estado <> 'ABATIDO'
                           AND (c.id IS NULL OR COALESCE(c.conta_gasto, '') = '' OR COALESCE(c.conta_amortizacao_acumulada, '') = '') ORDER BY a.codigo",
            ],
            'amortizacoes_integradas_sem_lancamento' => [
                'titulo' => 'Períodos de amortização integrados sem lançamento no diário AM', 'modulo' => 'Activos', 'gravidade' => 'AVISO',
                'descricao' => 'Quotas marcadas como contabilizadas cujo lançamento AM-MM-AAAA não existe (ou foi estornado fora do módulo).',
                'legado' => 'Descontabilizar no diário apagava as linhas AM (ui_lancamentos.js:2368-2371)',
                'sql' => "SELECT x.periodo_codigo, x.valor FROM (SELECT periodo_codigo, SUM(valor) AS valor FROM amortizacoes_ativos WHERE empresa_id = ? AND contabilizado GROUP BY 1) x
                          WHERE NOT EXISTS (SELECT 1 FROM lancamentos_contabeis l JOIN diarios_contabeis d ON d.id = l.diario_id AND d.codigo = 'AM'
                                            WHERE l.empresa_id = ? AND l.numero_documento = 'AM-' || x.periodo_codigo AND l.estorno_de_id IS NULL AND l.estornado_por_id IS NULL)
                          ORDER BY 1",
            ],
            'ad_periodos_sem_lancamento' => [
                'titulo' => 'Acréscimos/diferimentos contabilizados sem lançamento activo', 'modulo' => 'Acréscimos', 'gravidade' => 'ERRO',
                'descricao' => 'Períodos CONTABILIZADOS cujo lançamento não existe ou foi estornado fora do módulo.', 'legado' => 'descontabilizar apagava as linhas (ad_dados.js:339-343)',
                'sql' => "SELECT p.id AS periodo_id, p.item_acrescimo_diferimento_id AS item_id, p.tipo, p.periodo, p.numero_lan FROM periodos_lancamento_acrescimos p
                          WHERE p.empresa_id = ? AND p.estado = 'CONTABILIZADO' AND p.valor > 0 AND NOT EXISTS (SELECT 1 FROM lancamentos_contabeis l WHERE l.empresa_id = p.empresa_id
                          AND l.diario_id = p.diario_id AND l.numero_lan = p.numero_lan AND l.estorno_de_id IS NULL AND l.estornado_por_id IS NULL) ORDER BY p.id",
            ],
            'ad_acrescimos_sem_documento' => [
                'titulo' => 'Acréscimos sem documento real depois da data limite', 'modulo' => 'Acréscimos', 'gravidade' => 'AVISO',
                'descricao' => 'Acréscimos ACTIVOS cuja data limite já passou: regularize com a factura ou anule.', 'legado' => 'Alerta da proposta (ad_dados.js:263-265)',
                'sql' => "SELECT id AS item_id, descricao, valor, data_limite FROM itens_acrescimos_diferimentos WHERE empresa_id = ? AND tipo = 'ACRESCIMO' AND estado = 'ACTIVO'
                          AND data_limite < CURRENT_DATE ORDER BY data_limite",
            ],
            'crm_oportunidades_etapa_inexistente' => [
                'titulo' => 'CRM: oportunidades numa etapa que não existe no funil', 'modulo' => 'CRM', 'gravidade' => 'ERRO',
                'descricao' => 'A etapa da oportunidade não consta das etapas do funil.', 'legado' => 'gravarPipeline só verificava as etapas removidas (crm_dados.js:100-106)',
                'sql' => "SELECT o.id AS oportunidade_id, o.etapa_codigo, o.funil_vendas_crm_id FROM oportunidades_venda_crm o JOIN funis_vendas_crm f ON f.id = o.funil_vendas_crm_id
                          WHERE o.empresa_id = ? AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(f.etapas) e WHERE e->>'id' = o.etapa_codigo) ORDER BY o.id",
            ],
            'crm_vendas_cliente_divergente' => [
                'titulo' => 'CRM: documentos de venda ligados a oportunidades de outro cliente', 'modulo' => 'CRM', 'gravidade' => 'AVISO',
                'descricao' => 'O cliente do documento difere do terceiro da conta CRM da oportunidade.', 'legado' => 'ligarVenda (crm_dados.js:316)',
                'sql' => 'SELECT v.id AS venda_id, v.numero_documento, o.id AS oportunidade_id FROM vendas v JOIN oportunidades_venda_crm o ON o.id = v.oportunidade_crm_id
                          JOIN contas_crm c ON c.id = o.conta_crm_id WHERE v.empresa_id = ? AND (c.terceiro_id IS NULL OR c.terceiro_id <> v.cliente_id) ORDER BY v.id',
            ],
            'projetos_horas_fora_da_equipa' => [
                'titulo' => 'Projectos: horas de quem não é colaborador interno da equipa', 'modulo' => 'Projectos', 'gravidade' => 'AVISO',
                'descricao' => 'O legado gravava o id do membro externo como colaborador (1 caso migrado).', 'legado' => 'showTimesheetModal, js/ui_projects.js:3312',
                'sql' => 'SELECT f.id AS folha_id, f.projeto_id, f.tarefa_projeto_id, f.colaborador_id, f.data, f.horas FROM folhas_horas_projeto f WHERE f.empresa_id = ?
                          AND NOT EXISTS (SELECT 1 FROM membros_equipa_projeto m JOIN equipas_projeto e ON e.id = m.equipa_projeto_id WHERE e.projeto_id = f.projeto_id AND m.colaborador_id = f.colaborador_id)
                          ORDER BY f.id',
            ],
            'projetos_autos_sem_factura' => [
                'titulo' => 'Projectos: autos de subempreitada cuja factura não existe ou é de outro projecto/fornecedor', 'modulo' => 'Projectos', 'gravidade' => 'AVISO',
                'descricao' => 'Facturas AUTO apagadas no legado ou ids reutilizados; o custo conta pela factura, não pela linha.', 'legado' => 'executeReview, js/ui_projects.js:2714-2738',
                'sql' => "SELECT l.id AS linha_id, r.projeto_id, r.mes, r.ano, l.terceiro_id, l.tarefa_projeto_id, l.valor_calculado, l.documento_gerado_id FROM linhas_revisao_projeto l
                          JOIN revisoes_mensais_projeto r ON r.id = l.revisao_mensal_projeto_id WHERE l.empresa_id = ? AND l.tipo = 'SUBEMPREITADA' AND l.documento_gerado_id IS NOT NULL
                          AND NOT EXISTS (SELECT 1 FROM faturas_compra f WHERE f.empresa_id = l.empresa_id AND f.id::text = l.documento_gerado_id AND f.projeto_id = r.projeto_id AND f.fornecedor_id = l.terceiro_id)
                          ORDER BY l.id",
            ],
            'projetos_tarefas_datas_invertidas' => [
                'titulo' => 'Projectos: tarefas com fim antes do início', 'modulo' => 'Projectos', 'gravidade' => 'INFO',
                'descricao' => 'O legado não validava as datas (2 casos migrados).', 'legado' => 'saveTask, js/ui_projects.js:1254',
                'sql' => 'SELECT t.id AS tarefa_id, t.projeto_id, t.nome, t.data_inicio, t.data_fim FROM tarefas_projeto t WHERE t.empresa_id = ? AND t.data_fim < t.data_inicio ORDER BY t.id',
            ],
            'linhas_sem_nota_demonstracao' => [
                'titulo' => 'Linhas sem nota às demonstrações', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Linhas (fora da classe 9) sem nota DEMO: não entram no Balanço/DR e desequilibram o Balanço ("Movimentos por mapear").',
                'legado' => 'Alerta do Balanço em ui_reports.js:1048-1062',
                'sql' => "SELECT l.codigo_conta, COUNT(*) AS linhas, SUM(CASE WHEN l.tipo_dc='D' THEN l.valor ELSE -l.valor END) AS saldo, MIN(l.id) AS linha_id
                          FROM lancamentos_contabeis l LEFT JOIN notas_demonstracao_resultados n ON n.id = l.nota_demonstracao_id AND n.empresa_id = l.empresa_id
                          WHERE l.empresa_id = ? AND n.id IS NULL AND l.codigo_conta NOT LIKE '9%' GROUP BY l.codigo_conta ORDER BY 2 DESC",
            ],
            'notas_demonstracao_fora_da_estrutura' => [
                'titulo' => 'Notas DEMO com código fora da estrutura do PGC', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Linhas com notas cujo código não é 4–35 (nem 14.1): o legado descartava-as em silêncio.',
                'legado' => 'processBalanco, ui_reports.js:863-914',
                'sql' => "SELECT trim(n.codigo) AS nota, COUNT(*) AS linhas, MIN(l.id) AS linha_id FROM lancamentos_contabeis l
                          JOIN notas_demonstracao_resultados n ON n.id = l.nota_demonstracao_id
                          WHERE l.empresa_id = ? AND trim(n.codigo) NOT IN ('4','5','6','7','8','9','10','11','12','13','14','14.1','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31','32','33','34','35')
                          GROUP BY 1 ORDER BY 2 DESC",
            ],
            'notas_demonstracao_codigo_repetido' => [
                'titulo' => 'Notas DEMO com o mesmo código', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Duas notas com o mesmo código (ex.: "15" curto e médio/longo prazo; devia ser 15 e 20).',
                'legado' => 'Tabelas auxiliares sem unicidade',
                'sql' => 'SELECT trim(codigo) AS codigo, COUNT(*) AS notas, MIN(id) AS nota_id FROM notas_demonstracao_resultados WHERE empresa_id = ? GROUP BY 1 HAVING COUNT(*) > 1',
            ],
            'encerramento_exercicio_encerrado_com_resultados' => [
                'titulo' => 'Exercícios encerrados com as classes 6/7 por apurar', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Ano com cadeado (closed_year) cujas classes 6 ou 7 não estão a zero (ex.: resíduo do arredondamento ADR-022).',
                'legado' => 'A validação comparava floats com tolerância 0,001 (ui_closing.js:1428)',
                'sql' => "SELECT extract(year from l.data_documento)::int AS ano,
                                 SUM(CASE WHEN l.codigo_conta LIKE '6%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS classe_6,
                                 SUM(CASE WHEN l.codigo_conta LIKE '7%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) AS classe_7
                          FROM lancamentos_contabeis l WHERE l.empresa_id = ? AND EXISTS (SELECT 1 FROM configuracoes_sistema c
                               WHERE c.chave = 'closed_year_' || l.empresa_id || '_' || extract(year from l.data_documento)::int AND lower(trim(c.valor)) IN ('true','1'))
                          GROUP BY 1 HAVING SUM(CASE WHEN l.codigo_conta LIKE '6%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) <> 0
                              OR SUM(CASE WHEN l.codigo_conta LIKE '7%' THEN CASE WHEN l.tipo_dc = 'D' THEN l.valor ELSE -l.valor END ELSE 0 END) <> 0 ORDER BY 1",
            ],
            'consolidacao_holding_desactualizada' => [
                'titulo' => 'Consolidação desactualizada', 'modulo' => 'Contabilidade', 'gravidade' => 'AVISO',
                'descricao' => 'Lançamentos das empresas do grupo gravados depois da última consolidação e datados até à data de fim: consolide de novo.',
                'legado' => 'Aviso do Mapa de Consolidação (consolidacao.js:1209)',
                'sql' => 'SELECT g.id AS grupo_id, e.data_fim, e.executado_em, COUNT(l.id) AS linhas FROM grupos_consolidacao g
                          JOIN execucoes_consolidacao e ON e.id = g.ultima_execucao_id JOIN membros_consolidacao m ON m.grupo_consolidacao_id = g.id
                          JOIN lancamentos_contabeis l ON l.empresa_id = m.empresa_membro_id AND l.data_documento <= e.data_fim AND l.criado_em > e.executado_em
                          WHERE g.empresa_id = ? GROUP BY g.id, e.data_fim, e.executado_em ORDER BY g.id',
            ],
            'texto_corrompido_lancamentos' => [
                'titulo' => 'Descrições com texto corrompido (mojibake)', 'modulo' => 'Sistema', 'gravidade' => 'INFO',
                'descricao' => 'Acentos estragados gravados (ex.: "GestÃ£o") ou caracteres de substituição. O legado reparava-os em massa (reparar_texto.js); aqui só se listam.',
                'legado' => 'repararTextoCorrompidoBD, js/reparar_texto.js',
                'sql' => "SELECT id AS linha_id, numero_lan, data_documento, descricao FROM lancamentos_contabeis WHERE empresa_id = ? AND descricao ~ '\u{00C3}[\u{0080}-\u{00BF}]|\u{00C2}[\u{00A0}-\u{00BF}]|\u{FFFD}' ORDER BY id",
            ],
            'reconciliacoes_tesouraria_orfas' => [
                'titulo' => 'Linhas 43/45 reconciliadas sem extracto bancário', 'modulo' => 'Tesouraria', 'gravidade' => 'AVISO',
                'descricao' => 'Código de reconciliação que não pertence a uma reconciliação CONCILIADO_BANCO (correcção: Manutenção de dados › LIMPAR_RECONCILIACOES_ORFAS).',
                'legado' => 'limparReconciliacoesTesouraria, ui_rotinas.js:1225',
                'sql' => "SELECT l.id AS linha_id, l.codigo_conta, l.reconciliacao_codigo, l.data_documento, l.valor FROM lancamentos_contabeis l WHERE l.empresa_id = ? AND l.reconciliacao_codigo IS NOT NULL
                          AND (l.codigo_conta LIKE '43%' OR l.codigo_conta LIKE '45%') AND NOT EXISTS (SELECT 1 FROM reconciliacoes_bancarias r WHERE r.empresa_id = l.empresa_id
                          AND r.reconciliacao_codigo = l.reconciliacao_codigo AND r.estado = 'CONCILIADO_BANCO') ORDER BY l.id",
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
