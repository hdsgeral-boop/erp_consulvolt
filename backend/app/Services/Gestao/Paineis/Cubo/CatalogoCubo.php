<?php

namespace App\Services\Gestao\Paineis\Cubo;

/**
 * Lista branca da Análise Dinâmica (CONJUNTOS, ui_cubo.js:100-338): conjuntos de dados,
 * dimensões e medidas, com as expressões SQL fixas no servidor. O cliente só escolhe identificadores desta lista; nenhum texto
 * do pedido entra no SQL (os valores dos filtros vão sempre por parâmetros).
 *
 * Cada conjunto: nome, vistas (consulta de pelo menos um ecrã do módulo, como `permitido`, ui_cubo.js:39-44), alias da tabela
 * base (empresa_id), dimensões [rótulo, expressão, junções], medidas [rótulo, expressão, formato, junções], junções e a visão
 * padrão. A fonte (FROM + WHERE por empresa e período) é montada pelo ServicoCubo.
 *
 * Regras de cada conjunto (paridade, com as correcções assinaladas):
 *   - contabilidade: linhas do diário sem a classe 9; correcção: sem os lançamentos de apuramento (períodos 13/14), salvo
 *     `incluir_apuramento` (regra dos mapas — com eles o saldo das classes 6/7 do ano é zero);
 *   - vendas: documentos não anulados, linha a linha (sem linhas: os totais do documento); NC com sinal negativo; correcção: o
 *     valor sem IVA da linha passa a ser o da linha (com desconto) — o legado usava quantidade × preço e ignorava o desconto;
 *   - compras: facturas de fornecedor, linha a linha (itens_compra da factura); correcção: anuladas excluídas;
 *   - tesouraria: linhas dos recebimentos/pagamentos (perspectiva da conta financeira: contrapartida a crédito = entrada) e
 *     movimentos da folha de caixa; correcção: documentos anulados excluídos;
 *   - armazem: movimentos de stock com sinal; correcção: entrada/saída pelo sentido do movimento (o legado classificava os
 *     acertos negativos como entradas);
 *   - rh: fotografia dos processamentos FECHADO/VALIDADO (o legado recalculava cada período);
 *   - rh_rubricas (Versão Actual): uma linha por rubrica gravada em cada resultado desses processamentos;
 *
 * Versão Actual (Fase H, ADR-071): dimensões novas em todos os cubos — NIF de clientes, fornecedores, terceiros e colaboradores
 * (visíveis a quem tem acesso ao cubo), conta do papel, liquidação, categoria/natureza/código do artigo, taxa de IVA, UN e CC da
 * linha (mandam sobre as do cabeçalho), meio de pagamento, função/órgão do colaborador, tipo de activo… — e medidas de contagem
 * (Linhas, Documentos, Facturas, Movimentos, Colaboradores, Amortizações). As contagens por documento usam ROW_NUMBER() sobre as
 * linhas que passam os filtros, para a soma contar documentos e não linhas;
 *   - projetos: orçamento, custos, proveitos e compromissos pela regra única do razão analítico (ADR-052), aditamentos aprovados
 *     e horas;
 *   - ativos: quotas de amortização (contabilizadas ou não).
 */
final class CatalogoCubo
{
    public const AGREGACOES = ['soma' => 'SUM', 'media' => 'AVG', 'minimo' => 'MIN', 'maximo' => 'MAX', 'contagem' => 'COUNT'];

    private const MESES = "ARRAY['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']";

    /** @return array<string, array<string, mixed>> */
    public static function conjuntos(): array
    {
        return [
            'contabilidade' => self::contabilidade(),
            'vendas' => self::vendas(),
            'compras' => self::compras(),
            'tesouraria' => self::tesouraria(),
            'armazem' => self::armazem(),
            'rh' => self::rh(),
            'rh_rubricas' => self::rhRubricas(),
            'projetos' => self::projetos(),
            'ativos' => self::ativos(),
        ];
    }

    /** Dimensões de data (Ano, Trimestre, Mês "MM - Mmm") a partir de uma expressão de data (dimData, ui_cubo.js:31-36). */
    public static function datas(string $expr): array
    {
        return [
            'ano' => ['Ano', "COALESCE(to_char({$expr}, 'YYYY'), '(sem data)')", []],
            'trimestre' => ['Trimestre', "COALESCE('T' || EXTRACT(QUARTER FROM {$expr})::int, '(sem data)')", []],
            'mes' => ['Mês', "COALESCE(to_char({$expr}, 'MM') || ' - ' || (".self::MESES.")[EXTRACT(MONTH FROM {$expr})::int], '(sem data)')", []],
        ];
    }

    private static function contabilidade(): array
    {
        $saldo = "CASE WHEN l.tipo_dc = 'D' THEN COALESCE(l.valor, 0) ELSE -COALESCE(l.valor, 0) END";

        return [
            'nome' => 'Contabilidade — Lançamentos', 'icone' => 'livro', 'alias' => 'l', 'tabela' => 'lancamentos_contabeis l', 'data' => 'l.data_documento',
            'vistas' => ['lancamentos', 'relatorios_contabeis', 'contabilidade', 'contab_mapa_balancete'],
            'condicao' => "l.codigo_conta IS NOT NULL AND l.codigo_conta NOT LIKE '9%'",
            'padrao' => ['linhas' => ['classe', 'conta2'], 'colunas' => ['mes'], 'medidas' => [['medida' => 'saldo', 'agregacao' => 'soma']]],
            'juncoes' => [
                'd' => 'LEFT JOIN diarios_contabeis d ON d.id = l.diario_id',
                'pc' => 'LEFT JOIN plano_contas pc ON pc.empresa_id = l.empresa_id AND pc.codigo = l.codigo_conta AND pc.eliminado_em IS NULL',
                't' => 'LEFT JOIN terceiros t ON t.id = l.terceiro_id',
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = l.unidade_negocio_id',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = l.centro_custo_id',
                'pr' => 'LEFT JOIN projetos pr ON pr.id = l.projeto_id',
                'eo' => 'LEFT JOIN empresas eo ON eo.id = l.empresa_origem_id',
            ],
            'dimensoes' => self::datas('l.data_documento') + [
                'diario' => ['Diário', "COALESCE(d.codigo, '-')", ['d']],
                'classe' => ['Classe', 'left(l.codigo_conta, 1)', []],
                'conta2' => ['Conta (2 díg.)', 'left(l.codigo_conta, 2)', []],
                'conta3' => ['Conta (3 díg.)', 'left(l.codigo_conta, 3)', []],
                'conta' => ['Conta', 'l.codigo_conta', []],
                'descricao_conta' => ['Descrição da conta', "COALESCE(pc.descricao, '')", ['pc']],
                'terceiro' => ['Terceiro', "COALESCE(t.nome, '(sem terceiro)')", ['t']],
                'nif_terceiro' => ['NIF do terceiro', self::nif('t'), ['t']],
                'documento' => ['Documento', "COALESCE(l.numero_documento, '-')", []],
                'descricao_linha' => ['Descrição da linha', "COALESCE(NULLIF(TRIM(l.descricao), ''), '-')", []],
                'numero_lan' => ['Nº de lançamento', "COALESCE(NULLIF(TRIM(l.numero_lan), ''), '-')", []],
                'origem' => ['Origem', "COALESCE(NULLIF(TRIM(l.tipo_documento_origem), ''), 'Manual')", []],
                'natureza' => ['Natureza', "CASE WHEN l.tipo_dc = 'D' THEN 'Débito' ELSE 'Crédito' END", []],
                'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
                'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
                'projeto' => ['Projecto', "COALESCE(pr.codigo || ' - ' || pr.nome, '-')", ['pr']],
                'moeda' => ['Moeda original', "COALESCE(l.codigo_moeda, 'AOA')", []],
                'empresa' => ['Empresa', '{empresa_origem}', ['eo'], 'holding' => true],
                'tipo_linha' => ['Tipo de linha', "CASE WHEN l.tipo_consolidacao = 'ELIMINACAO' THEN 'Eliminação intragrupo' WHEN l.tipo_consolidacao = 'CONVERSAO' THEN 'Conversão cambial'
                    WHEN l.empresa_origem_id IS NULL THEN 'Lançado na holding' ELSE 'Agregação' END", [], 'holding' => true],
                'empresa_eliminacao' => ['Empresa da eliminação', "CASE WHEN l.tipo_consolidacao = 'ELIMINACAO' THEN {empresa_origem_nome} ELSE '-' END", ['eo'], 'holding' => true],
            ],
            'medidas' => [
                'debito' => ['Débito', "CASE WHEN l.tipo_dc = 'D' THEN COALESCE(l.valor, 0) ELSE 0 END", 'kz', []],
                'credito' => ['Crédito', "CASE WHEN l.tipo_dc = 'D' THEN 0 ELSE COALESCE(l.valor, 0) END", 'kz', []],
                'saldo' => ['Saldo (D−C)', $saldo, 'kz', []],
                'valor_moeda' => ['Valor na moeda (D−C)', "CASE WHEN COALESCE(l.codigo_moeda, 'AOA') <> 'AOA' THEN CASE WHEN l.tipo_dc = 'D' THEN 1 ELSE -1 END * COALESCE(l.valor_moeda, 0) ELSE 0 END", 'moeda', []],
                'linhas' => ['Linhas', '1', 'num', []],
            ],
            'opcoes' => ['incluir_apuramento'],
        ];
    }

    private static function vendas(): array
    {
        $sinal = "(CASE WHEN v.tipo_documento = 'NC' THEN -1 ELSE 1 END)";
        $liq = 'CASE WHEN i.id IS NULL THEN COALESCE(v.total_liquido, 0) ELSE COALESCE(i.total_linha, COALESCE(i.quantidade, 0) * COALESCE(i.preco_unitario, 0) * (1 - COALESCE(i.percentagem_desconto, 0) / 100)) END';
        $total = "CASE WHEN i.id IS NULL THEN COALESCE(v.total_bruto, 0) ELSE COALESCE(i.total, ({$liq}) * (1 + COALESCE(i.taxa_imposto, 0) / 100)) END";

        return [
            'nome' => 'Vendas — Documentos e artigos', 'icone' => 'carrinho', 'alias' => 'v', 'tabela' => 'vendas v LEFT JOIN itens_venda i ON i.venda_id = v.id',
            'data' => 'v.data_emissao', 'data_hora' => true,
            'vistas' => ['vendas_faturacao', 'vendas_relatorios', 'pos'],
            'padrao' => ['linhas' => ['cliente'], 'colunas' => ['mes'], 'medidas' => [['medida' => 'total', 'agregacao' => 'soma']]],
            'condicao' => "COALESCE(v.estado, '') NOT IN ('ANULADO', 'CANCELADO')",
            'juncoes' => [
                't' => 'LEFT JOIN terceiros t ON t.id = v.cliente_id',
                'et' => self::juncaoProvincia('t', 'et'),
                'pr' => 'LEFT JOIN projetos pr ON pr.id = v.projeto_id',
                'p' => 'LEFT JOIN produtos p ON p.id = i.produto_id',
                'cp' => 'LEFT JOIN categorias_produtos cp ON cp.id = p.categoria_produto_id',
                // a unidade e o centro da LINHA mandam sobre os do cabeçalho (Versão Actual, js/shared/linha_un_cc.js)
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = COALESCE(i.unidade_negocio_id, v.unidade_negocio_id)',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = COALESCE(i.centro_custo_id, v.centro_custo_id)',
            ],
            'dimensoes' => self::datas('v.data_emissao') + [
                'tipo_documento' => ['Tipo de documento', "COALESCE(v.tipo_documento, '-')", []],
                'documento' => ['Documento', "COALESCE(v.numero_documento, '-')", []],
                'estado' => ['Estado', "COALESCE(v.estado, '-')", []],
                'cliente' => ['Cliente', "COALESCE(t.nome, 'Consumidor final')", ['t']],
                'nif_cliente' => ['NIF do cliente', self::nif('t'), ['t']],
                'provincia_cliente' => ['Província do cliente', "COALESCE(NULLIF(TRIM(et.provincia), ''), '-')", ['t', 'et']],
                'conta_cliente' => ['Conta do cliente', "COALESCE(NULLIF(TRIM(t.conta_cliente), ''), NULLIF(TRIM(t.codigo_conta), ''), '-')", ['t']],
                'contabilizado' => ['Contabilizado', "CASE WHEN v.contabilizado THEN 'Sim' ELSE 'Não' END", []],
                'liquidacao' => ['Liquidação', "CASE WHEN v.estado = 'PAGO' OR (COALESCE(v.total_bruto, 0) > 0 AND COALESCE(v.valor_pago, 0) >= COALESCE(v.total_bruto, 0) - 0.01) THEN 'Pago'
                    WHEN COALESCE(v.valor_pago, 0) > 0 THEN 'Parcial' ELSE 'Por receber' END", []],
                'moeda' => ['Moeda', "COALESCE(v.codigo_moeda, 'AOA')", []],
                'projeto' => ['Projecto', "COALESCE(pr.codigo || ' - ' || pr.nome, '-')", ['pr']],
                'artigo' => ['Artigo', "CASE WHEN i.id IS NULL THEN '(sem linhas)' ELSE COALESCE(p.nome, i.descricao, 'Artigo ' || i.produto_id) END", ['p']],
                'codigo_artigo' => ['Código do artigo', "CASE WHEN i.id IS NULL THEN '-' ELSE COALESCE(NULLIF(TRIM(p.codigo), ''), '-') END", ['p']],
                'categoria_artigo' => ['Categoria do artigo', "CASE WHEN i.id IS NULL THEN '-' ELSE COALESCE(cp.nome, '(sem categoria)') END", ['p', 'cp']],
                'natureza_artigo' => ['Natureza do artigo', "CASE WHEN i.id IS NULL THEN '-' WHEN p.movimenta_stock THEN 'Produto' ELSE 'Serviço' END", ['p']],
                'taxa_iva' => ['Taxa de IVA', "CASE WHEN i.id IS NULL THEN '-' ELSE ".self::percentagem('i.taxa_imposto').' END', []],
                'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
                'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
            ],
            'medidas' => [
                'quantidade' => ['Quantidade', "{$sinal} * COALESCE(i.quantidade, 0)", 'num', []],
                'valor_sem_iva' => ['Valor s/ IVA (Kz)', "{$sinal} * ({$liq})", 'kz', []],
                'iva' => ['IVA (Kz)', "{$sinal} * (({$total}) - ({$liq}))", 'kz', []],
                'total' => ['Total (Kz)', "{$sinal} * ({$total})", 'kz', []],
                'preco_unitario' => ['Preço unitário (Kz)', 'COALESCE(i.preco_unitario, 0)', 'kz', []],
                // 1 por documento (na primeira das suas linhas que passam os filtros): a soma conta documentos, não linhas
                'documentos' => ['Documentos', 'CASE WHEN ROW_NUMBER() OVER (PARTITION BY v.id ORDER BY i.id NULLS FIRST) = 1 THEN 1 ELSE 0 END', 'num', []],
            ],
        ];
    }

    private static function compras(): array
    {
        $liq = 'CASE WHEN i.id IS NULL THEN COALESCE(c.montante_total, 0) - COALESCE(c.total_imposto, 0) ELSE COALESCE(i.total_kz, COALESCE(i.quantidade, 0) * COALESCE(i.preco_unitario, 0)) END';
        $iva = "CASE WHEN i.id IS NULL THEN COALESCE(c.total_imposto, 0) ELSE COALESCE(i.imposto_kz, ({$liq}) * COALESCE(i.taxa_imposto, 0) / 100) END";

        return [
            'nome' => 'Compras — Facturas de fornecedor', 'icone' => 'camiao', 'alias' => 'c',
            'tabela' => "faturas_compra c LEFT JOIN itens_compra i ON i.fatura_compra_id = c.id AND i.tipo_documento_origem = 'FATURA'", 'data' => 'c.data',
            'vistas' => ['compras', 'compras_encomendas', 'compras_faturacao'],
            'padrao' => ['linhas' => ['fornecedor'], 'colunas' => ['mes'], 'medidas' => [['medida' => 'total', 'agregacao' => 'soma']]],
            'condicao' => 'c.anulado_em IS NULL',
            'juncoes' => [
                't' => 'LEFT JOIN terceiros t ON t.id = c.fornecedor_id',
                'pr' => 'LEFT JOIN projetos pr ON pr.id = c.projeto_id',
                'p' => 'LEFT JOIN produtos p ON p.id = i.produto_id',
                'cp' => 'LEFT JOIN categorias_produtos cp ON cp.id = p.categoria_produto_id',
                // a unidade e o centro da LINHA mandam sobre os do cabeçalho (Versão Actual)
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = COALESCE(i.unidade_negocio_id, c.unidade_negocio_id)',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = COALESCE(i.centro_custo_id, c.centro_custo_id)',
            ],
            'dimensoes' => self::datas('c.data') + [
                'fornecedor' => ['Fornecedor', "COALESCE(t.nome, '-')", ['t']],
                'nif_fornecedor' => ['NIF do fornecedor', self::nif('t'), ['t']],
                'conta_fornecedor' => ['Conta do fornecedor', "COALESCE(NULLIF(TRIM(t.conta_fornecedor), ''), NULLIF(TRIM(t.codigo_conta), ''), '-')", ['t']],
                'codigo_artigo' => ['Código do artigo', "CASE WHEN i.id IS NULL THEN '-' ELSE COALESCE(NULLIF(TRIM(p.codigo), ''), '-') END", ['p']],
                'categoria_artigo' => ['Categoria do artigo', "CASE WHEN i.id IS NULL THEN '-' ELSE COALESCE(cp.nome, '(sem categoria)') END", ['p', 'cp']],
                'natureza_artigo' => ['Natureza do artigo', "CASE WHEN i.id IS NULL THEN '-' WHEN p.e_ativo_imobilizado THEN 'Imobilizado' WHEN p.movimenta_stock THEN 'Existências' ELSE 'Serviço' END", ['p']],
                'taxa_iva' => ['Taxa de IVA', "CASE WHEN i.id IS NULL THEN '-' ELSE ".self::percentagem('i.taxa_imposto').' END', []],
                'fatura' => ['Factura', "COALESCE(c.numero_fatura, '-')", []],
                'estado' => ['Estado', "CASE WHEN c.contabilizado THEN 'Contabilizada' ELSE 'Pendente' END", []],
                'com_encomenda' => ['Com encomenda', "CASE WHEN c.encomenda_compra_id IS NOT NULL THEN 'Sim' ELSE 'Não' END", []],
                'moeda' => ['Moeda', "COALESCE(c.codigo_moeda, 'AOA')", []],
                'projeto' => ['Projecto', "COALESCE(pr.codigo || ' - ' || pr.nome, '-')", ['pr']],
                'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
                'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
                'artigo' => ['Artigo', "CASE WHEN i.id IS NULL THEN '(sem linhas)' ELSE COALESCE(p.nome, i.descricao, 'Artigo ' || i.produto_id) END", ['p']],
            ],
            'medidas' => [
                'quantidade' => ['Quantidade', 'COALESCE(i.quantidade, 0)', 'num', []],
                'valor_sem_iva' => ['Valor s/ IVA (Kz)', $liq, 'kz', []],
                'iva' => ['IVA (Kz)', $iva, 'kz', []],
                'total' => ['Total (Kz)', "({$liq}) + ({$iva})", 'kz', []],
                'preco_unitario' => ['Preço unitário (Kz)', 'COALESCE(i.preco_unitario, 0)', 'kz', []],
                'faturas' => ['Facturas', 'CASE WHEN ROW_NUMBER() OVER (PARTITION BY c.id ORDER BY i.id NULLS FIRST) = 1 THEN 1 ELSE 0 END', 'num', []],
            ],
        ];
    }

    private static function tesouraria(): array
    {
        $fonte = "(SELECT d.empresa_id, d.data_documento AS data, 'Tesouraria' AS origem, CASE WHEN d.tipo = 'PAGAMENTO' THEN 'Pagamento' ELSE 'Recebimento' END AS tipo,
                d.conta_financeira, it.codigo_conta AS contrapartida, it.terceiro_id, COALESCE(it.numero_documento, d.referencia, '-') AS documento, COALESCE(d.estado, '-') AS estado,
                COALESCE(it.codigo_moeda, d.codigo_moeda, 'AOA') AS moeda,
                (SELECT mp.nome FROM meios_pagamento mp WHERE mp.empresa_id = d.empresa_id AND mp.codigo_conta = d.conta_financeira ORDER BY mp.predefinido DESC NULLS LAST, mp.id LIMIT 1) AS meio,
                CASE WHEN d.integrado_em IS NOT NULL OR NULLIF(d.numero_lan_contabilizacao, '') IS NOT NULL THEN 'Sim' ELSE 'Não' END AS contabilizado,
                CASE WHEN it.tipo_dc = 'D' THEN -COALESCE(it.valor, 0) ELSE COALESCE(it.valor, 0) END AS valor
            FROM documentos_tesouraria d JOIN itens_documento_tesouraria it ON it.documento_tesouraria_id = d.id
            WHERE d.empresa_id IN ({empresas}) AND d.data_documento BETWEEN ? AND ? AND d.anulado_em IS NULL AND COALESCE(d.estado, '') <> 'ANULADO'
            UNION ALL
            SELECT m.empresa_id, m.data_documento, 'Folha de Caixa', CASE WHEN m.tipo = 'REC' THEN 'Entrada de caixa' ELSE 'Saída de caixa' END,
                s.codigo_conta, CASE WHEN m.tipo = 'REC' THEN m.conta_credito ELSE m.conta_debito END, m.terceiro_id, COALESCE(m.numero_documento, '-'),
                CASE WHEN m.contabilizado THEN 'Integrado' ELSE 'Pendente' END, COALESCE(m.codigo_moeda, 'AOA'),
                'Numerário', CASE WHEN m.contabilizado THEN 'Sim' ELSE 'Não' END,
                CASE WHEN m.tipo = 'REC' THEN 1 ELSE -1 END * COALESCE(m.valor_kz, m.valor, 0)
            FROM movimentos_caixa m JOIN sessoes_caixa s ON s.id = m.sessao_caixa_id
            WHERE m.empresa_id IN ({empresas}) AND m.data_documento BETWEEN ? AND ?) x";

        return [
            'nome' => 'Tesouraria — Recebimentos, pagamentos e caixa', 'icone' => 'banco', 'alias' => 'x', 'tabela' => $fonte, 'data' => 'x.data', 'fonte_filtrada' => 2,
            'vistas' => ['teso_gestao_pagamentos', 'teso_gestao_recebimentos', 'teso_contab_integracao', 'teso_gestao_mapas', 'teso_folha_caixa'],
            'padrao' => ['linhas' => ['conta_financeira'], 'colunas' => ['mes'], 'medidas' => [['medida' => 'valor', 'agregacao' => 'soma']]],
            'juncoes' => [
                'pf' => 'LEFT JOIN plano_contas pf ON pf.empresa_id = x.empresa_id AND pf.codigo = x.conta_financeira AND pf.eliminado_em IS NULL',
                'pc' => 'LEFT JOIN plano_contas pc ON pc.empresa_id = x.empresa_id AND pc.codigo = x.contrapartida AND pc.eliminado_em IS NULL',
                't' => 'LEFT JOIN terceiros t ON t.id = x.terceiro_id',
            ],
            'dimensoes' => self::datas('x.data') + [
                'origem' => ['Origem', 'x.origem', []],
                'tipo' => ['Tipo', 'x.tipo', []],
                'conta_financeira' => ['Conta financeira', "TRIM(COALESCE(x.conta_financeira, '') || ' ' || COALESCE(pf.descricao, ''))", ['pf']],
                'contrapartida' => ['Contrapartida', "COALESCE(x.contrapartida, '-')", []],
                'descricao_contrapartida' => ['Descrição contrapartida', "COALESCE(pc.descricao, '')", ['pc']],
                'classe_contrapartida' => ['Classe da contrapartida', "COALESCE(NULLIF(left(x.contrapartida, 1), ''), '-')", []],
                'meio_pagamento' => ['Meio de pagamento', "COALESCE(x.meio, '-')", []],
                'contabilizado' => ['Contabilizado', 'x.contabilizado', []],
                'terceiro' => ['Terceiro', "COALESCE(t.nome, '(sem terceiro)')", ['t']],
                'nif_terceiro' => ['NIF do terceiro', self::nif('t'), ['t']],
                'documento' => ['Documento', 'x.documento', []],
                'estado' => ['Estado', 'x.estado', []],
                'moeda' => ['Moeda', 'x.moeda', []],
            ],
            'medidas' => [
                'valor' => ['Valor (Kz)', 'x.valor', 'kz', []],
                'entradas' => ['Entradas (Kz)', 'GREATEST(x.valor, 0)', 'kz', []],
                'saidas' => ['Saídas (Kz)', 'GREATEST(-x.valor, 0)', 'kz', []],
                'movimentos' => ['Movimentos', '1', 'num', []],
            ],
        ];
    }

    private static function armazem(): array
    {
        $sinal = "(CASE WHEN m.sentido = 'S' THEN -1 ELSE 1 END)";

        return [
            'nome' => 'Armazém — Movimentos de stock', 'icone' => 'armazem', 'alias' => 'm', 'tabela' => 'movimentos_inventario m', 'data' => 'm.data', 'data_hora' => true,
            'vistas' => ['armazem', 'armazem_stock', 'armazem_rececoes', 'armazem_movimentos'],
            'padrao' => ['linhas' => ['artigo'], 'colunas' => ['tipo'], 'medidas' => [['medida' => 'quantidade', 'agregacao' => 'soma']]],
            'juncoes' => [
                'p' => 'LEFT JOIN produtos p ON p.id = m.produto_id',
                'cp' => 'LEFT JOIN categorias_produtos cp ON cp.id = p.categoria_produto_id',
                'a' => 'LEFT JOIN armazens a ON a.id = m.armazem_id',
                't' => 'LEFT JOIN terceiros t ON t.id = m.terceiro_id',
            ],
            'dimensoes' => self::datas('m.data') + [
                'tipo' => ['Tipo', "CASE WHEN m.sentido = 'S' THEN 'Saída' ELSE 'Entrada' END", []],
                'tipo_movimento' => ['Movimento', "COALESCE(m.tipo, '-')", []],
                'artigo' => ['Artigo', "COALESCE(p.nome, 'Artigo ' || m.produto_id)", ['p']],
                'codigo_artigo' => ['Código do artigo', "COALESCE(NULLIF(TRIM(p.codigo), ''), '-')", ['p']],
                'categoria_artigo' => ['Categoria do artigo', "COALESCE(cp.nome, '(sem categoria)')", ['p', 'cp']],
                'unidade_medida' => ['Unidade de medida', "COALESCE(NULLIF(TRIM(p.unidade_fe), ''), '-')", ['p']],
                'armazem' => ['Armazém', "COALESCE(a.nome, '-')", ['a']],
                'terceiro' => ['Terceiro', "COALESCE(t.nome, '-')", ['t']],
                'referencia' => ['Referência', "COALESCE(m.referencia, '-')", []],
                'documento' => ['Documento', "COALESCE(NULLIF(TRIM(COALESCE(m.documento_tipo, '') || ' ' || COALESCE(m.documento_id::text, '')), ''), '-')", []],
            ],
            'medidas' => [
                'quantidade' => ['Quantidade', "{$sinal} * ABS(COALESCE(m.quantidade, 0))", 'num', []],
                'entradas' => ['Entradas', "CASE WHEN m.sentido = 'S' THEN 0 ELSE ABS(COALESCE(m.quantidade, 0)) END", 'num', []],
                'saidas' => ['Saídas', "CASE WHEN m.sentido = 'S' THEN ABS(COALESCE(m.quantidade, 0)) ELSE 0 END", 'num', []],
                'custo_unitario' => ['Custo unitário (Kz)', 'COALESCE(m.preco_unitario, 0)', 'kz', []],
                'valor' => ['Valor (Kz)', "{$sinal} * ABS(COALESCE(m.valor, m.quantidade * m.preco_unitario, 0))", 'kz', []],
                'movimentos' => ['Movimentos', '1', 'num', []],
            ],
        ];
    }

    private static function rh(): array
    {
        $data = "to_date(pp.mes_ano, 'MM/YYYY')";

        return [
            'nome' => 'Recursos Humanos — Processamentos salariais', 'icone' => 'pessoas', 'alias' => 'r',
            'tabela' => 'resultados_folha_salarial r JOIN periodos_processamento_salarial pp ON pp.id = r.periodo_processamento_salarial_id', 'data' => $data, 'data_mes' => true,
            'vistas' => ['colaboradores', 'calcular', 'processamento', 'relatorios'],
            'padrao' => ['linhas' => ['colaborador'], 'colunas' => ['periodo'], 'medidas' => [['medida' => 'liquido', 'agregacao' => 'soma']]],
            'condicao' => "pp.estado IN ('FECHADO', 'VALIDADO')",
            'juncoes' => self::juncoesColaborador(),
            'dimensoes' => self::datas($data) + [
                'periodo' => ['Período', "to_char({$data}, 'YYYY-MM')", []],
                'estado_periodo' => ['Estado do período', "COALESCE(pp.estado, '-')", []],
            ] + self::dimensoesColaborador(),
            'medidas' => [
                'iliquido' => ['Ilíquido', 'COALESCE(r.bruto, 0)', 'kz', []],
                'base_inss' => ['Base INSS', 'COALESCE(r.base_inss, 0)', 'kz', []],
                'inss_trabalhador' => ['INSS trabalhador', 'COALESCE(r.inss_trabalhador, 0)', 'kz', []],
                'inss_patronal' => ['INSS empresa', 'COALESCE(r.inss_patronal, 0)', 'kz', []],
                'irt' => ['IRT', 'COALESCE(r.irt, 0)', 'kz', []],
                'descontos' => ['Descontos', 'COALESCE(r.descontos, 0)', 'kz', []],
                'liquido' => ['Líquido', 'COALESCE(r.liquido, 0)', 'kz', []],
                'custo_empresa' => ['Custo empresa', 'COALESCE(r.bruto, 0) + COALESCE(r.inss_patronal, 0)', 'kz', []],
                'dias_contratados' => ['Dias contratados', 'COALESCE(r.dias_contrato, 0)', 'num', []],
                'dias_trabalhados' => ['Dias trabalhados', 'COALESCE(r.dias_trabalhados, 0)', 'num', []],
                // rubricas gravadas no resultado (fotografia do motor salarial): horas extra = vencimentos com horas; faltas = rubricas `falta`
                'horas_extra' => ['Horas extra', self::somaRubricas('horas', "e->>'tipo' = 'VENCIMENTO' AND e->>'horas' IS NOT NULL AND COALESCE(e->>'falta', '') <> 'true'"), 'num', []],
                'horas_falta' => ['Horas de falta', self::somaRubricas('horas', "e->>'falta' = 'true'"), 'num', []],
                'valor_horas_extra' => ['Valor horas extra', self::somaRubricas('valor', "e->>'tipo' = 'VENCIMENTO' AND e->>'horas' IS NOT NULL AND COALESCE(e->>'falta', '') <> 'true'"), 'kz', []],
                'valor_faltas' => ['Valor das faltas', self::somaRubricas('valor', "e->>'falta' = 'true'"), 'kz', []],
                'subsidio_alimentacao' => ['Subsídio de alimentação', self::somaRubricas('valor', "e->>'tipo' = 'VENCIMENTO' AND lower(COALESCE(e->>'nome', '')) LIKE '%alimenta%'"), 'kz', []],
                'subsidio_transporte' => ['Subsídio de transporte', self::somaRubricas('valor', "e->>'tipo' = 'VENCIMENTO' AND lower(COALESCE(e->>'nome', '')) LIKE '%transporte%'"), 'kz', []],
                'adiantamentos' => ['Adiantamentos', self::somaRubricas('valor', "e->>'tipo' = 'DESCONTO' AND lower(COALESCE(e->>'nome', '')) LIKE '%adiantamento%'"), 'kz', []],
                'colaboradores' => ['Colaboradores', '1', 'num', []],
            ],
        ];
    }

    /**
     * Cubo «Recursos Humanos — Rubricas (infotipos)» (Versão Actual, ui_cubo.js:385-428): uma linha por rubrica de cada recibo
     * dos períodos FECHADO/VALIDADO. O legado recalculava cada período (calculatePeriodData); aqui lêem-se as rubricas gravadas
     * no resultado da folha (resultados_folha_salarial.rubricas), que são a fotografia do processamento fechado.
     *
     * @return array<string, mixed>
     */
    private static function rhRubricas(): array
    {
        $data = "to_date(pp.mes_ano, 'MM/YYYY')";
        $valor = self::numero("ru->>'valor'");
        $venc = "COALESCE(ru->>'tipo', 'VENCIMENTO') <> 'DESCONTO'";

        return [
            'nome' => 'Recursos Humanos — Rubricas (infotipos)', 'icone' => 'lista', 'alias' => 'r',
            'tabela' => 'resultados_folha_salarial r JOIN periodos_processamento_salarial pp ON pp.id = r.periodo_processamento_salarial_id '
                ."CROSS JOIN LATERAL jsonb_array_elements(CASE WHEN jsonb_typeof(r.rubricas) = 'array' THEN r.rubricas ELSE '[]'::jsonb END) ru",
            'data' => $data, 'data_mes' => true,
            'vistas' => ['colaboradores', 'calcular', 'processamento', 'relatorios', 'infotipos'],
            'padrao' => ['linhas' => ['infotipo'], 'colunas' => ['periodo'], 'medidas' => [['medida' => 'valor', 'agregacao' => 'soma']]],
            'condicao' => "pp.estado IN ('FECHADO', 'VALIDADO')",
            'juncoes' => self::juncoesColaborador() + [
                'inf' => "LEFT JOIN infotipos_salariais inf ON inf.id = (CASE WHEN (ru->>'infotipo_id') ~ '^[0-9]{1,18}$' THEN (ru->>'infotipo_id')::bigint END)",
            ],
            'dimensoes' => self::datas($data) + [
                'periodo' => ['Período', "to_char({$data}, 'YYYY-MM')", []],
                'infotipo' => ['Infotipo', "COALESCE(NULLIF(TRIM(ru->>'nome'), ''), inf.nome, 'Rubrica ' || COALESCE(ru->>'infotipo_id', '?'))", ['inf']],
                'natureza_rubrica' => ['Natureza da rubrica', "CASE WHEN {$venc} THEN 'Vencimento' ELSE 'Desconto' END", []],
                'entra_inss' => ['Entra no INSS', "CASE WHEN inf.sujeito_inss THEN 'Sim' ELSE 'Não' END", ['inf']],
                // irt é texto no legado: true | false | conditional_30k (isenção até 30 000 Kz)
                'entra_irt' => ['Entra no IRT', "CASE WHEN lower(COALESCE(inf.irt, '')) IN ('true', 't', '1', 'sim') THEN 'Sim'
                    WHEN lower(COALESCE(inf.irt, '')) LIKE 'conditional%' THEN 'Condicional (isento até 30 000 Kz)' ELSE 'Não' END", ['inf']],
                'falta' => ['Falta', "CASE WHEN ru->>'falta' = 'true' THEN 'Sim' ELSE 'Não' END", []],
                'origem_calculo' => ['Origem do cálculo', "COALESCE(NULLIF(ru->>'origem', ''), CASE WHEN ru->>'automatica' = 'true' THEN 'Automática' ELSE 'Manual' END)", []],
            ] + self::dimensoesColaborador(),
            'medidas' => [
                'valor' => ['Valor', $valor, 'kz', []],
                'vencimentos' => ['Vencimentos', "CASE WHEN {$venc} THEN {$valor} ELSE 0 END", 'kz', []],
                'descontos' => ['Descontos', "CASE WHEN {$venc} THEN 0 ELSE {$valor} END", 'kz', []],
                'valor_sinal' => ['Valor com sinal', "CASE WHEN {$venc} THEN {$valor} ELSE -({$valor}) END", 'kz', []],
                'horas' => ['Horas', self::numero("ru->>'horas'"), 'num', []],
            ],
        ];
    }

    /** Junções do colaborador comuns aos cubos de RH (função, tipo de órgão, UN e CC do resultado). */
    private static function juncoesColaborador(): array
    {
        return [
            'c' => 'LEFT JOIN colaboradores c ON c.id = r.colaborador_id',
            'fn' => 'LEFT JOIN cargos_funcoes fn ON fn.id = c.cargo_funcao_id',
            'org' => 'LEFT JOIN tipos_organizacao_rh org ON org.id = COALESCE(r.tipo_organizacao_id, c.tipo_organizacao_id)',
            'un' => 'LEFT JOIN unidades_negocio un ON un.id = r.unidade_negocio_id',
            'cc' => 'LEFT JOIN centros_custo cc ON cc.id = r.centro_custo_id',
        ];
    }

    /** Dimensões do colaborador comuns aos cubos de RH (dimColaborador, Versão Actual). O NIF é visível a quem vê o cubo (ADR-071). */
    private static function dimensoesColaborador(): array
    {
        return [
            'colaborador' => ['Colaborador', "COALESCE(c.nome_completo, '-')", ['c']],
            'nif_colaborador' => ['NIF do colaborador', self::nif('c'), ['c']],
            'funcao' => ['Função', "COALESCE(fn.nome, '(sem função)')", ['c', 'fn']],
            'tipo_orgao' => ['Tipo de órgão', "COALESCE(org.nome, '(sem órgão)')", ['c', 'org']],
            'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
            'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
            'estado_colaborador' => ['Estado do colaborador', "COALESCE(c.estado, '-')", ['c']],
            'vinculo' => ['Vínculo', "CASE WHEN COALESCE(r.avencado, c.avencado, false) THEN 'Avençado' ELSE 'Efectivo' END", ['c']],
            'aposentado' => ['Aposentado', "CASE WHEN COALESCE(r.reformado, c.reformado, false) THEN 'Sim' ELSE 'Não' END", ['c']],
            'genero' => ['Género', "CASE upper(left(COALESCE(c.sexo, ''), 1)) WHEN 'M' THEN 'Masculino' WHEN 'F' THEN 'Feminino' ELSE COALESCE(NULLIF(c.sexo, ''), '-') END", ['c']],
        ];
    }

    /** Soma de um campo numérico das rubricas gravadas no resultado da folha que cumprem a condição (sobre `e`). */
    private static function somaRubricas(string $campo, string $condicao): string
    {
        return '(SELECT COALESCE(SUM('.self::numero("e->>'{$campo}'").'), 0) FROM jsonb_array_elements(CASE WHEN jsonb_typeof(r.rubricas) = \'array\' THEN r.rubricas ELSE \'[]\'::jsonb END) e WHERE '.$condicao.')';
    }

    /** Texto JSON → número (0 se não for um número): nunca falha a consulta por um valor mal gravado. */
    private static function numero(string $expr): string
    {
        return "(CASE WHEN ({$expr}) ~ '^-?[0-9]+(\\.[0-9]+)?([eE][-+]?[0-9]+)?$' THEN ({$expr})::numeric ELSE 0 END)";
    }

    /** NIF de um terceiro/colaborador (dimensão visível a todos com acesso ao cubo, decisão do ADR-071). */
    private static function nif(string $alias): string
    {
        return "COALESCE(NULLIF(TRIM({$alias}.nif), ''), '-')";
    }

    /** Taxa em percentagem sem casas inúteis (14.00 → «14%», 7.50 → «7.5%»). */
    private static function percentagem(string $expr): string
    {
        return "rtrim(rtrim((COALESCE({$expr}, 0)::numeric(9, 2))::text, '0'), '.') || '%'";
    }

    /** Província do terceiro: o endereço estruturado está em enderecos_terceiros pelo NIF + empresa (Fase A). */
    private static function juncaoProvincia(string $terceiro, string $alias): string
    {
        return "LEFT JOIN LATERAL (SELECT e.provincia FROM enderecos_terceiros e WHERE e.empresa_id = {$terceiro}.empresa_id AND e.nif = {$terceiro}.nif ORDER BY e.id LIMIT 1) {$alias} ON TRUE";
    }

    private static function projetos(): array
    {
        return [
            'nome' => 'Projectos e Obras — Orçamento, custos e proveitos', 'icone' => 'obra', 'alias' => 'x', 'tabela' => '{json} x', 'data' => 'x.data::date', 'json' => true,
            'vistas' => ['projectos_carteira', 'projectos_extracto', 'projectos_gantt'],
            'padrao' => ['linhas' => ['projeto'], 'colunas' => ['tipo'], 'medidas' => [['medida' => 'valor', 'agregacao' => 'soma']]],
            'juncoes' => [],
            'dimensoes' => self::datas('x.data::date') + [
                'projeto' => ['Projecto', "COALESCE(x.projeto, '-')", []],
                'estado_projeto' => ['Estado do projecto', "COALESCE(x.estado_projeto, '-')", []],
                'tarefa' => ['Tarefa', "COALESCE(x.tarefa, '-')", []],
                'tipo' => ['Tipo', 'x.tipo', []],
                'rubrica' => ['Rubrica', "COALESCE(x.rubrica, '-')", []],
                'origem' => ['Origem', "COALESCE(x.origem, '-')", []],
                'colaborador' => ['Colaborador', "COALESCE(x.colaborador, '-')", []],
            ],
            'medidas' => [
                'valor' => ['Valor (Kz)', 'COALESCE(x.valor, 0)', 'kz', []],
                'horas' => ['Horas', 'COALESCE(x.horas, 0)', 'num', []],
            ],
        ];
    }

    private static function ativos(): array
    {
        return [
            'nome' => 'Activos Fixos — Amortizações', 'icone' => 'activos', 'alias' => 'q', 'tabela' => 'amortizacoes_ativos q LEFT JOIN ativos_imobilizados a ON a.id = q.ativo_imobilizado_id',
            'data' => 'q.data',
            'vistas' => ['activos', 'activos_amortizacoes'],
            'padrao' => ['linhas' => ['categoria'], 'colunas' => ['ano'], 'medidas' => [['medida' => 'amortizacao', 'agregacao' => 'soma']]],
            'juncoes' => [
                'cat' => 'LEFT JOIN categorias_ativos cat ON cat.id = a.categoria_ativo_id',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = a.centro_custo_id',
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = a.unidade_negocio_id',
                'f' => 'LEFT JOIN terceiros f ON f.id = a.fornecedor_id',
            ],
            'dimensoes' => self::datas('q.data') + [
                'ativo' => ['Activo', "COALESCE(NULLIF(TRIM(COALESCE(a.codigo, '') || ' ' || COALESCE(a.descricao, '')), ''), 'Activo ' || q.ativo_imobilizado_id)", []],
                'categoria' => ['Categoria', "COALESCE(cat.nome, 'Sem categoria')", ['cat']],
                'tipo_ativo' => ['Tipo de activo', "COALESCE(NULLIF(TRIM(a.tipo_ativo), ''), '-')", []],
                'estado_ativo' => ['Estado do activo', "COALESCE(a.estado, '-')", []],
                'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
                'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
                'fornecedor' => ['Fornecedor', "COALESCE(f.nome, '-')", ['f']],
                'ano_aquisicao' => ['Ano de aquisição', "COALESCE(to_char(a.data_aquisicao, 'YYYY'), '-')", []],
                'contabilizada' => ['Contabilizada', "CASE WHEN q.contabilizado THEN 'Sim' ELSE 'Não' END", []],
            ],
            'medidas' => [
                'amortizacao' => ['Amortização (Kz)', 'COALESCE(q.valor, 0)', 'kz', []],
                // correcção: o legado repetia o valor de aquisição em cada quota (a soma multiplicava-o); conta uma vez por activo
                'valor_aquisicao' => ['Valor de aquisição (Kz)', 'CASE WHEN ROW_NUMBER() OVER (PARTITION BY q.ativo_imobilizado_id ORDER BY q.data, q.id) = 1 THEN COALESCE(a.valor_aquisicao, 0) ELSE 0 END', 'kz', []],
                'amortizacoes' => ['Amortizações', '1', 'num', []],
            ],
        ];
    }
}
