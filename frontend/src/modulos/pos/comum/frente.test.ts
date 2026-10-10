import { afterEach, describe, expect, it } from 'vitest';
import { textoSessoesAntigas } from '../frente/AvisoSessoesAntigas';
import { corDesvio, destinoLiquidacao, linhasMeios, rotuloDecisao, textoDesvio } from '../contas/formatos';
import { documentosDoLancamento } from '../contas/PreVisualizacaoIntegracao';
import {
  contarCategorias,
  corStock,
  eServico,
  filtrarPorStock,
  gravarPreferenciasFrente,
  iconeCategoria,
  iconeMeio,
  lerPreferenciasFrente,
  stockDe,
  urlImagemSegura,
} from './frente';
import type { ProdutoPOS } from './tipos';

const produto = (id: number, extra: Partial<ProdutoPOS> = {}): ProdutoPOS => ({
  id,
  codigo: `P${id}`,
  nome: `Produto ${id}`,
  preco_unitario: '100.00',
  taxa_imposto: '14',
  movimenta_stock: true,
  categoria_produto_id: null,
  ...extra,
});

describe('frente de caixa — produtos (3.1.5, 3.1.6)', () => {
  const lista = [produto(1, { categoria_produto_id: 10 }), produto(2, { categoria_produto_id: 10 }), produto(3, { movimenta_stock: false, categoria_produto_id: 20 })];
  const stock = { '1': '40.000', '2': '0.000' };

  it('stock por produto: serviços sem stock, produtos com o saldo do armazém do terminal', () => {
    expect(stockDe(lista[0], stock)).toBe(40);
    expect(stockDe(lista[1], stock)).toBe(0);
    expect(stockDe(lista[2], stock)).toBeNull();
    expect(eServico(lista[2])).toBe(true);
  });

  it('Com Stock inclui os serviços; Sem Stock só produtos de inventário esgotados', () => {
    expect(filtrarPorStock(lista, 'all', stock).map((p) => p.id)).toEqual([1, 2, 3]);
    expect(filtrarPorStock(lista, 'in', stock).map((p) => p.id)).toEqual([1, 3]);
    expect(filtrarPorStock(lista, 'out', stock).map((p) => p.id)).toEqual([2]);
    // sem dados de stock não esconde nada
    expect(filtrarPorStock(lista, 'out', undefined)).toHaveLength(3);
  });

  it('chips de categoria com a contagem, pela ordem das categorias', () => {
    expect(contarCategorias(lista, [{ id: 20, nome: 'Serviços' }, { id: 10, nome: 'Materiais' }, { id: 30, nome: 'Vazia' }])).toEqual([
      { id: 20, nome: 'Serviços', total: 1 },
      { id: 10, nome: 'Materiais', total: 2 },
      { id: 30, nome: 'Vazia', total: 0 },
    ]);
  });

  it('ícones e cores do legado', () => {
    expect(iconeCategoria('Bebidas frescas')).toBe('glass-martini-alt');
    expect(iconeCategoria('Materiais eléctricos')).toBe('box');
    expect(corStock(0)).toBe('error');
    expect(corStock(3)).toBe('warning');
    expect(corStock(40)).toBe('success');
    expect(iconeMeio('NUMERARIO')).toBe('money-bill-wave');
    expect(iconeMeio('TPA')).toBe('credit-card');
    expect(iconeMeio('TRANSFERENCIA')).toBe('university');
  });

  it('só aceita o URL relativo da API para a imagem do produto', () => {
    expect(urlImagemSegura('/vendas/produtos/12/imagem?v=1700000000')).toBe('/vendas/produtos/12/imagem?v=1700000000');
    expect(urlImagemSegura('https://exemplo.invalid/x.png')).toBeNull();
    expect(urlImagemSegura('javascript:alert(1)')).toBeNull();
    expect(urlImagemSegura('data:image/svg+xml;base64,AAAA')).toBeNull();
    expect(urlImagemSegura(undefined)).toBeNull();
  });
});

describe('preferências do posto (dispositivo, grelha/lista, Ocultar Imagens, filtro de stock)', () => {
  afterEach(() => window.localStorage.clear());

  it('grava por utilizador e normaliza valores inválidos', () => {
    gravarPreferenciasFrente(7, { dispositivo: 'tablet', disposicao: 'list', ocultarImagens: true, filtroStock: 'in' });
    expect(lerPreferenciasFrente(7)).toEqual({ dispositivo: 'tablet', disposicao: 'list', ocultarImagens: true, filtroStock: 'in' });
    expect(lerPreferenciasFrente(8).dispositivo).toBe('pc');
    window.localStorage.setItem('erp.pos.frente.9', JSON.stringify({ dispositivo: 'tv', disposicao: 'x', filtroStock: 'y' }));
    expect(lerPreferenciasFrente(9)).toEqual({ dispositivo: 'pc', disposicao: 'grid', ocultarImagens: false, filtroStock: 'all' });
  });
});

describe('aviso das sessões abertas de dias anteriores (3.1.9)', () => {
  it('lista TODAS as sessões (qualquer terminal e operador)', () => {
    const texto = textoSessoesAntigas([
      { id: 2, terminal_pos_id: 4, codigo_sessao: 'WCZL-2026-0001', codigo_terminal: 'WCZL', nome_terminal: 'Lavandaria', nome_operador: 'op1', aberto_em: '2026-09-15T11:47:13+01:00' },
      { id: 4, terminal_pos_id: 5, codigo_sessao: 'T05-2026-0002', codigo_terminal: 'T05', nome_terminal: 'Loja 5', nome_operador: 'op2', aberto_em: '2026-09-15T20:01:14+01:00' },
    ]);
    expect(texto).toMatch(/^Existem 2 sessões de caixa abertas desde dias anteriores: WCZL-2026-0001 \(Lavandaria, 15\/09\/2026\), T05-2026-0002 \(Loja 5, 15\/09\/2026\)\./);
    expect(textoSessoesAntigas([])).toBeNull();
  });
});

describe('integração, desvios e prestação de contas — textos do legado', () => {
  it('formatos', () => {
    expect(rotuloDecisao({ decisao: 'FALTA_CUSTO', automatica: false })).toBe('Falta assumida como custo');
    expect(rotuloDecisao({ decisao: 'SOBRA_PROVEITO', automatica: true })).toMatch(/automática/);
    expect(textoDesvio('10')).toMatch(/^\+10/);
    expect(textoDesvio('-5')).not.toMatch(/^\+/);
    expect(corDesvio('-1')).toBe('#b91c1c');
    expect(linhasMeios([{ meio_id: 'pm', tipo: 'NUMERARIO', nome: 'Numerário', valor: '1140.00', quantidade: 1 }])[0]).toMatch(/^Numerário: 1/);
    expect(destinoLiquidacao({ alvo: 'FOLHA_CAIXA', conta_destino: '4511', documento_tesouraria_id: null })).toBe('Folha de Caixa 4511');
    expect(documentosDoLancamento('Z-T01-2026-0001 — vendas POS T01 (3 doc.)')).toBe(3);
    expect(documentosDoLancamento(null)).toBeNull();
  });
});
