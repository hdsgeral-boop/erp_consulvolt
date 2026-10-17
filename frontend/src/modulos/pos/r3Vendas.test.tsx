import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, renderHook, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import type { ReactNode } from 'react';

const obter = vi.fn();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obter(...a), obterPagina: vi.fn(), enviar: vi.fn(), descarregar: vi.fn() }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null }) }));

import { chaveCatalogoPOS, useCatalogoPOS } from './comum/dados';
import { Definicoes } from './lavandaria/Tabelas';
import { alteracaoMesa } from './frente/PainelMesas';
import { linhasDetalheLiquidacao } from './Prestacao';
import type { Liquidacao } from './comum/tipos';

vi.setConfig({ testTimeout: 600_000 });
const ESPERA = { timeout: 30_000 };

function envolver() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={cliente}><App>{children}</App></QueryClientProvider>;
}

beforeEach(() => {
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/pos/lavandaria/definicoes') {
      return Promise.resolve({ taxa_armazenagem_ativa: false, dias_armazenagem_gratis: 30, percentagem_armazenagem_dia: '1', percentagem_adiantamento: '50',
        percentagem_urgencia: '50', fator_prazo_urgencia: 0.5, valor_taxa_recolha: '0', valor_taxa_entrega: '0', faturar_no_adiantamento: true,
        conta_compensacao: null, conta_extras: null, taxa_extras: '14', dias_reclamacao: 7, estados_entrada: [] });
    }
    return Promise.resolve([{ id: 1, codigo: 'P1', nome: 'Produto', preco_unitario: '100', taxa_imposto: '14', movimenta_stock: false }]);
  });
});

describe('catálogo do POS por serviço', () => {
  it('com terminal pede o catálogo filtrado no servidor; sem terminal, o catálogo comum', async () => {
    const { result } = renderHook(() => useCatalogoPOS(7), { wrapper: envolver() });
    await waitFor(() => expect(result.current.isSuccess).toBe(true), ESPERA);
    expect(obter).toHaveBeenCalledWith('/pos/terminais/7/catalogo');

    const comum = renderHook(() => useCatalogoPOS(), { wrapper: envolver() });
    await waitFor(() => expect(comum.result.current.isSuccess).toBe(true), ESPERA);
    expect(obter).toHaveBeenCalledWith('/logistica/produtos/catalogo');
  });

  it('a chave fica sob o catálogo de Logística (invalidada com ele, não a cada venda)', () => {
    expect(chaveCatalogoPOS(7)).toEqual(['logistica', 'catalogo', 'pos', 7]);
    expect(chaveCatalogoPOS(null)).toEqual(['logistica', 'catalogo']);
    expect(chaveCatalogoPOS(7)[0]).not.toBe('pos');
  });
});

describe('Lavandaria › Definições', () => {
  it('explica o que acontece com a facturação no adiantamento ligada e desligada, com exemplo', async () => {
    render(<Definicoes />, { wrapper: envolver() });
    const ajuda = await screen.findByTestId('ajuda-faturar-adiantamento', {}, ESPERA);
    expect(screen.getByText('Emitir a factura logo no primeiro pagamento (adiantamento)')).toBeTruthy();
    const texto = ajuda.textContent ?? '';
    expect(texto).toContain('Ligada:');
    expect(texto).toContain('Desligada:');
    expect(texto).toContain('Exemplo:');
    expect(texto).toContain('Factura-Recibo');
    expect(texto).toContain('Recibo de adiantamento');
    expect(texto).not.toContain('Facturar ao receber pagamentos antes da facturação');
  });
});

describe('auditoria: mesas e liquidações', () => {
  it('só grava a mesa quando o nome ou a ordem mudam (nome aparado e obrigatório)', () => {
    const m = { nome: 'Mesa 1', ordem: 1 };
    expect(alteracaoMesa(m, undefined)).toBeNull();
    expect(alteracaoMesa(m, { nome: ' Mesa 1 ' })).toBeNull();
    expect(alteracaoMesa(m, { nome: '   ' })).toBeNull();
    expect(alteracaoMesa(m, { nome: 'Terraço 1 ' })).toEqual({ nome: 'Terraço 1', ordem: 1 });
    expect(alteracaoMesa(m, { ordem: 5 })).toEqual({ nome: 'Mesa 1', ordem: 5 });
  });

  it('detalhe da liquidação mostra os campos preenchidos, com comissão e anulação', () => {
    const l = {
      id: 9, estado: 'ANULADO', sessao_pos_id: 3, numero_z: 'Z-12', chave_item: 'TPA:1', natureza_registo: 'TPA', meio_pagamento_codigo: 'pm_tpa',
      data: '2026-10-10', montante_bruto: '1000.00', comissao: '15.00', montante_liquido: '985.00', alvo: 'TESOURARIA', conta_destino: '4311',
      conta_transitoria: '489', sessao_caixa_id: null, movimento_caixa_id: null, documento_tesouraria_id: 4, documento_comissao_id: null,
      numero_documento: 'REC A2026/4', referencia: null, criado_por: 'ana', criado_em: '2026-10-10T10:00:00Z', cancelado_em: '2026-10-11T10:00:00Z',
      cancelado_por: 'rui', comissao_deduzida: true,
    } as Liquidacao & { comissao_deduzida: boolean };
    const linhas = Object.fromEntries(linhasDetalheLiquidacao(l));
    expect(linhas['Estado']).toBe('Anulada');
    expect(linhas['Sessão (Z)']).toBe('Z-12');
    expect(linhas['Conta transitória → destino']).toBe('489 → 4311');
    expect(linhas['Comissão']).toContain('(deduzida no recebimento)');
    expect(linhas['Documento']).toBe('REC A2026/4');
    expect(linhas['Anulado']).toContain('rui');
    expect(linhas).not.toHaveProperty('Referência / comprovativo');
  });
});
