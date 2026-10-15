import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import IAAssistente from './IAAssistente';
import { pedidoCalculo, usaMotor, validacoesFalhadas, novoDia, type ResultadoDia } from './ia/regrasFicheiro';

vi.setConfig({ testTimeout: 60_000 });

let permissoes = new Set(['ia_assistente_view', 'ia_regras', 'ia_lancar', 'lancamentos_post']);
vi.mock('@/sessao/SessaoContexto', () => ({
  useSessao: () => ({
    pode: (...p: string[]) => p.some((x) => permissoes.has(x)),
    menu: [{ id: 'contab', nome: 'Contabilidade', ecras: [{ id: 'lancamentos', nome: 'Lançamentos', vistas: [], pai: null }] }],
  }),
}));

const REGRA = {
  id: 5, nome: 'fecho_caixa_ficticio', nome_regra: 'fecho_caixa_ficticio', ficheiro: 'fecho.json', ativo: true, motor: 'AMBOS', versao: 2,
  importado_em: null, importado_por: 'Teste', problema: null, descricao: 'Fecho de caixa (teste)', unidade: 'Loja 1', linhas: 2, diario: '01',
  contas_em_falta: ['99.9'], contas_totalizadoras: [],
};
const DETALHE = {
  ...REGRA, documento: 'Fecho de caixa', por_dia: true, colunas: [], calculadas: ['vendas_net'], conteudo: {}, avisos: [],
  entradas: [{ nome: 'total_numerario', rotulo: 'Numerário / Dinheiro', ajuda: 'Total recebido em numerário' }],
};
const DIA: ResultadoDia = {
  data: '2026-10-14', referencia: 'FC/26/10/14', valores: { total_numerario: 100 }, calculados: {},
  linhas: [
    { codigo_conta: '43.1', tipo_dc: 'D', valor: 100, descricao: 'Dinheiro', nota_demonstracao: '10', nota_fluxo: '111' },
    { codigo_conta: '71.1', tipo_dc: 'C', valor: 100, descricao: 'Vendas', nota_demonstracao: '22', nota_fluxo: null },
  ],
  total_debito: '100.00', total_credito: '100.00',
  validacoes: [{ texto: 'soma_metodos_pagamento == total_vendas_com_iva', ok: false, detalhe: '100,00 == 120,00' }],
  erros: [], pontos: ['Valores ilegíveis no documento: talão 3.'],
  proposta: { origem: 'REGRA_FICHEIRO', diario_id: 1, diario: '01 Vendas', data_documento: '2026-10-14', numero_documento: 'FC/26/10/14', descricao: 'Fecho', linhas: [],
    debito: '100.00', credito: '100.00', equilibrado: true, justificacao: null, avisos: [] },
};

const enviarMock = vi.fn((..._a: unknown[]) => Promise.resolve({ dados: { regra: REGRA, motor: 'INTERNA', dias: [DIA], propostas_validas: 1 }, mensagem: 'ok' }));
vi.mock('@/api/cliente', () => ({
  obter: vi.fn((url: string) => Promise.resolve(url.endsWith('/ficheiros-regras') ? [REGRA] : url.endsWith('/estado') ? { ativo: false, configurado: true, ia_disponivel: false } : DETALHE)),
  enviar: (...a: unknown[]) => enviarMock(...a),
  http: { post: vi.fn(), patch: vi.fn(() => Promise.resolve({ data: {} })) },
}));

function montar() {
  const cliente = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={cliente}>
      <MemoryRouter initialEntries={['/m/contab/ia_assistente']}>
        <Routes>
          <Route path="/m/contab/ia_assistente" element={<IAAssistente />} />
          <Route path="/m/contab/lancamentos/novo" element={<p>Formulário de lançamento</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('regras em ficheiro — funções puras', () => {
  it('monta o pedido de cálculo só com os valores preenchidos e os pontos a confirmar', () => {
    const d = { ...novoDia('2026-10-14'), valores: { a: 10, b: null }, outroMetodo: ' Cheque ', outroValor: 5, ilegivel: '', netImpresso: null };
    expect(pedidoCalculo([d], [{ nome: 'a', rotulo: 'A', ajuda: '' }, { nome: 'b', rotulo: 'B', ajuda: '' }])).toEqual({
      dias: [{ data: '2026-10-14', valores: { a: 10 }, outros: [{ metodo: 'Cheque', valor: 5 }], ilegivel: null, net_impresso: null }],
    });
    expect(usaMotor({ motor: 'AMBOS', ativo: true }, 'EXTERNA')).toBe(true);
    expect(usaMotor({ motor: 'INTERNA', ativo: true }, 'EXTERNA')).toBe(false);
    expect(usaMotor({ motor: 'AMBOS', ativo: false }, 'INTERNA')).toBe(false);
    expect(validacoesFalhadas(DIA)).toBe(1);
  });
});

describe('Assistente IA — ecrã ia_assistente', { timeout: 180_000 }, () => {   // formulários AntD pesados: lentos com a suite completa em paralelo
  beforeEach(() => {
    permissoes = new Set(['ia_assistente_view', 'ia_regras', 'ia_lancar', 'lancamentos_post']);
    enviarMock.mockClear();
  });

  it('lista as regras com as contas em falta e, só com ia_regras, deixa importar', async () => {
    montar();
    expect(await screen.findByText('fecho_caixa_ficticio')).toBeInTheDocument();
    expect(screen.getByText(/Contas que não existem no plano: 99.9/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Importar ficheiro de regras/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: 'Motor interno' })).toBeInTheDocument();
  });

  it('sem ia_regras nem ia_lancar só se vê (sem importar, sem motores)', async () => {
    permissoes = new Set(['ia_assistente_view']);
    montar();
    expect(await screen.findByText('fecho_caixa_ficticio')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Importar ficheiro de regras/ })).toBeNull();
    expect(screen.queryByRole('tab', { name: 'Motor interno' })).toBeNull();
    expect(screen.getByRole('switch', { name: /activa/ })).toBeDisabled();
  });

  it('motor interno: calcula no servidor, mostra as validações e pede confirmação antes de abrir o formulário', async () => {
    montar();
    await screen.findByText('fecho_caixa_ficticio', undefined, { timeout: 10_000 });
    fireEvent.click(await screen.findByRole('button', { name: 'Motor interno' }, { timeout: 10_000 }));
    const campo = await screen.findByRole('spinbutton', { name: 'Numerário / Dinheiro' }, { timeout: 10_000 });
    fireEvent.change(campo, { target: { value: '100' } });
    fireEvent.click(screen.getByRole('button', { name: 'Calcular e propor' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalled());
    const [metodo, url, corpo] = enviarMock.mock.calls[0] as [string, string, { dias: { valores: Record<string, number> }[] }];
    expect([metodo, url]).toEqual(['post', '/contabilidade/assistente/ficheiros-regras/5/calcular']);
    expect(corpo.dias[0].valores).toEqual({ total_numerario: 100 });

    expect(await screen.findByText('Referência: FC/26/10/14 — 14/10/2026', undefined, { timeout: 10_000 })).toBeInTheDocument();
    const validacoes = screen.getByRole('list', { name: 'Validações da regra' });
    expect(within(validacoes).getByText('soma_metodos_pagamento == total_vendas_com_iva')).toBeInTheDocument();
    expect(screen.getByText('Valores ilegíveis no documento: talão 3.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Rever no formulário de lançamento' }));
    expect(await screen.findByText('Validações por cumprir')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Continuar' }));
    expect(await screen.findByText('Formulário de lançamento')).toBeInTheDocument();
  });

  it('IA externa desligada: explica e não deixa enviar o documento', async () => {
    montar();
    fireEvent.click(await screen.findByRole('tab', { name: 'IA externa' }));
    expect(await screen.findByText('A IA está desligada nesta empresa')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Ler o documento e propor/ })).toBeDisabled();
  });
});
