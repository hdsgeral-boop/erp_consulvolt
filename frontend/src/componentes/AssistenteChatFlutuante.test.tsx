import { beforeEach, describe, expect, it, vi } from 'vitest';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { ErroApi } from '@/api/tipos';
import { AssistenteChatFlutuante } from './AssistenteChatFlutuante';

vi.setConfig({ testTimeout: 120_000 });

const obter = vi.fn();
const enviar = vi.fn();
const post = vi.fn();
vi.mock('@/api/cliente', async (original) => ({
  ...(await original<typeof import('@/api/cliente')>()),
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  http: { post: (...a: unknown[]) => post(...a) },
}));
vi.mock('@/utilitarios/erros', () => ({ notificarErro: vi.fn() }));
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({
    empresa: { id: 7, nome: 'Demo' },
    menu: [{ id: 'contab', nome: 'Contabilidade', ecras: [{ id: 'contab_plano', nome: 'Plano de Contas', vistas: [], pai: null }] }],
  }),
}));

let estado: unknown;
let mensagens: unknown[];

/** O servidor devolve as mensagens novas e, ao reabrir a conversa, a lista completa. */
function responder(msgs: unknown[]) {
  mensagens = msgs;
  return { data: { dados: { conversa: { id: 4, titulo: 'Olá', atualizado_em: 'x' }, mensagens: msgs } } };
}

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={['/m/contab/contab_lancamentos']}>
        <Routes>
          <Route path="*" element={<><AssistenteChatFlutuante contexto="CONTAB › lancamentos" /><LocalActual /></>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

import { useLocation } from 'react-router';
function LocalActual() {
  return <output data-testid="local">{useLocation().pathname}</output>;
}

const PROPOSTA = { id: 31, tipo: 'terceiro', resumo: 'Criar cliente «Alfa»', metodo: 'POST', url: '/terceiros', dados: { papel: 'CLIENTE', nome: 'Alfa' }, estado: 'PENDENTE' };

beforeEach(() => {
  estado = { ativo: true, quota_tokens_mes: 1000, tokens_usados_mes: 100 };
  mensagens = [];
  obter.mockReset().mockImplementation((url: string) => {
    if (url === '/ia/estado') return Promise.resolve(estado);
    if (url === '/ia/conversas') return Promise.resolve([{ id: 4, titulo: 'Conversa antiga', atualizado_em: '2026-11-01T10:00:00Z' }]);
    if (url === '/ia/conversas/4/mensagens') return Promise.resolve(mensagens);
    if (url === '/ia/regras') return Promise.resolve({ regras: 'Responda em português.' });
    return Promise.resolve([]);
  });
  enviar.mockReset().mockResolvedValue({ dados: {}, mensagem: 'Feito.' });
  post.mockReset().mockImplementation(() => Promise.resolve(responder([
    { id: 1, papel: 'utilizador', texto: 'Olá' },
    { id: 2, papel: 'assistente', texto: 'Resposta **forte**', extra: { accoes: [PROPOSTA], fontes: [{ url: 'https://exemplo.pt', titulo: 'Fonte A' }] } },
  ])));
});

async function abrir() {
  montar();
  fireEvent.click(await screen.findByRole('button', { name: 'Abrir o Assistente (chat)' }));
  await screen.findByRole('dialog');
}

async function enviarOla() {
  fireEvent.change(await screen.findByLabelText('Mensagem para o assistente'), { target: { value: 'Olá' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enviar mensagem' }));
  await screen.findByText('Criar cliente «Alfa»');
}

describe('Assistente (chat flutuante)', () => {
  it('o botão chama-se «Assistente» (não colide com o «Assistente IA» dos lançamentos) e abre o painel', async () => {
    await abrir();
    expect(screen.getByRole('dialog')).toHaveTextContent('Assistente');
    expect(screen.getByText(/Está em CONTAB › lancamentos/)).toBeInTheDocument();
  });

  it('envia a mensagem com o ecrã actual, mostra a resposta em Markdown e as fontes', async () => {
    await abrir();
    await enviarOla();
    const corpo = post.mock.calls[0][1] as FormData;
    expect(post.mock.calls[0][0]).toBe('/ia/mensagens');
    expect(corpo.get('texto')).toBe('Olá');
    expect(corpo.get('modulo')).toBe('contab');
    expect(corpo.get('ecra')).toBe('contab_lancamentos');
    expect(corpo.get('conversa_id')).toBeNull();
    expect(screen.getByText('forte').tagName).toBe('STRONG');
    expect(screen.getByRole('link', { name: 'Fonte A' })).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('uma acção proposta NÃO se executa sem confirmação explícita', async () => {
    await abrir();
    await enviarOla();
    expect(enviar).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }));
    // o Popconfirm pede uma segunda confirmação; ainda nada foi enviado
    await screen.findByText('Confirmar esta operação?');
    expect(enviar).not.toHaveBeenCalled();
    fireEvent.click(screen.getByText('Sim, executar'));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/terceiros', { papel: 'CLIENTE', nome: 'Alfa' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/ia/accoes/31/decisao', { estado: 'EXECUTADA', resultado: { mensagem: 'Feito.' } }));
  });

  it('«Rejeitar» só regista a decisão e não executa a operação', async () => {
    await abrir();
    await enviarOla();
    fireEvent.click(screen.getByRole('button', { name: 'Rejeitar' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/ia/accoes/31/decisao', { estado: 'REJEITADA' }));
    expect(enviar).not.toHaveBeenCalledWith('post', '/terceiros', expect.anything());
  });

  it('se a operação falha regista FALHOU e não marca como executada', async () => {
    enviar.mockImplementation((_m: string, url: string) => (url === '/terceiros' ? Promise.reject(new ErroApi('NIF inválido', 422)) : Promise.resolve({ dados: {}, mensagem: 'ok' })));
    await abrir();
    await enviarOla();
    fireEvent.click(screen.getByRole('button', { name: 'Confirmar' }));
    fireEvent.click(await screen.findByText('Sim, executar'));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/ia/accoes/31/decisao', { estado: 'FALHOU', resultado: { mensagem: 'NIF inválido' } }));
    expect(enviar).not.toHaveBeenCalledWith('post', '/ia/accoes/31/decisao', expect.objectContaining({ estado: 'EXECUTADA' }));
  });

  it('uma acção com endereço absoluto/perigoso não oferece «Confirmar»', async () => {
    post.mockImplementation(() => Promise.resolve(responder([
      { id: 2, papel: 'assistente', texto: 'ok', extra: { accoes: [{ ...PROPOSTA, url: 'https://evil.com/x' }] } },
    ])));
    await abrir();
    fireEvent.change(await screen.findByLabelText('Mensagem para o assistente'), { target: { value: 'x' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar mensagem' }));
    await screen.findByText('Endereço da operação não permitido.');
    expect(screen.queryByRole('button', { name: 'Confirmar' })).toBeNull();
  });

  it('abrir um ecrã exige um clique e vai para /m/{modulo}/{ecra}', async () => {
    post.mockImplementation(() => Promise.resolve(responder([
      { id: 2, papel: 'assistente', texto: 'Aqui', extra: { accoes: [{ id: 9, tipo: 'navegar', resumo: 'Abrir «Plano de Contas»', metodo: 'GET', url: '', dados: { ecra: 'Plano de Contas' }, estado: 'EXECUTADA' }] } },
    ])));
    await abrir();
    fireEvent.change(await screen.findByLabelText('Mensagem para o assistente'), { target: { value: 'x' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enviar mensagem' }));
    const botao = await screen.findByRole('button', { name: 'Abrir Plano de Contas' });
    expect(screen.getByTestId('local')).toHaveTextContent('/m/contab/contab_lancamentos');
    fireEvent.click(botao);
    await waitFor(() => expect(screen.getByTestId('local')).toHaveTextContent('/m/contab/contab_plano'));
  });

  it('quota esgotada: avisa e bloqueia o envio', async () => {
    estado = { ativo: true, quota_tokens_mes: 1000, tokens_usados_mes: 1000 };
    await abrir();
    expect(await screen.findByText('Limite mensal de tokens atingido')).toBeInTheDocument();
    expect(screen.getByLabelText('Mensagem para o assistente')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Enviar mensagem' })).toBeDisabled();
  });

  it('IA inactiva: explica e bloqueia o envio', async () => {
    estado = { ativo: false, quota_tokens_mes: null, tokens_usados_mes: 0 };
    await abrir();
    expect(await screen.findByText('O assistente ainda não está disponível')).toBeInTheDocument();
    expect(screen.getByLabelText('Mensagem para o assistente')).toBeDisabled();
  });

  it('sem backend de IA (404 no estado) o botão não aparece', async () => {
    obter.mockImplementation(() => Promise.reject(new ErroApi('Não encontrado', 404)));
    montar();
    await waitFor(() => expect(obter).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Abrir o Assistente (chat)' })).toBeNull());
  });

  it('histórico: lista, abre e elimina conversas; definições: mostra consumo e grava as regras', async () => {
    mensagens = [{ id: 1, papel: 'assistente', texto: 'Mensagem antiga' }];
    await abrir();
    fireEvent.click(screen.getByRole('tab', { name: /Histórico/ }));
    expect(await screen.findByText('Conversa antiga')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Abrir a conversa Conversa antiga' }));
    expect(await screen.findByText('Mensagem antiga')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('tab', { name: /Definições/ }));
    expect(await screen.findByText(/100 usados este mês de 1\s?000/)).toBeInTheDocument();
    const regras = await screen.findByLabelText('Regras da IA');
    await waitFor(() => expect(regras).toHaveValue('Responda em português.'));
    fireEvent.change(regras, { target: { value: 'Seja breve.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Guardar regras' }));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('put', '/ia/regras', { regras: 'Seja breve.' }));
  });
});
