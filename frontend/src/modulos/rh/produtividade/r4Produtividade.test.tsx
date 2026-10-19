import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Form, Row } from 'antd';
import { MemoryRouter } from 'react-router-dom';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

/**
 * Ronda 4 (r4_rh_proj) — Produtividade com um só cálculo: as métricas e a explicação do cálculo vêm das rotas do servidor
 * (GET /rh/produtividade/metricas e /explicar); o ecrã mostra o que a API devolve, sem refazer a conta. Dados fictícios: os
 * textos da API são propositadamente diferentes dos do legado para provar que não há cálculo/descrição local.
 */
const obter = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: vi.fn().mockResolvedValue({ dados: {}, mensagem: 'ok' }),
  obterPagina: () => Promise.resolve({ itens: [], paginacao: { ultima_pagina: 1 } }),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [] }) }));

import Produtividade, { CamposCalculoItem } from '../Produtividade';

vi.setConfig({ testTimeout: 120_000 });

const descricao = (nome: string, formula: string, extra: Record<string, unknown> = {}) => ({
  nome, rotulo_quantidade: 'Qtd', rotulo_preco: `Preço de ${nome}`, rotulo_minimo: `Mínimo de ${nome}`, rotulo_maximo: `Máximo de ${nome}`, formula,
  exemplo: `Exemplo de ${nome}`, ajuda: `Ajuda de ${nome}`, base_premio: false, quantidade_exemplo: 10, ...extra,
});
const METRICAS = {
  metricas: {
    QUANTIDADE: descricao('Quantidade (servidor)', 'Fórmula Q do servidor'),
    HORAS: descricao('Horas (servidor)', 'Fórmula H do servidor'),
    OBJECTIVO: descricao('Objectivo (servidor)', 'Fórmula O do servidor', { base_premio: true, quantidade_exemplo: 95 }),
    PONTOS: descricao('Pontos (servidor)', 'Fórmula P do servidor'),
    TAREFAS: descricao('Tarefas (servidor)', 'Fórmula T do servidor'),
  },
  bases: { UNITARIO: { nome: 'Unitário (servidor)', formula: 'q × p' }, PREMIO: { nome: 'Prémio (servidor)', formula: 'p × %' } },
};
/** Explicação devolvida pela API — a conta não bate com 95 × 50 000 de propósito: o ecrã tem de a mostrar tal como vem. */
const EXPLICACAO = {
  metrica: 'OBJECTIVO', base_calculo: 'PREMIO', formula: 'FÓRMULA DO MOTOR', rotulo_preco: 'Rótulo do motor', conta: 'CONTA DO MOTOR = 123,45 Kz',
  cortes: ['corte do motor'], quantidade: 95, quantidade_considerada: 95, preco: 50000, valor: '123.45',
};

let respostas: Record<string, unknown> = {};
beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  respostas = { '/rh/produtividade/metricas': METRICAS, '/rh/produtividade/explicar': EXPLICACAO };
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(url in respostas ? respostas[url] : []));
});

function envolver(ui: ReactNode, rota = '/') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}><MemoryRouter initialEntries={[rota]}>{ui}</MemoryRouter></QueryClientProvider>);
}

describe('Produtividade — métricas e explicação do servidor', () => {
  it('o formulário do item mostra a fórmula, o rótulo e a conta que a API devolve, com os valores do formulário no pedido', async () => {
    envolver(
      <Form initialValues={{ metrica: 'OBJECTIVO', base_calculo: 'PREMIO', preco_unitario: 50000, minimo: 80, maximo: 120 }}>
        <Row>
          <CamposCalculoItem metricas={METRICAS} valores={{ metrica: 'OBJECTIVO', base_calculo: 'PREMIO', preco_unitario: 50000, unidade: '%', minimo: 80, maximo: 120 }} />
        </Row>
      </Form>,
    );
    expect(await screen.findByText('FÓRMULA DO MOTOR')).toBeInTheDocument();
    expect(screen.getByText('Rótulo do motor')).toBeInTheDocument();
    expect(screen.getByText(/CONTA DO MOTOR = 123,45 Kz \(corte do motor\)/)).toBeInTheDocument();
    expect(screen.getByText('Ajuda de Objectivo (servidor)')).toBeInTheDocument();
    expect(screen.getByText('Mínimo de Objectivo (servidor) (total)')).toBeInTheDocument();
    // a base só aparece quando o servidor diz que a métrica admite prémio, com os nomes do servidor
    expect(screen.getByText('Base de cálculo')).toBeInTheDocument();
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/rh/produtividade/explicar',
      { metrica: 'OBJECTIVO', base_calculo: 'PREMIO', preco: '50000', unidade: '%', minimo: '80', maximo: '120' }));
  });

  it('sem preço não mostra conta (só a fórmula do servidor) e esconde a base nas métricas sem prémio', async () => {
    respostas['/rh/produtividade/explicar'] = { ...EXPLICACAO, metrica: 'QUANTIDADE', base_calculo: 'UNITARIO', formula: 'FÓRMULA Q DO MOTOR', rotulo_preco: 'Preço Q do motor' };
    envolver(<Form><Row><CamposCalculoItem metricas={METRICAS} valores={{ metrica: 'QUANTIDADE', base_calculo: 'UNITARIO', preco_unitario: null }} /></Row></Form>);
    expect(await screen.findByText('FÓRMULA Q DO MOTOR')).toBeInTheDocument();
    expect(screen.queryByText(/CONTA DO MOTOR/)).not.toBeInTheDocument();
    expect(screen.queryByText('Base de cálculo')).not.toBeInTheDocument();
    expect(obter).toHaveBeenCalledWith('/rh/produtividade/explicar', { metrica: 'QUANTIDADE', base_calculo: 'UNITARIO', preco: '0' });
  });

  it('a lista de itens mostra o nome da métrica e da base vindos da API', async () => {
    respostas['/rh/produtividade/periodos'] = [];
    respostas['/rh/produtividade/itens'] = [
      { id: 1, codigo: 'OBJ', descricao: 'Objectivo comercial', metrica: 'OBJECTIVO', unidade: '%', preco_unitario: '50000.0000', infotipo_salarial_id: 9, minimo: null, maximo: null, ativo: true, base_calculo: 'PREMIO' },
      { id: 2, codigo: 'PEC', descricao: 'Peças', metrica: 'QUANTIDADE', unidade: 'peças', preco_unitario: '2000.0000', infotipo_salarial_id: 9, minimo: null, maximo: null, ativo: true, base_calculo: null },
    ];
    envolver(<Produtividade />);
    fireEvent.click(await screen.findByText('Itens de produtividade'));
    expect(await screen.findByText('Objectivo (servidor)')).toBeInTheDocument();
    expect(screen.getByText('Quantidade (servidor)')).toBeInTheDocument();
    expect(obter).toHaveBeenCalledWith('/rh/produtividade/metricas');
  });
});
