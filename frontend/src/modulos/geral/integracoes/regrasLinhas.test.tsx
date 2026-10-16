import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

/** Assistente IA › «Nova regra interna» com as vistas Grade | Linhas (o modelo JSON é validado em cada linha). */
const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  http: { post: vi.fn() },
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: { id: 1, nome: 'E' }, menu: [] }) }));

vi.setConfig({ testTimeout: 420_000 });

import { AssistenteIA, dadosRegra } from './AssistenteIA';

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/contabilidade/assistente/estado') return Promise.resolve({ ativo: true, configurado: false, fornecedor: 'x', modelo: 'y', regras_internas: 0, tem_regras_empresa: false, uso_mes: { pedidos: 0, pedidos_ia: 0, custo_estimado_usd: 0 }, dados_enviados: [] });
    return Promise.resolve([]);
  });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('Regras internas do assistente IA', () => {
  it('dadosRegra apara os textos (grade e linhas)', () => {
    expect(dadosRegra({ nome: ' Combustível ', palavras_chave: ' gasóleo ', modelo: ' [] ' })).toEqual({ nome: 'Combustível', palavras_chave: 'gasóleo', modelo: '[]' });
  });

  it('«Nova regra» em Linhas valida o JSON e grava uma regra por linha', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter><AssistenteIA aberto aoFechar={() => undefined} /></MemoryRouter>
      </QueryClientProvider>,
    );
    fireEvent.click(await screen.findByRole('tab', { name: 'Regras internas' }, { timeout: 60_000 }));
    fireEvent.click(await screen.findByRole('button', { name: /Nova regra/ }));
    const dialogo = (await screen.findByText('Nova regra interna')).closest('.ant-modal') as HTMLElement;
    fireEvent.click(within(dialogo).getByRole('button', { name: /Linhas/ }));
    await screen.findByTestId('painel-linhas');
    const celula = (r: string, n: number) => screen.getByLabelText(`${r}, linha ${n}`);
    fireEvent.change(celula('Nome', 1), { target: { value: 'Combustível' } });
    fireEvent.change(celula('Palavras-chave', 1), { target: { value: 'gasóleo, gasolina' } });
    fireEvent.change(celula('Nome', 2), { target: { value: 'Rendas' } });
    fireEvent.change(celula('Palavras-chave', 2), { target: { value: 'renda' } });
    fireEvent.change(celula('Modelo das linhas (JSON)', 2), { target: { value: '{ mal' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    expect(await screen.findByText(/JSON inválido/)).toBeInTheDocument();
    fireEvent.change(celula('Modelo das linhas (JSON)', 2), { target: { value: '[{"account_code": "6261", "type_dc": "D"}]' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }));
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/contabilidade/assistente/regras');
    await waitFor(() => expect(posts()).toHaveLength(2));
    expect(posts()[0][2]).toMatchObject({ nome: 'Combustível', palavras_chave: 'gasóleo, gasolina' });
    expect(JSON.parse(posts()[0][2].modelo)).toHaveLength(2);
    expect(posts()[1][2]).toEqual({ nome: 'Rendas', palavras_chave: 'renda', modelo: '[{"account_code": "6261", "type_dc": "D"}]' });
  });
});
