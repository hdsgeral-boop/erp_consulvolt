<?php

namespace App\Services\Gestao\Paineis\Cubo;

/**
 * Lista branca da Análise Dinâmica (CONJUNTOS, ui_cubo.js:100-338) e do BI contabilístico (ui_bi.js:75-100): conjuntos de dados,
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
 *   - projetos: orçamento, custos, proveitos e compromissos pela regra única do razão analítico (ADR-052), aditamentos aprovados
 *     e horas;
 *   - ativos: quotas de amortização (contabilizadas ou não).
 */
final class CatalogoCubo
{
    public const AGREGACOES = ['soma' => 'SUM', 'media' => 'AVG', 'minimo' => 'MIN', 'maximo' => 'MAX', 'contagem' => 'COUNT'];

    private const MESES = "ARRAY['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez']";

    private const MESES_LONGOS = "ARRAY['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro']";

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
            'projetos' => self::projetos(),
            'ativos' => self::ativos(),
        ];
    }

    /**
     * BI contabilístico (ui_bi.js): os lançamentos com os campos do legado (conta, nome da conta, entidade, diário, mês por extenso,
     * trimestre, ano, dia, natureza, documento, descrição, valor e saldo). Correcção: o legado procurava o nome do diário pelo
     * código com o id da linha e mostrava sempre "Geral"; agora é o nome do diário.
     *
     * @return array<string, mixed>
     */
    public static function bi(): array
    {
        $c = self::contabilidade();
        $data = 'l.data_documento';

        return array_merge($c, [
            'nome' => 'BI contabilístico — Lançamentos',
            'vistas' => ['accounting_bi'],
            'padrao' => ['linhas' => ['conta'], 'colunas' => ['mes_nome'], 'medidas' => [['medida' => 'saldo', 'agregacao' => 'soma']]],
            'dimensoes' => [
                'conta' => ['Cód. Conta', "COALESCE(l.codigo_conta, 'S/C')", []],
                'descricao_conta' => ['Nome da Conta', "COALESCE(pc.descricao, 'N/A')", ['pc']],
                'terceiro' => ['Entidade', "COALESCE(t.nome, 'N/A')", ['t']],
                'diario' => ['Diário', "COALESCE(d.nome, d.descricao, d.codigo, 'Geral')", ['d']],
                'mes_nome' => ['Mês', "COALESCE(to_char({$data}, 'MM') || ' - ' || (".self::MESES_LONGOS.")[EXTRACT(MONTH FROM {$data})::int], '(sem data)')", []],
                'trimestre' => ['Trimestre', "COALESCE('Trim ' || EXTRACT(QUARTER FROM {$data})::int, '(sem data)')", []],
                'ano' => ['Ano', "COALESCE(to_char({$data}, 'YYYY'), '(sem data)')", []],
                'dia' => ['Dia', "COALESCE(to_char({$data}, 'DD'), '(sem data)')", []],
                'natureza' => ['Natureza', "CASE WHEN l.tipo_dc = 'D' THEN 'DÉBITO' ELSE 'CRÉDITO' END", []],
                'documento' => ['Ref. Doc', "COALESCE(l.numero_documento, '-')", []],
                'descricao' => ['Descrição', "COALESCE(l.descricao, '')", []],
            ] + array_intersect_key($c['dimensoes'], array_flip(['empresa', 'tipo_linha'])),
            'medidas' => [
                'valor' => ['Valor', 'COALESCE(l.valor, 0)', 'kz', []],
                'saldo' => ['Saldo', "CASE WHEN l.tipo_dc = 'D' THEN COALESCE(l.valor, 0) ELSE -COALESCE(l.valor, 0) END", 'kz', []],
            ],
        ]);
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
                'conta' => ['Conta', 'l.codigo_conta', []],
                'descricao_conta' => ['Descrição da conta', "COALESCE(pc.descricao, '')", ['pc']],
                'terceiro' => ['Terceiro', "COALESCE(t.nome, '(sem terceiro)')", ['t']],
                'documento' => ['Documento', "COALESCE(l.numero_documento, '-')", []],
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
                'pr' => 'LEFT JOIN projetos pr ON pr.id = v.projeto_id',
                'p' => 'LEFT JOIN produtos p ON p.id = i.produto_id',
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = v.unidade_negocio_id',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = v.centro_custo_id',
            ],
            'dimensoes' => self::datas('v.data_emissao') + [
                'tipo_documento' => ['Tipo de documento', "COALESCE(v.tipo_documento, '-')", []],
                'documento' => ['Documento', "COALESCE(v.numero_documento, '-')", []],
                'estado' => ['Estado', "COALESCE(v.estado, '-')", []],
                'cliente' => ['Cliente', "COALESCE(t.nome, 'Consumidor final')", ['t']],
                'moeda' => ['Moeda', "COALESCE(v.codigo_moeda, 'AOA')", []],
                'projeto' => ['Projecto', "COALESCE(pr.codigo || ' - ' || pr.nome, '-')", ['pr']],
                'artigo' => ['Artigo', "CASE WHEN i.id IS NULL THEN '(sem linhas)' ELSE COALESCE(p.nome, i.descricao, 'Artigo ' || i.produto_id) END", ['p']],
                'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
                'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
            ],
            'medidas' => [
                'quantidade' => ['Quantidade', "{$sinal} * COALESCE(i.quantidade, 0)", 'num', []],
                'valor_sem_iva' => ['Valor s/ IVA (Kz)', "{$sinal} * ({$liq})", 'kz', []],
                'iva' => ['IVA (Kz)', "{$sinal} * (({$total}) - ({$liq}))", 'kz', []],
                'total' => ['Total (Kz)', "{$sinal} * ({$total})", 'kz', []],
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
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = c.unidade_negocio_id',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = c.centro_custo_id',
            ],
            'dimensoes' => self::datas('c.data') + [
                'fornecedor' => ['Fornecedor', "COALESCE(t.nome, '-')", ['t']],
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
            ],
        ];
    }

    private static function tesouraria(): array
    {
        $fonte = "(SELECT d.empresa_id, d.data_documento AS data, 'Tesouraria' AS origem, CASE WHEN d.tipo = 'PAGAMENTO' THEN 'Pagamento' ELSE 'Recebimento' END AS tipo,
                d.conta_financeira, it.codigo_conta AS contrapartida, it.terceiro_id, COALESCE(it.numero_documento, d.referencia, '-') AS documento, COALESCE(d.estado, '-') AS estado,
                COALESCE(it.codigo_moeda, d.codigo_moeda, 'AOA') AS moeda, CASE WHEN it.tipo_dc = 'D' THEN -COALESCE(it.valor, 0) ELSE COALESCE(it.valor, 0) END AS valor
            FROM documentos_tesouraria d JOIN itens_documento_tesouraria it ON it.documento_tesouraria_id = d.id
            WHERE d.empresa_id IN ({empresas}) AND d.data_documento BETWEEN ? AND ? AND d.anulado_em IS NULL AND COALESCE(d.estado, '') <> 'ANULADO'
            UNION ALL
            SELECT m.empresa_id, m.data_documento, 'Folha de Caixa', CASE WHEN m.tipo = 'REC' THEN 'Entrada de caixa' ELSE 'Saída de caixa' END,
                s.codigo_conta, CASE WHEN m.tipo = 'REC' THEN m.conta_credito ELSE m.conta_debito END, m.terceiro_id, COALESCE(m.numero_documento, '-'),
                CASE WHEN m.contabilizado THEN 'Integrado' ELSE 'Pendente' END, COALESCE(m.codigo_moeda, 'AOA'),
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
                'terceiro' => ['Terceiro', "COALESCE(t.nome, '(sem terceiro)')", ['t']],
                'documento' => ['Documento', 'x.documento', []],
                'estado' => ['Estado', 'x.estado', []],
                'moeda' => ['Moeda', 'x.moeda', []],
            ],
            'medidas' => ['valor' => ['Valor (Kz)', 'x.valor', 'kz', []]],
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
                'a' => 'LEFT JOIN armazens a ON a.id = m.armazem_id',
                't' => 'LEFT JOIN terceiros t ON t.id = m.terceiro_id',
            ],
            'dimensoes' => self::datas('m.data') + [
                'tipo' => ['Tipo', "CASE WHEN m.sentido = 'S' THEN 'Saída' ELSE 'Entrada' END", []],
                'tipo_movimento' => ['Tipo de movimento', "COALESCE(m.tipo, '-')", []],
                'artigo' => ['Artigo', "COALESCE(p.nome, 'Artigo ' || m.produto_id)", ['p']],
                'armazem' => ['Armazém', "COALESCE(a.nome, '-')", ['a']],
                'terceiro' => ['Terceiro', "COALESCE(t.nome, '-')", ['t']],
                'referencia' => ['Referência', "COALESCE(m.referencia, '-')", []],
            ],
            'medidas' => [
                'quantidade' => ['Quantidade', "{$sinal} * ABS(COALESCE(m.quantidade, 0))", 'num', []],
                'custo_unitario' => ['Custo unitário (Kz)', 'COALESCE(m.preco_unitario, 0)', 'kz', []],
                'valor' => ['Valor (Kz)', "{$sinal} * ABS(COALESCE(m.valor, m.quantidade * m.preco_unitario, 0))", 'kz', []],
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
            'juncoes' => [
                'c' => 'LEFT JOIN colaboradores c ON c.id = r.colaborador_id',
                'un' => 'LEFT JOIN unidades_negocio un ON un.id = r.unidade_negocio_id',
                'cc' => 'LEFT JOIN centros_custo cc ON cc.id = r.centro_custo_id',
            ],
            'dimensoes' => self::datas($data) + [
                'periodo' => ['Período', "to_char({$data}, 'YYYY-MM')", []],
                'colaborador' => ['Colaborador', "COALESCE(c.nome_completo, '-')", ['c']],
                'unidade_negocio' => ['Unidade de negócio', "COALESCE(un.codigo, '-')", ['un']],
                'centro_custo' => ['Centro de custo', "COALESCE(cc.codigo, '-')", ['cc']],
            ],
            'medidas' => [
                'iliquido' => ['Ilíquido', 'COALESCE(r.bruto, 0)', 'kz', []],
                'inss_trabalhador' => ['INSS trabalhador', 'COALESCE(r.inss_trabalhador, 0)', 'kz', []],
                'inss_patronal' => ['INSS empresa', 'COALESCE(r.inss_patronal, 0)', 'kz', []],
                'irt' => ['IRT', 'COALESCE(r.irt, 0)', 'kz', []],
                'descontos' => ['Descontos', 'COALESCE(r.descontos, 0)', 'kz', []],
                'liquido' => ['Líquido', 'COALESCE(r.liquido, 0)', 'kz', []],
                'custo_empresa' => ['Custo empresa', 'COALESCE(r.bruto, 0) + COALESCE(r.inss_patronal, 0)', 'kz', []],
            ],
        ];
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
            'juncoes' => ['cat' => 'LEFT JOIN categorias_ativos cat ON cat.id = a.categoria_ativo_id'],
            'dimensoes' => self::datas('q.data') + [
                'ativo' => ['Activo', "COALESCE(NULLIF(TRIM(COALESCE(a.codigo, '') || ' ' || COALESCE(a.descricao, '')), ''), 'Activo ' || q.ativo_imobilizado_id)", []],
                'categoria' => ['Categoria', "COALESCE(cat.nome, 'Sem categoria')", ['cat']],
                'estado_ativo' => ['Estado do activo', "COALESCE(a.estado, '-')", []],
                'contabilizada' => ['Contabilizada', "CASE WHEN q.contabilizado THEN 'Sim' ELSE 'Não' END", []],
            ],
            'medidas' => ['amortizacao' => ['Amortização (Kz)', 'COALESCE(q.valor, 0)', 'kz', []]],
        ];
    }
}
