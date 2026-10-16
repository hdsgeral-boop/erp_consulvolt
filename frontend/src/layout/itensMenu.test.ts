import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { procurarEcras } from './PesquisaEcras';
import { CHAVE_MENU_GERAL, construirMenuLateral, estadoMenu } from './itensMenu';
import { temIconeFa } from '@/componentes/fluxos/IconeFa';
import { ecrasDaEstrutura, MENU_GERAL_LEGADO, nomeModulo, rotuloEcra, SISTEMA_LEGADO, type NoLegado } from './menuLegado';

type No = { key?: string; label?: unknown; title?: string; children?: Item[] };
type Item = No | null;

const ecra = (id: string, nome = id, pai: string | null = null) => ({ id, nome, vistas: [], pai });
const MENU: ModuloMenu[] = [
  { id: 'geral', nome: 'Geral', ecras: [ecra('dashboard'), ecra('relatorios_gestao'), ecra('fluxo_processos'), ecra('accounting_bi', 'BI contabilístico')] },
  { id: 'rh', nome: 'Recursos Humanos e Salários', ecras: [ecra('colaboradores'), ecra('relatorios', 'Relatórios e recibos'), ecra('rh_rel_irt', 'Mapa de IRT', 'relatorios')] },
  { id: 'contab', nome: 'Contabilidade', ecras: [ecra('lancamentos'), ecra('contab_rotinas'), ecra('imposto_selo', 'Imposto de Selo', 'contab_rotinas')] },
  { id: 'acrescimos', nome: 'Acréscimos e Diferimentos', ecras: [ecra('ad_registos')] },
  { id: 'teso', nome: 'Tesouraria', ecras: [ecra('teso_gestao_pagamentos', 'Pagamentos e recebimentos'), ecra('teso_folha_caixa', 'Folha de Caixa')] },
  { id: 'pos', nome: 'POS, Lavandaria e Hotelaria', ecras: [ecra('pos'), ecra('pos_hotelaria', 'Hotelaria')] },
  { id: 'vendas', nome: 'Vendas e Facturação', ecras: [ecra('vendas_faturacao', 'Facturação'), ecra('vendas_clientes', 'Clientes')] },
  { id: 'stock', nome: 'Armazém e Inventário', ecras: [ecra('armazem_stock', 'Níveis de stock')] },
  { id: 'config', nome: 'Configurações', ecras: [ecra('config_geral'), ecra('config_migracao')] },
  { id: 'novo_modulo', nome: 'Módulo futuro', ecras: [ecra('futuro_x', 'Ecrã futuro')] },
];

const filho = (itens: Item[] | undefined, rotulo: string): No => {
  const i = (itens ?? []).find((x) => x?.label === rotulo || x?.title === rotulo);
  if (!i) throw new Error(`sem «${rotulo}» em ${(itens ?? []).map((x) => String(x?.label)).join(', ')}`);
  return i;
};
const rotulos = (itens: Item[] | undefined) => (itens ?? []).map((x) => x?.label);

describe('menu lateral com a estrutura do legado (0.1)', () => {
  const { itens, ancestrais } = construirMenuLateral(MENU);
  const raiz = itens as Item[];

  it('itens gerais soltos no topo, depois «Menu Geral» e «Sistema»', () => {
    expect(rotulos(raiz)).toEqual(['Início', 'Dashboard Global', 'Relatórios de Gestão', 'Fluxo de Processos', 'BI contabilístico', 'Menu Geral', 'Sistema']);
    expect(raiz[0]?.key).toBe('/');
    expect(raiz.find((x) => x?.key === CHAVE_MENU_GERAL)).toBeDefined();
  });

  it('módulos dentro do «Menu Geral» pela ordem e com os nomes curtos do legado; módulo desconhecido no fim', () => {
    const geral = filho(raiz, 'Menu Geral');
    expect(rotulos(geral.children)).toEqual(['Logística', 'Vendas', 'Salários e RH', 'Tesouraria', 'Contabilidade', 'Módulo futuro']);
    expect(rotulos(filho(filho(geral.children, 'Logística').children, 'Gestão de Armazém').children)).toEqual(['Níveis de Stock']);
  });

  it('POS dentro de Vendas e ecrãs exclusivos do novo no fim do respectivo grupo', () => {
    const vendas = filho(filho(raiz, 'Menu Geral').children, 'Vendas');
    expect(rotulos(vendas.children)).toEqual(['Ponto de Venda (POS)', 'Faturação', 'Clientes']);
    expect(rotulos(filho(vendas.children, 'Ponto de Venda (POS)').children)).toEqual(['Frente de Caixa', 'Hotelaria']);
  });

  it('«Relatórios e Recibos» é uma só entrada; Imposto de Selo não é entrada própria; Acréscimos dentro de Contabilidade', () => {
    const geral = filho(raiz, 'Menu Geral');
    const rh = filho(geral.children, 'Salários e RH');
    expect(rotulos(rh.children)).toEqual(['Colaboradores', 'Relatórios e Recibos']);
    expect(filho(rh.children, 'Relatórios e Recibos').key).toBe('/m/rh/relatorios');
    const contab = filho(geral.children, 'Contabilidade');
    expect(rotulos(contab.children)).toEqual(['Lançamentos (Diários)', 'Acréscimos e Diferimentos', 'Rotinas (inclui Imposto de Selo)']);
  });

  it('Pagamentos e Recebimentos: duas entradas para o mesmo ecrã já filtrado', () => {
    const operacoes = filho(filho(filho(raiz, 'Menu Geral').children, 'Tesouraria').children, 'Operações');
    expect(operacoes.children?.map((x) => x?.key)).toEqual([
      '/m/teso/teso_gestao_pagamentos?tipo=PAGAMENTO',
      '/m/teso/teso_gestao_pagamentos?tipo=RECEBIMENTO',
      '/m/teso/teso_folha_caixa',
    ]);
  });

  it('«Sistema» com o sub-grupo Configurações e a Migração fora dele', () => {
    const sistema = filho(raiz, 'Sistema');
    expect(rotulos(sistema.children)).toEqual(['Configurações', 'Migração de Dados']);
  });

  it('sem permissões, os grupos vazios desaparecem', () => {
    const so = construirMenuLateral([{ id: 'vendas', nome: 'Vendas e Facturação', ecras: [ecra('vendas_faturacao')] }]).itens as Item[];
    expect(rotulos(so)).toEqual(['Início', 'Menu Geral']);
  });

  it('estado do menu: query de Pagamentos/Recebimentos, ecrãs escondidos seleccionam o pai e abrem os grupos', () => {
    const lateral = { itens, ancestrais };
    expect(estadoMenu('/m/teso/teso_gestao_pagamentos', '?tipo=RECEBIMENTO', lateral, MENU)).toEqual({
      seleccionado: '/m/teso/teso_gestao_pagamentos?tipo=RECEBIMENTO',
      abertos: ['grupo:menu-geral', 'grupo:teso', 'grupo:teso-operacoes'],
    });
    expect(estadoMenu('/m/rh/rh_rel_irt', '', lateral, MENU).seleccionado).toBe('/m/rh/relatorios');
    expect(estadoMenu('/m/contab/imposto_selo', '', lateral, MENU).seleccionado).toBe('/m/contab/contab_rotinas');
    expect(estadoMenu('/', '', lateral, MENU)).toEqual({ seleccionado: '/', abertos: [] });
  });
});

describe('rótulos do legado na pesquisa (Ctrl+K)', () => {
  it('mostra o rótulo do legado e encontra também pelo nome do catálogo', () => {
    const r = procurarEcras(MENU, 'factur', () => false);
    expect(r.map((x) => x.nome)).toContain('Faturação');
    expect(r.find((x) => x.nome === 'Faturação')?.nomeModulo).toBe('Vendas');
    // o separador Imposto de Selo e as Rotinas («inclui Imposto de Selo»)
    expect(procurarEcras(MENU, 'imposto selo', () => false).map((x) => x.rota).sort()).toEqual(['/m/contab/contab_rotinas', '/m/contab/imposto_selo']);
  });

  it('cada ecrã da estrutura tem rótulo; nomes de módulo curtos', () => {
    expect(ecrasDaEstrutura().every((x) => x.rotulo.trim().length > 0)).toBe(true);
    expect(rotuloEcra('stock', 'armazem_armazens', 'Configuração de armazéns')).toBe('Config. Armazém');
    expect(rotuloEcra('pos', 'pos_hotelaria', 'Hotelaria')).toBe('Hotelaria');
    expect(nomeModulo('stock', 'Armazém e Inventário')).toBe('Logística');
  });
});

describe('ícones do menu do legado', () => {
  it('todos os ícones usados no menu têm desenho (IconeFa)', () => {
    const nomes = new Set<string>(['home', 'exchange-alt', 'mobile-alt', 'sign-out-alt']);
    const juntar = (n: NoLegado) => {
      if (n.icone) nomes.add(n.icone);
      if (n.tipo === 'grupo') n.filhos.forEach(juntar);
    };
    ecrasDaEstrutura().forEach(juntar);
    juntar(MENU_GERAL_LEGADO);
    juntar(SISTEMA_LEGADO);
    expect([...nomes].filter((x) => !temIconeFa(x))).toEqual([]);
  });
});

describe('Versão Actual: Aprovisionamento, Método de Trabalho e Folha de Caixa', () => {
  const MENU_VA: ModuloMenu[] = [
    { id: 'compras', nome: 'Compras', ecras: [ecra('compras_pedidos', 'Pedidos internos'), ecra('compras_encomendas')] },
    { id: 'metodo', nome: 'Método de Trabalho 5W1H', ecras: [ecra('mt_assuntos'), ecra('mt_novo', 'Ecrã novo do método')] },
    { id: 'teso', nome: 'Tesouraria', ecras: [ecra('teso_folha_caixa', 'Folha de Caixa')] },
  ];
  const lateral = construirMenuLateral(MENU_VA);
  const geral = filho(lateral.itens as Item[], 'Menu Geral');

  it('«Aprovisionamento» à cabeça das Compras, abre o ecrã dos pedidos; a pesquisa mantém «Pedidos Internos»', () => {
    const compras = filho(geral.children, 'Compras');
    expect(rotulos(compras.children)).toEqual(['Aprovisionamento', 'Pedidos Internos', 'Encomendas']);
    expect(filho(compras.children, 'Aprovisionamento').key).toBe('/m/compras/compras_pedidos?vista=aprovisionamento');
    expect(estadoMenu('/m/compras/compras_pedidos', '?vista=aprovisionamento', lateral, MENU_VA).seleccionado).toBe('/m/compras/compras_pedidos?vista=aprovisionamento');
    expect(estadoMenu('/m/compras/compras_pedidos', '', lateral, MENU_VA).seleccionado).toBe('/m/compras/compras_pedidos');
    expect(rotuloEcra('compras', 'compras_pedidos', 'x')).toBe('Pedidos Internos');
  });

  it('ecrãs novos do módulo «metodo» entram no grupo Método de Trabalho, com o nome curto', () => {
    const metodo = filho(geral.children, 'Método de Trabalho');
    expect(rotulos(metodo.children)).toEqual(['Registo de Assuntos (5W1H)', 'Ecrã novo do método']);
    expect(nomeModulo('metodo', 'Método de Trabalho 5W1H')).toBe('Método de Trabalho');
  });

  it('«Folha de Caixa» com o ícone cash-register do legado', () => {
    expect(ecrasDaEstrutura().find((x) => x.ecra === 'teso_folha_caixa')?.icone).toBe('cash-register');
  });
});
