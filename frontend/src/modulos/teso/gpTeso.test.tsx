import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { reporPreferenciasColunasLocal, reporPreferenciasVistaLocal } from '@/componentes/vistas';

const obter = vi.fn();
const enviar = vi.fn();
vi.mock('@/api/cliente', () => ({
  obter: (...a: unknown[]) => obter(...a),
  enviar: (...a: unknown[]) => enviar(...a),
  obterPagina: vi.fn().mockResolvedValue({ itens: [], total: 0 }),
  descarregar: vi.fn(),
}));
const imprimirDocumento = vi.fn();
vi.mock('@/componentes/impressao/motor', async (orig) => ({ ...(await orig<object>()), imprimirDocumento: (...a: unknown[]) => imprimirDocumento(...a) }));
let permissoes: (c: string) => boolean = () => true;
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...c: string[]) => c.some((x) => permissoes(x)), empresa: null, utilizador: { nome_utilizador: 'admin' } }) }));

import Conciliacao, { ACEITAR_EXTRATO, validarFicheiroExtrato } from './Conciliacao';
import { COLUNAS_HISTORICO, textoPeriodo } from './ReconciliacaoExtras';
import FolhaCaixa from './FolhaCaixa';
import { accoesSessao, sessaoAbertaRecente, simboloMoeda, valorComMoeda } from './regras';
import { ICONES_FA } from '@/componentes/fluxos/iconesFa';
import { bytesModeloExtrato, MODELO_EXTRATO, NOME_MODELO_EXTRATO } from './modeloExtrato';

vi.setConfig({ testTimeout: 120_000 });

const MEIOS = [{ id: 1, codigo_conta: '43.1.1', descricao: 'Banco BAI', ativo: true, predefinido: true }];
const MAPA = {
  conta: '43.1.1', data: '2026-10-16', saldo_diario: '1000.00', saldo_banco_esperado: '1000.00',
  por_reconciliar_diario: { total: '100.00', linhas: [{ id: 11, data_documento: '2026-10-10', numero_lan: 'L1', numero_documento: 'RC 1', descricao: 'Rec', tipo_dc: 'D', valor: '100.00' }] },
  por_reconciliar_extrato: { total: '100.00', linhas: [{ id: 21, data: '2026-10-10', referencia: 'TRF1', descricao: 'Trf', tipo_dc: 'C', valor: '100.00', estado: 'PENDENTE' }] },
};
const HISTORICO = [
  { id: 1, reconciliacao_codigo: 'REC-20261010-0001', data: '2026-10-10T10:00:00', valor_total: '100.00', estado: 'CONCILIADO_BANCO', conta: '43.1.1', tipo: 'MANUAL', grupos: 1, motivo_anulacao: null, periodo_inicio: '2026-10-01', periodo_fim: '2026-10-10', itens: 3 },
  { id: 2, reconciliacao_codigo: 'REC-20261011-0002', data: '2026-10-11T10:00:00', valor_total: '50.00', estado: 'CONCILIADO_BANCO', conta: '43.1.1', tipo: 'AUTOMATICA', grupos: 1, motivo_anulacao: null, periodo_inicio: '2026-10-11', periodo_fim: '2026-10-11', itens: 1 },
];
const RASCUNHOS = [{ id: 9, codigo_conta: '43.1.1', periodo_inicio: null, periodo_fim: null, grupos: [{ extrato: [21], lancamentos: [11] }], observacoes: null, criado_por: 'ana', atualizado_em: '2026-10-12T09:00:00' }];

const sessao = (p: Record<string, unknown>) => ({
  codigo_conta: '45.1', operador: 'admin', data_abertura: '2026-10-15', data_fecho: null, saldo_abertura: '1000.00', saldo_fecho: null, saldo_fisico: null,
  codigo_moeda: null, numeros_lan_contabilizacao: null, fechado_por: null, contabilizado_em: null, total_entradas: '0.00', total_saidas: '0.00', ...p,
});
const SESSOES = [
  sessao({ id: 5, estado: 'FECHADA', n_movimentos: 2 }),
  sessao({ id: 6, estado: 'ABERTA', n_movimentos: 0 }),
  sessao({ id: 7, estado: 'ABERTA', n_movimentos: 3 }),
  sessao({ id: 8, estado: 'CONTABILIZADA', n_movimentos: 1 }),
];

function respostas(url: string) {
  if (url === '/tesouraria/meios-pagamento') return MEIOS;
  if (url === '/tesouraria/reconciliacao/mapa') return MAPA;
  if (url === '/tesouraria/reconciliacao/historico') return HISTORICO;
  if (url === '/tesouraria/reconciliacao/rascunhos') return RASCUNHOS;
  if (url === '/tesouraria/caixa/sessoes') return SESSOES;
  if (url === '/tesouraria/caixa/sessoes/5') return { ...SESSOES[0], saldo_sistema: '1000.00', diferenca: null, movimentos: [{ id: 1, tipo: 'REC', valor: '10.00', data_documento: '2026-10-15', descricao: 'Venda' }] };
  return [];
}

function ecra(no: ReactNode, inicial = '/') {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={[inicial]}><Routes><Route path="/*" element={no} /></Routes></MemoryRouter>
    </QueryClientProvider>,
  );
}

const textosBotoes = (raiz: HTMLElement) => Array.from(raiz.querySelectorAll('button')).map((b) => b.textContent?.trim()).filter(Boolean);

beforeEach(() => {
  permissoes = () => true;
  obter.mockReset().mockImplementation((url: string) => Promise.resolve(respostas(url)));
  enviar.mockReset().mockResolvedValue({ dados: { lote: 'IMP-1', importadas: 2, duplicadas: 1, ignoradas: [] }, mensagem: 'ok' });
  imprimirDocumento.mockReset().mockResolvedValue(null);
  reporPreferenciasColunasLocal();
  reporPreferenciasVistaLocal();
});

describe('Conciliação — cartão de topo do legado', () => {
  it('botões pela ordem, rótulos e ícones do legado', async () => {
    ecra(<Conciliacao />);
    const cartao = (await screen.findByText('Nova conciliação ou Reprocessamento')).closest('.ant-card') as HTMLElement;
    expect(textosBotoes(cartao)).toEqual(['Gerir Divergências (Pendentes)', 'Extratos Carregados', 'Rascunhos', 'Modelo', 'REPROCESSAR EXISTENTES', 'CARREGAR E CONCILIAR']);
    const icones = Array.from(cartao.querySelectorAll('button svg.fx-icone path')).map((p) => p.getAttribute('d'));
    expect(icones).toEqual(['balance-scale', 'university', 'edit', 'file-excel', 'sync-alt', 'file-upload'].map((n) => ICONES_FA[n][2]));
  });

  it('sem permissão de importar não há «Carregar e conciliar»', async () => {
    permissoes = (c) => c !== 'teso_conc_importar';
    ecra(<Conciliacao />);
    const cartao = (await screen.findByText('Nova conciliação ou Reprocessamento')).closest('.ant-card') as HTMLElement;
    expect(textosBotoes(cartao)).not.toContain('CARREGAR E CONCILIAR');
    expect(screen.queryByTestId('ficheiro-extrato')).toBeNull();
  });

  it('modelo do extracto: cabeçalhos e exemplos do legado num XLSX', () => {
    expect(NOME_MODELO_EXTRATO).toBe('Modelo_Extrato_Externo.xlsx');
    expect(MODELO_EXTRATO[0]).toEqual(['Data', 'Referencia', 'Descrição', 'DEBITO', 'CREDITO']);
    const bytes = bytesModeloExtrato();
    expect(String.fromCharCode(bytes[0], bytes[1])).toBe('PK');
    const texto = new TextDecoder().decode(bytes);
    expect(texto).toContain('Extrato Externo');
    expect(texto).toContain('PAGAMENTO FORNECEDOR X');
  });

  it('validação do ficheiro do extracto antes do envio', () => {
    expect(ACEITAR_EXTRATO).toBe('.xlsx,.xls,.csv');
    expect(validarFicheiroExtrato({ name: 'extracto.XLSX', size: 10 })).toBeNull();
    expect(validarFicheiroExtrato({ name: 'extracto.exe', size: 10 })).toMatch(/XLSX, XLS ou CSV/);
    expect(validarFicheiroExtrato({ name: 'e.csv', size: 11 * 1024 * 1024 })).toMatch(/10 MB/);
    expect(validarFicheiroExtrato({ name: 'e.csv', size: 0 })).toMatch(/vazio/);
  });

  it('«Carregar e conciliar» importa pelo endpoint do extracto e volta a correr a correspondência automática', async () => {
    ecra(<Conciliacao />);
    await screen.findByTestId('quadro-conciliacao', {}, { timeout: 20_000 });
    await waitFor(() => expect(obter).toHaveBeenCalledWith('/tesouraria/reconciliacao/sugestoes', expect.anything()));
    const antes = obter.mock.calls.filter((c) => c[0] === '/tesouraria/reconciliacao/sugestoes').length;
    fireEvent.change(screen.getByTestId('ficheiro-extrato'), { target: { files: [new File(['Data;Debito\n'], 'extracto.csv', { type: 'text/csv' })] } });
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/tesouraria/extrato/importar', expect.any(FormData)));
    const dados = enviar.mock.calls[0][2] as FormData;
    expect(dados.get('codigo_conta')).toBe('43.1.1');
    expect((dados.get('ficheiro') as File).name).toBe('extracto.csv');
    await waitFor(() => expect(obter.mock.calls.filter((c) => c[0] === '/tesouraria/reconciliacao/sugestoes').length).toBeGreaterThan(antes));
  });

  it('ficheiro inválido não chega ao servidor', async () => {
    ecra(<Conciliacao />);
    await screen.findByTestId('quadro-conciliacao', {}, { timeout: 20_000 });
    fireEvent.change(screen.getByTestId('ficheiro-extrato'), { target: { files: [new File(['x'], 'virus.exe')] } });
    await screen.findByText('Use um ficheiro XLSX, XLS ou CSV.');
    expect(enviar).not.toHaveBeenCalled();
  });

  it('«Rascunhos» lista os rascunhos do servidor e «Continuar» repõe a selecção no tratamento manual', async () => {
    ecra(<Conciliacao />);
    await screen.findByTestId('quadro-conciliacao', {}, { timeout: 20_000 });
    fireEvent.click(screen.getByText('Rascunhos'));
    const modal = (await screen.findByText('Rascunhos de conciliação')).closest('.ant-modal') as HTMLElement;
    await within(modal).findByText('#9');
    expect([...new Set(Array.from(modal.querySelectorAll('th')).map((t) => t.textContent?.trim()).filter((t) => t && t !== 'Utilizador'))]).toEqual(['Data Gravação', 'ID Rascunho', 'Conta', 'Grupos', 'Ações']);
    fireEvent.click(within(modal).getByText('Continuar'));
    await screen.findByText('Rascunho #9 aberto (1 grupo(s)).');
    expect(screen.getByText('Espaço de Tratamento Manual')).toBeTruthy();
  });
});

describe('Conciliação — histórico com as colunas do legado', () => {
  it('período do legado', () => {
    expect(textoPeriodo('2026-10-01', '2026-10-10')).toBe('01/10/2026 a 10/10/2026');
    expect(textoPeriodo('2026-10-11', '2026-10-11')).toBe('11/10/2026');
    expect(textoPeriodo(null, null)).toBe('---');
  });

  it('colunas pela ordem do legado, itens e período vindos da API', async () => {
    ecra(<Conciliacao />);
    const cartao = (await screen.findByText('Histórico de Conciliações Bancárias')).closest('.ant-card') as HTMLElement;
    await within(cartao).findByText('REC-20261010-0001');
    // «Tipo» (do novo) só aparece em ecrãs largos; as do legado aparecem sempre e pela mesma ordem
    const titulos = Array.from(cartao.querySelectorAll('thead th')).map((t) => t.textContent?.trim()).filter((t) => t && t !== 'Tipo');
    expect(titulos).toEqual(COLUNAS_HISTORICO.filter((t) => t !== 'Tipo'));
    expect(within(cartao).getByText('01/10/2026 a 10/10/2026')).toBeTruthy();
    expect(within(cartao).getByText('3')).toBeTruthy();
    expect(within(cartao).getByText('Consultar histórico')).toBeTruthy();
    expect(within(cartao).getByLabelText('Reprocessar REC-20261010-0001')).toBeTruthy();
  });

  it('pesquisa por ID e «Reabrir Selecionadas» anula cada uma com o motivo', async () => {
    ecra(<Conciliacao />);
    const cartao = (await screen.findByText('Histórico de Conciliações Bancárias')).closest('.ant-card') as HTMLElement;
    await within(cartao).findByText('REC-20261011-0002');
    fireEvent.change(within(cartao).getByLabelText('Pesquisar ID'), { target: { value: '0002' } });
    fireEvent.click(within(cartao).getByText('Consultar histórico'));
    await waitFor(() => expect(within(cartao).queryByText('REC-20261010-0001')).toBeNull());
    fireEvent.change(within(cartao).getByLabelText('Pesquisar ID'), { target: { value: '' } });
    fireEvent.click(within(cartao).getByText('Consultar histórico'));
    await within(cartao).findByText('REC-20261010-0001');
    fireEvent.click(within(cartao).getByLabelText('Seleccionar REC-20261010-0001'));
    fireEvent.click(within(cartao).getByLabelText('Seleccionar REC-20261011-0002'));
    fireEvent.click(within(cartao).getByText('Reabrir Selecionadas'));
    const modal = (await screen.findByText('Reabrir 2 conciliações')).closest('.ant-modal') as HTMLElement;
    fireEvent.change(within(modal).getByLabelText('Motivo'), { target: { value: 'Extracto errado' } });
    fireEvent.click(within(modal).getByText('Reabrir'));
    await waitFor(() => expect(enviar).toHaveBeenCalledTimes(2));
    expect(enviar).toHaveBeenCalledWith('post', '/tesouraria/reconciliacao/REC-20261010-0001/anular', { motivo: 'Extracto errado' });
    expect(enviar).toHaveBeenCalledWith('post', '/tesouraria/reconciliacao/REC-20261011-0002/anular', { motivo: 'Extracto errado' });
  });
});

describe('Folha de Caixa — acções na linha da lista de sessões', () => {
  it('eliminar só na sessão aberta sem movimentos (contagem da lista); sem contagem não elimina', () => {
    expect(accoesSessao({ estado: 'ABERTA', n_movimentos: 0 }, () => true).podeEliminar).toBe(true);
    expect(accoesSessao({ estado: 'ABERTA', n_movimentos: 2 }, () => true).podeEliminar).toBe(false);
    expect(accoesSessao({ estado: 'ABERTA' }, () => true).podeEliminar).toBe(false);
    expect(accoesSessao({ estado: 'FECHADA', n_movimentos: 0 }, () => true).podeEliminar).toBe(false);
  });

  it('imprimir, contabilizar e eliminar com as regras e as acções do detalhe', async () => {
    ecra(<FolhaCaixa />);
    const linhas = await waitFor(() => { const l = document.querySelectorAll('.ant-table-tbody tr.ant-table-row'); if (l.length !== 4) throw new Error('sem linhas'); return l; }, { timeout: 20_000 });
    const tabela = linhas[0].closest('table') as HTMLElement;
    expect(within(tabela).getAllByLabelText('Imprimir')).toHaveLength(4);
    expect(within(tabela).getAllByLabelText('Contabilizar')).toHaveLength(1);   // só a FECHADA
    expect(within(tabela).getAllByLabelText('Eliminar')).toHaveLength(1);       // só a ABERTA sem movimentos
    expect(within(tabela).getAllByLabelText('Ir para Caixa')).toHaveLength(2);

    // imprimir: lê a sessão com os movimentos e imprime a mesma folha do detalhe
    fireEvent.click(within(tabela).getAllByLabelText('Imprimir')[0]);
    await waitFor(() => expect(imprimirDocumento).toHaveBeenCalled());
    expect(obter).toHaveBeenCalledWith('/tesouraria/caixa/sessoes/5');
    expect(imprimirDocumento.mock.calls[0][0]).toMatchObject({ titulo: 'Folha de caixa — sessão #5' });

    // contabilizar: a mesma confirmação do detalhe e o mesmo POST
    fireEvent.click(screen.getByLabelText('Contabilizar'));
    const conf = (await screen.findAllByText('Contabilizar a sessão?'))[0].closest('.ant-modal') as HTMLElement;
    fireEvent.click(within(conf).getAllByText('Contabilizar').at(-1) as HTMLElement);
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('post', '/tesouraria/caixa/sessoes/5/contabilizar', undefined));

    // eliminar: Popconfirm do detalhe e DELETE da sessão
    fireEvent.click(screen.getByLabelText('Eliminar'));
    const pop = (await screen.findByText('Eliminar esta sessão (sem movimentos)?')).closest('.ant-popover') as HTMLElement;
    fireEvent.click(within(pop).getByText('Eliminar'));
    await waitFor(() => expect(enviar).toHaveBeenCalledWith('delete', '/tesouraria/caixa/sessoes/6', undefined));
  });

  it('sem as permissões não há contabilizar nem eliminar na linha', async () => {
    permissoes = (c) => !['teso_caixa_contabilizar', 'teso_caixa_eliminar'].includes(c);
    ecra(<FolhaCaixa />);
    await waitFor(() => { if (document.querySelectorAll('.ant-table-tbody tr.ant-table-row').length !== 4) throw new Error('sem linhas'); }, { timeout: 20_000 });
    expect(screen.queryByLabelText('Contabilizar')).toBeNull();
    expect(screen.queryByLabelText('Eliminar')).toBeNull();
  });
});

describe('Folha de Caixa — estrutura e colunas do legado (ui_folha_caixa.js:52-125)', () => {
  it('moeda da sessão e sessão aberta para «Caixa Aberta»', () => {
    expect(simboloMoeda(null)).toBe('Kz');
    expect(simboloMoeda('AOA')).toBe('Kz');
    expect(simboloMoeda('USD')).toBe('US$');
    expect(valorComMoeda('1234.5', 'USD')).toMatch(/^1\s234,50 US\$$/);
    expect(valorComMoeda(null, null)).toBe('—');
    expect(sessaoAbertaRecente(SESSOES as never)?.id).toBe(7);
    expect(sessaoAbertaRecente([])).toBeUndefined();
  });

  it('título, sub-separadores «Sessões | Caixa Aberta», cartão «Histórico de Sessões» e colunas pela ordem', async () => {
    obter.mockImplementation((url: string) => Promise.resolve(url === '/tesouraria/caixa/sessoes'
      ? [sessao({ id: 5, estado: 'FECHADA', n_movimentos: 2, descricao_conta: 'Caixa principal', total_entradas: '200.00', total_saidas: '50.00', saldo_fecho: '1150.00' }),
        sessao({ id: 9, estado: 'ABERTA', n_movimentos: 0, codigo_conta: '45.2', codigo_moeda: 'USD', saldo_abertura: '10.00' })]
      : respostas(url)));
    // ecrã largo: as colunas «responsive» (sm/md/lg) só aparecem quando os min-width respondem
    const original = window.matchMedia;
    window.matchMedia = ((q: string) => ({ ...original(q), matches: q.includes('min-width') })) as typeof window.matchMedia;
    try {
      await verificarListaLegado();
    } finally {
      window.matchMedia = original;
    }
  });

  async function verificarListaLegado() {
    ecra(<FolhaCaixa />);
    expect(await screen.findByRole('heading', { name: /Folha de Caixa/ })).toBeTruthy();
    const cartao = (await screen.findByText('Histórico de Sessões')).closest('.ant-card') as HTMLElement;
    const linhas = await waitFor(() => { const l = cartao.querySelectorAll('.ant-table-tbody tr.ant-table-row'); if (l.length !== 2) throw new Error('sem linhas'); return l; }, { timeout: 20_000 });
    expect(screen.getAllByRole('tab').map((t) => t.textContent?.trim())).toEqual(['Sessões', 'Caixa Aberta']);
    const titulos = Array.from(cartao.querySelectorAll('thead th')).map((t) => t.textContent?.trim()).filter(Boolean);
    expect(titulos).toEqual(['Data Abertura', 'Conta', 'Operador', 'Saldo Inicial', 'Entradas', 'Saídas', 'Saldo Final', 'Estado', 'Acções']);
    const fechada = Array.from(linhas[0].querySelectorAll('td')).map((td) => td.textContent?.replace(/\s/g, ' ').trim());
    expect(fechada[1]).toBe('45.1 Caixa principal');
    expect(fechada.slice(3, 8)).toEqual(['1 000,00 Kz', '200,00 Kz', '50,00 Kz', '1 150,00 Kz', 'FECHADA']);
    expect(linhas[0].querySelector('.ant-tag')).toBeNull();
    expect(linhas[1].textContent).toContain('10,00 US$');
  }

  it('sem sessão aberta não há «Caixa Aberta»', async () => {
    obter.mockImplementation((url: string) => Promise.resolve(url === '/tesouraria/caixa/sessoes' ? [sessao({ id: 5, estado: 'FECHADA', n_movimentos: 2 })] : respostas(url)));
    ecra(<FolhaCaixa />);
    await screen.findByText('Histórico de Sessões');
    expect(screen.getAllByRole('tab').map((t) => t.textContent?.trim())).toEqual(['Sessões']);
  });
});
