import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from 'antd';
import type { ReactNode } from 'react';
import dayjs from 'dayjs';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const obterPagina = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  obterPagina: (...a: unknown[]) => obterPagina(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  descarregar: vi.fn(),
}));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: { nome_utilizador: 'ana' } }) }));

import { ModalActividade } from './comum/componentes';
import { camposOportunidade, dadosConta, dadosContacto, dadosOportunidade } from './comum/formularios';
import type { Actividade, Funil } from './comum/tipos';

// antd no jsdom é lento (e a máquina de testes partilhada): margem larga por teste e nas esperas
vi.setConfig({ testTimeout: 600_000 });
const ESPERA = { timeout: 30_000 };

function ecra(no: ReactNode) {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <App>{no}</App>
    </QueryClientProvider>,
  );
}

const celula = (rotulo: string, n: number) => screen.getByLabelText(`${rotulo}, linha ${n}`) as HTMLInputElement;

beforeEach(() => {
  reporPreferenciasVistaLocal();
  reporPreferenciasColunasLocal();
  window.localStorage.clear();
  const prefs: { nome: string; valor: unknown }[] = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url.startsWith('/sistema/preferencias')) return Promise.resolve([...prefs]);
    if (url === '/crm/configuracao') return Promise.resolve({ motivos_perda: [], origens: ['Feira'], dias_sem_atividade: 7, prazo_pagamento_dias: 30, tipos_atividade: { CHAMADA: 'Chamada', TAREFA: 'Tarefa' }, marcadores: [] });
    return Promise.resolve([]);
  });
  obterPagina.mockReset().mockResolvedValue({ itens: [], total: 0, pagina: 1, por_pagina: 25 });
  enviar.mockReset().mockImplementation((_m: string, url: string, dados: { valor?: unknown }) => {
    if (url.startsWith('/sistema/preferencias/interface/')) {
      const nome = url.split('/').pop()!;
      prefs.splice(0, prefs.length, ...prefs.filter((p) => p.nome !== nome), { nome, valor: dados.valor });
    }
    return Promise.resolve({ dados: {}, mensagem: 'ok' });
  });
});

describe('CRM — formulários de criação com Grade | Linhas', () => {
  it('«Nova actividade» em Linhas grava uma actividade por linha, ligada ao mesmo registo; «Editar» só tem a grade', async () => {
    const { rerender } = ecra(<ModalActividade actividade="nova" contexto={{ conta_crm_id: 9 }} aoFechar={() => {}} />);
    fireEvent.click(await screen.findByRole('button', { name: /Linhas/ }, ESPERA));
    await screen.findByTestId('painel-linhas', undefined, ESPERA);
    // omissões vindas da grade: tipo TAREFA, data de hoje e o utilizador como responsável
    await waitFor(() => expect(celula('Responsável', 1).value).toBe('ana'), ESPERA);
    fireEvent.change(celula('Título', 1), { target: { value: 'Ligar ao director' } });
    fireEvent.change(celula('Título', 2), { target: { value: 'Enviar proposta' } });
    fireEvent.click(screen.getByRole('button', { name: /Registar linhas/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Registar' }, ESPERA));
    const posts = () => enviar.mock.calls.filter((c) => c[1] === '/crm/atividades');
    await waitFor(() => expect(posts()).toHaveLength(2), ESPERA);
    const hoje = dayjs().format('YYYY-MM-DD');
    expect(posts()[0][2]).toEqual({ conta_crm_id: 9, tipo: 'TAREFA', titulo: 'Ligar ao director', descricao: null, responsavel: 'ana', concluida: false, data_prevista: hoje });
    expect(posts()[1][2]).toMatchObject({ conta_crm_id: 9, titulo: 'Enviar proposta' });

    const existente = { id: 4, tipo: 'CHAMADA', titulo: 'X', descricao: null, data_prevista: null, responsavel: null, concluida: false, conta_crm_id: 9 } as unknown as Actividade;
    rerender(
      <QueryClientProvider client={new QueryClient()}>
        <App><ModalActividade actividade={existente} aoFechar={() => {}} /></App>
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Editar actividade', undefined, ESPERA)).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Vista do formulário' })).toBeNull();
  });

  it('contas, contactos e oportunidades: as mesmas regras na grade e nas linhas', () => {
    expect(dadosConta({ nome: ' Alfa ', email: '', origem: 'Feira' })).toMatchObject({ nome: 'Alfa', email: null, origem: 'Feira', nif: null });
    expect(dadosContacto({ nome: 'Rui', principal_sn: 'S' }).principal).toBe(true);
    expect(dadosContacto({ nome: 'Rui', principal: true }).principal).toBe(true);
    expect(dadosOportunidade({ titulo: 'Obra', conta_crm_id: 1, funil_vendas_crm_id: 2, valor: null, data_fecho_prevista: '2026-12-01' }))
      .toMatchObject({ valor: 0, data_fecho_prevista: '2026-12-01', etapa_codigo: null, itens: [] });
    expect(dadosOportunidade({ titulo: 'Obra', conta_crm_id: 1, funil_vendas_crm_id: 2, valor: 5, itens: [{ quantidade: 2, preco: 10, taxa: 14 }] }).valor).toBe('20.00');
    const funis: Funil[] = [
      { id: 1, nome: 'Vendas', ordem: 1, ativo: true, etapas: [{ id: 'novo', nome: 'Novo', tipo: 'ABERTA' }, { id: 'ganho', nome: 'Ganho', tipo: 'GANHA' }] },
      { id: 2, nome: 'Projectos', ordem: 2, ativo: true, etapas: [{ id: 'novo', nome: 'Novo', tipo: 'ABERTA' }, { id: 'proposta', nome: 'Proposta', tipo: 'ABERTA' }] },
    ];
    const campos = camposOportunidade({ contas: [], funis, origens: [] });
    const etapa = campos.find((c) => c.nome === 'etapa_codigo')!;
    expect(etapa.opcoes?.map((o) => o.label)).toEqual(['Novo (Vendas, Projectos)', 'Proposta (Projectos)']);
    expect(etapa.validar?.('proposta', { funil_vendas_crm_id: 1 })).toMatch(/não pertence ao funil «Vendas»/);
    expect(etapa.validar?.('proposta', { funil_vendas_crm_id: 2 })).toBeNull();
    expect(campos.find((c) => c.nome === 'funil_vendas_crm_id')?.omissao).toBe(1);
  });
});
