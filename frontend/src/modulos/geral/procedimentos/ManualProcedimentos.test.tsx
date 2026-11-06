import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { Cobertura, DefinicoesManual, ListaProcessos, Processo, Recolha } from './api';

vi.setConfig({ testTimeout: 120_000 });

const etapa = (nome: string, quem = 'RH') => ({ nome, quem, descricao: 'faz', controlos: 'controla', resultado: 'feito' });
const PROCESSOS: Processo[] = [
  { chave: 'rh', origem: 'FABRICA', editado: false, tem_painel: true, fluxo_id: 'rh', modulo: null, ordem: 0, titulo: 'Processamento salarial', objetivo: 'Pagar a tempo.', intervenientes: ['RH'], etapas: [etapa('Dados base'), etapa('Fechar')], cruza: [{ com: 'ferias', onde: 'Dados base', o_que: 'Subsídio' }] },
  { chave: 'ferias', origem: 'EDITADO', editado: true, tem_painel: true, fluxo_id: 'ferias', modulo: null, ordem: 10, titulo: 'Plano de férias', objetivo: '', intervenientes: [], etapas: [etapa('Marcar', '')], cruza: [] },
  { chave: 'recrutamento', origem: 'PROPRIO', editado: true, tem_painel: false, fluxo_id: null, modulo: null, ordem: 9000, titulo: 'Recrutamento', objetivo: 'Contratar.', intervenientes: [], etapas: [etapa('Anunciar')], cruza: [] },
];
const LISTA: ListaProcessos = { processos: PROCESSOS, ocultos: [{ chave: 'stock', titulo: 'Gestão de stock' }] };
const COBERTURA: Cobertura = {
  modulos: [], painel_sem_narrativa: ['caixa'], narrativa_sem_painel: [], total: { modulos: 13, paineis: 14, narrativas: 3 },
  modulos_sem_processo: [{ id: 'stock', nome: 'Stock e Armazém', esperados: ['stock'], descritos: [], em_falta: ['stock'], coberto: false, sem_processo: false }],
};
const DEFINICOES = {
  titulo: 'Manual de Procedimentos', versao: '1.0', data_documento: '2026-11-06', aprovado_em: null, elaborado_por: '', aprovado_por: '', processos_excluidos: [],
  incluir_diagrama: true, incluir_legenda: true, incluir_matriz: true, incluir_controlos: true, incluir_cruzamentos: true, incluir_cobertura: false, conferir_funcoes: true,
  apresentacao: 'Texto de abertura.', objetivo: '', principios: '', notas: '', guardado: true,
  textos_ajuda: { apresentacao: { rot: 'Apresentação', ajuda: 'A mensagem de abertura.', exemplo: 'ex' }, objetivo: { rot: 'Objectivo e âmbito', ajuda: '', exemplo: '' }, principios: { rot: 'Princípios e regras gerais', ajuda: '', exemplo: '' }, notas: { rot: 'Disposições finais', ajuda: '', exemplo: '' } },
} as unknown as DefinicoesManual;
const RECOLHA: Recolha = {
  opcoes: DEFINICOES, processos: PROCESSOS,
  intervenientes: [{ nome: 'RH', processos: ['rh'], partes: ['RH'], na_funcoes: false }],
  controlos: [{ processo: 'rh', etapa: 'Dados base', quem: 'RH', controlos: 'controla', n: 1 }],
  cruzamentos: { pares: [{ de: 'rh', para: 'ferias', onde: 'Dados base', o_que: 'Subsídio' }], externos: [], ligacoes: [{ a: 'rh', b: 'ferias', pontos: [] }], grau: { rh: 1, ferias: 1, recrutamento: 0 }, isolados: ['recrutamento'], central: 'rh' },
  cobertura: null, totais: { processos: 3, etapas: 4, controlos: 1, intervenientes: 1, editados: 1, proprios: 1, ligacoes: 1 },
  avisos: ['1 processo(s) sem nenhum cruzamento declarado: Recrutamento'],
};

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));
vi.mock('@/componentes/impressao', () => ({ BotoesExportar: ({ obterPedido }: { obterPedido: () => Promise<{ titulo: string; conteudo: string }> }) => <button type="button" onClick={async () => { (globalThis as { __pedido?: unknown }).__pedido = await obterPedido(); }}>Imprimir</button> }));

import ManualProcedimentos from './ManualProcedimentos';

const ecra = (sep = 'manual', ui: ReactNode = <ManualProcedimentos />) =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[`/?sep=${sep}`]}>{ui}</MemoryRouter></QueryClientProvider>);

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  (globalThis as { __pedido?: unknown }).__pedido = undefined;
  permissoes = new Set(['proc_manual_view', 'proc_editar', 'proc_imprimir']);
  obterMock.mockImplementation((url: string) => {
    if (url === '/procedimentos') return Promise.resolve(LISTA);
    if (url === '/procedimentos/cobertura') return Promise.resolve(COBERTURA);
    if (url === '/manuais/procedimentos') return Promise.resolve(DEFINICOES);
    return Promise.resolve(null);
  });
  enviarMock.mockImplementation((_metodo: string, url: string) => {
    if (url === '/manuais/procedimentos/recolha') return Promise.resolve({ dados: RECOLHA, mensagem: 'ok' });
    if (url === '/procedimentos') return Promise.resolve({ dados: { ...PROCESSOS[2], chave: 'novo_proc', titulo: 'Novo proc' }, mensagem: 'ok' });
    return Promise.resolve({ dados: PROCESSOS[0], mensagem: 'ok' });
  });
});

describe('Manual de Procedimentos — ecrã', () => {
  it('separador Manual: formulário, pré-visualização com capítulos numerados, KPI e avisos', async () => {
    ecra('manual');
    expect(await screen.findByText('O que entra')).toBeInTheDocument();
    expect(screen.getByText('Texto que se escreve à mão')).toBeInTheDocument();
    expect(await screen.findByText('O manual sai, mas com partes por preencher:')).toBeInTheDocument();
    expect(screen.getAllByText(/1 processo\(s\) sem nenhum cruzamento declarado/).length).toBeGreaterThanOrEqual(1);
    const previa = await screen.findByRole('region', { name: 'Pré-visualização do manual' });
    expect(within(previa).getByRole('heading', { name: '1. Apresentação' })).toBeInTheDocument();
    expect(within(previa).getByRole('heading', { name: /Os procedimentos/ })).toBeInTheDocument();
    expect(within(previa).getByText('Texto de abertura.')).toBeInTheDocument();
    expect(within(previa).getByText('(sem função em RH)')).toBeInTheDocument();
    expect(within(previa).getByRole('heading', { name: '6.1. Processamento salarial' })).toBeInTheDocument();
    // a pré-visualização recebeu as exclusões (nenhuma) e não as inclusões
    const corpo = enviarMock.mock.calls.find((c) => c[1] === '/manuais/procedimentos/recolha')![2];
    expect(corpo.processos_excluidos).toEqual([]);
  });

  it('desmarcar um processo e gravar envia a lista de excluídos (B-03)', async () => {
    ecra('manual');
    fireEvent.click(await screen.findByRole('checkbox', { name: /Plano de férias/ }));
    expect(screen.getByText('Há alterações por gravar.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Gravar/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('put', '/manuais/procedimentos', expect.objectContaining({ processos_excluidos: ['ferias'], versao: '1.0' })));
    await waitFor(() => expect(screen.queryByText('Há alterações por gravar.')).not.toBeInTheDocument());
  });

  it('imprimir entrega ao motor comum o documento completo (HTML estático, sem scripts)', async () => {
    ecra('manual');
    fireEvent.click(await screen.findByRole('button', { name: 'Imprimir' }));
    await waitFor(() => expect((globalThis as { __pedido?: unknown }).__pedido).toBeDefined());
    const p = (globalThis as unknown as { __pedido: { titulo: string; conteudo: string } }).__pedido;
    expect(p.titulo).toBe('Manual de Procedimentos');
    expect(p.conteudo).toContain('Os procedimentos');
    expect(p.conteudo).toContain('Processamento salarial');
    expect(p.conteudo).not.toMatch(/<script/i);
  });

  it('sem permissões de edição e de impressão não há botões de escrita nem de impressão', async () => {
    permissoes = new Set(['proc_manual_view']);
    ecra('manual');
    await screen.findByText('O que entra');
    expect(screen.queryByRole('button', { name: /Gravar/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Imprimir' })).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Pré-visualizar/ })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /Plano de férias/ })).toBeDisabled();
  });

  it('separador Processos: lista com origens, cobertura, escondidos e editor com aviso do painel', async () => {
    ecra('processos');
    expect(await screen.findByText('Processos (3)')).toBeInTheDocument();
    expect(screen.getByText('adaptado')).toBeInTheDocument();
    expect(screen.getByText('próprio')).toBeInTheDocument();
    expect(screen.getByText(/Com painel e sem procedimento escrito: caixa/)).toBeInTheDocument();
    expect(screen.getByText('Gestão de stock')).toBeInTheDocument();
    expect(screen.getByText('Escolha um processo à esquerda para ver e adaptar as suas etapas.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Processamento salarial/ }));
    expect(await screen.findByText('Este processo tem painel de acompanhamento no Fluxo de Processos')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Pagar a tempo.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Subir a etapa 2' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Subir a etapa 1' })).toBeDisabled();
  });

  it('gravar um processo lê as etapas e cruzamentos do formulário e voltar a incluir um escondido', async () => {
    ecra('processos');
    fireEvent.click(await screen.findByRole('button', { name: /Processamento salarial/ }));
    fireEvent.change(await screen.findByDisplayValue('Pagar a tempo.'), { target: { value: 'Pagar sempre a tempo.' } });
    fireEvent.click(screen.getByRole('button', { name: 'Descer a etapa 1' }));
    fireEvent.click(screen.getByRole('button', { name: /Gravar/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('put', '/procedimentos/rh', expect.anything()));
    const corpo = enviarMock.mock.calls.find((c) => c[0] === 'put' && c[1] === '/procedimentos/rh')![2];
    expect(corpo.objetivo).toBe('Pagar sempre a tempo.');
    expect(corpo.etapas.map((e: { nome: string }) => e.nome)).toEqual(['Fechar', 'Dados base']);
    expect(corpo.cruza).toEqual([{ com: 'ferias', onde: 'Dados base', o_que: 'Subsídio' }]);
    expect(corpo.intervenientes).toEqual(['RH']);
    fireEvent.click(screen.getByRole('button', { name: 'Voltar a incluir' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/procedimentos/stock/mostrar'));
  });

  it('os 3 diálogos: novo processo, esconder e repor', async () => {
    ecra('processos');
    fireEvent.click(await screen.findByRole('button', { name: /Novo/ }));
    const dlg = await screen.findByRole('dialog', { name: 'Novo processo' });
    fireEvent.change(within(dlg).getByRole('textbox'), { target: { value: 'Novo proc' } });
    fireEvent.click(within(dlg).getByRole('button', { name: 'Criar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/procedimentos', { titulo: 'Novo proc' }));

    fireEvent.click(await screen.findByRole('button', { name: /Plano de férias/ }));
    fireEvent.click(await screen.findByRole('button', { name: /Repor/ }));
    const repor = await screen.findByRole('dialog', { name: 'Repor o texto do ERP' });
    expect(within(repor).getByText(/volta ao texto que vem com o ERP/)).toBeInTheDocument();
    fireEvent.click(within(repor).getByRole('button', { name: 'Repor' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('delete', '/procedimentos/ferias'));

    fireEvent.click(await screen.findByRole('button', { name: /Esconder/ }));
    const esc = await screen.findByRole('dialog', { name: 'Esconder do manual' });
    fireEvent.click(within(esc).getByRole('button', { name: 'Esconder' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/procedimentos/ferias/ocultar'));
  });

  it('sem proc_editar o separador Processos não mostra Novo, Gravar, Repor nem Esconder', async () => {
    permissoes = new Set(['proc_manual_view']);
    ecra('processos');
    fireEvent.click(await screen.findByRole('button', { name: /Plano de férias/ }));
    await screen.findByText('Plano de férias', { selector: 'span' });
    expect(screen.queryByRole('button', { name: /Novo/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Gravar/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Repor/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Esconder/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Voltar a incluir' })).not.toBeInTheDocument();
  });
});
