import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { Contrato, FichaContrato, ListasContratos, Margem, Renda, RendasDoPeriodo, ResumoContratos } from './apiContratos';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const opc = (pares: [string, string][]) => pares.map(([valor, rotulo]) => ({ valor, rotulo }));
const LISTAS: ListasContratos = {
  tipos: opc([['ARRENDAMENTO', 'Arrendamento (a empresa é senhoria)'], ['SUBARRENDAMENTO', 'Subarrendamento (a empresa subarrenda o que arrendou)'], ['TOMADA', 'Arrendamento tomado (a empresa é inquilina)']]),
  ambitos: opc([['FRACCAO', 'Uma fracção'], ['IMOVEL', 'Imóvel inteiro'], ['PARCIAL', 'Várias fracções']]),
  finalidades_fiscais: opc([['HABITACAO', 'Habitação'], ['COMERCIO', 'Comércio']]), periodicidades: opc([['MENSAL', 'Mensal'], ['ANUAL', 'Anual']]),
  estados_contrato: opc([['RASCUNHO', 'Rascunho'], ['ACTIVO', 'Activo'], ['SUSPENSO', 'Suspenso'], ['TERMINADO', 'Terminado'], ['DENUNCIADO', 'Denunciado']]),
  estados_renda: opc([['PREVISTA', 'Prevista'], ['APROVADA', 'Aprovada'], ['FACTURADA', 'Facturada'], ['PAGA', 'Paga'], ['ANULADA', 'Anulada']]),
  actualizacoes: opc([['NAO', 'Não actualiza']]), isencoes: opc([['NAO', 'Sem isenção']]), selo_encargo: opc([['SENHORIO', 'Encargo do senhorio']]), renovacoes: opc([['NAO', 'Não renova']]),
  doc_tipos: opc([['FT', 'Factura (recebe-se depois)']]), tipos_caucao: opc([['RECEBIDA', 'Recebida'], ['DEVOLVIDA', 'Devolvida'], ['RETIDA', 'Retida']]),
  lados: opc([['SENHORIO', 'A empresa recebe'], ['INQUILINO', 'A empresa paga']]), eventos: opc([['CRIADO', 'Criado'], ['ACTIVADO', 'Activado']]),
};

const contrato = (o: Partial<Contrato>): Contrato => ({
  id: 1, numero: 'AR 2026/0001', tipo: 'ARRENDAMENTO', posicao: 'SENHORIO', estado: 'ACTIVO', ambito: 'FRACCAO', imovel_id: 7, fracao_id: 3, terceiro_id: 9, contrato_pai_id: null, permite_sub: false,
  finalidade_fiscal: 'HABITACAO', inicio: '2026-01-16', fim: '2026-12-31', fim_real: null, motivo_saida: null, periodicidade: 'MENSAL', antecipada: true, dia_vencimento: 5, renda: 100000, pro_rata: true,
  actualizacao: 'NAO', actualizacao_valor: 0, actualizacao_meses: 12, actualizacao_base: null, ultima_actualizacao: null, renda_anterior: null, caucao: 200000, caucao_recebida: 200000, caucao_devolvida: 0,
  caucao_retida: 0, retencao_ip: true, isencao_ip: 'NAO', selo_encargo: 'SENHORIO', doc_tipo: 'FT', renovacao: 'NAO', renovacao_meses: 12, aviso_dias: 60, conta_custo: null, conta_retencao: null,
  conta_financeira: null, mora_mensal: null, registo_agt_data: null, outra_parte_morada: null, outra_parte_doc: null, outra_parte_contacto: null, notas: null, terceiro_nome: 'Inquilino Fictício',
  imovel_codigo: 'PR0001', imovel_nome: 'Edifício Girassol', fracoes: ['101'], contrato_pai_numero: null, caucao_em_poder: 200000, ...o,
});
const LISTA: Contrato[] = [contrato({}), contrato({ id: 2, numero: 'AR 2026/0002', estado: 'RASCUNHO', fracoes: ['102'], terceiro_nome: 'Outro Inquilino' })];
const ficha = (o: Partial<FichaContrato> = {}): FichaContrato => ({
  ...contrato(o), encargos: [{ id: 1, designacao: 'Condomínio', valor: 5000, incluido_na_renda: false }], fracoes_detalhe: [{ id: 3, codigo: 'PR0001-01', designacao: '101', estado: 'ARRENDADA', area_util: 40, renda_referencia: 100000 }],
  terceiro: { id: 9, nome: 'Inquilino Fictício', nif: '5000000555' }, imovel: { id: 7, codigo: 'PR0001', nome: 'Edifício Girassol', papel: 'PROPRIO', morada: null },
  historico: [{ id: 1, evento: 'ACTIVADO', estado_de: 'RASCUNHO', estado_para: 'ACTIVO', motivo: null, dados: null, em: '2026-03-01T10:00:00+01:00' }], filhos: [], renda_no_periodo: 100000,
  conta_corrente: { facturado: 100000, esperado: 85000, recebido: 0, em_divida: 85000, juros: 425, ip_retido: 15000, selo_senhorio: 100, caucao_em_poder: 200000 },
  fiscalidade: { periodo: '2026-03', ano: 2026, base: 105000, ip_valor: 15750, ip_retido: true, ip_efectivo: 15, selo_pct: 0.1, selo_valor: 105, iva_taxa: 0, iva_valor: 0, total_facturar: 105000, total_receber: 89250 },
  caucao_ledger: { movimentos: [{ id: 5, tipo: 'RECEBIDA', valor: 200000, data: '2026-01-16', conta_financeira: '4311', motivo: null, estado: 'VALIDO', lancamento_numero: 'IMO2026000001', estorno_numero: null, criado_por: 'Ana' }],
    resumo: { acordada: 200000, recebida: 200000, devolvida: 0, retida: 0, em_poder: 200000, por_receber: 0, lado: 'SENHORIO' } },
  avisos: [{ tipo: 'MORA', contrato_id: 1, numero: 'AR 2026/0001', texto: 'Contrato AR 2026/0001: 1 renda(s) em atraso, 85 000,00 Kz em dívida.' }], ...o,
});
const RESUMO: ResumoContratos = { contratos: 2, activos: 1, renda_mensal: 100000, renda_mensal_paga: 0, margem_mensal: 100000, caucoes: 200000, caucoes_entregues: 0, periodo: '2026-03', mes_previsto: 100000, mes_facturado: 0,
  mes_a_pagar: 0, mes_pago: 0, por_facturar: 1, por_pagar: 0, em_divida: 85000, rendas_em_atraso: 1, juros_mora: 425, a_expirar: 0 };
const renda = (o: Partial<Renda>): Renda => ({
  id: 11, contrato_id: 1, periodo: '2026-03', posicao: 'SENHORIO', de: '2026-03-01', ate: '2026-03-31', vencimento: '2026-03-05', estado: 'PREVISTA', renda: 100000, factor: 1, encargos: 0, desconto: 0, extras: [], extras_total: 0,
  base: 100000, ip_base: 60000, ip_valor: 15000, ip_retido: true, selo_pct: 0.1, selo_valor: 100, selo_encargo: 'SENHORIO', iva_taxa: 0, iva_valor: 0, total_facturar: 100000, total_receber: 85000, venda_id: null,
  documento_tesouraria_id: null, numero_documento: null, retencao_lancamento: null, notas: null, contrato_numero: 'AR 2026/0001', terceiro_nome: 'Inquilino Fictício', imovel_nome: 'Edifício Girassol', fracao_designacao: '101',
  pago: 0, divida: 0, atraso: 0, juros: 0, documento: null, documento_estado: null, ...o,
});
const RENDAS: RendasDoPeriodo = { linhas: [renda({}), renda({ id: 12, contrato_id: 2, contrato_numero: 'AR 2026/0002', estado: 'APROVADA', fracao_designacao: '102' }), renda({ id: 13, contrato_numero: 'AR 2026/0003', estado: 'FACTURADA', atraso: 15, divida: 85000, juros: 425, documento: 'FT 2026/0007' })],
  outro_lado: 0, totais: { previstas: 85000, aprovadas: 85000, facturadas: 85000, pagas: 0, total_receber: 255000, selo_senhorio: 300, rendas: 3 } };
const MARGEM: Margem = { de: '2026-02', ate: '2026-04', totais: { paga: '300000.00', recebe: '450000.00', margem: '150000.00' }, negativas: 0, vazias: 0,
  linhas: [{ fracao_id: 3, fracao_designacao: '101', imovel_nome: 'Edifício Girassol', paga: '300000.00', recebe: '450000.00', margem: '150000.00', taxa: '50.0', tomadas: ['AT 2026/0001'], subs: ['SB 2026/0001'], negativa: false, vazia: false,
    periodos: [{ periodo: '2026-02', paga: '100000.00', recebe: '150000.00', margem: '50000.00' }] }] };

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import Contratos from './Contratos';
import ModelosTaxas from './ModelosTaxas';
import Rendas from './Rendas';
import Subarrendamento from './Subarrendamento';

const ecra = (ui: ReactNode, url = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[url]}>{ui}</MemoryRouter></QueryClientProvider>);

const TODAS = ['imo_contratos_view', 'imo_rendas_view', 'imo_sub_view', 'imo_modelos_view', 'imo_contrato_editar', 'imo_contrato_activar', 'imo_contrato_terminar', 'imo_contrato_suspender', 'imo_contrato_renovar',
  'imo_caucao_movimentar', 'imo_renda_gerar', 'imo_renda_aprovar', 'imo_renda_facturar', 'imo_renda_pagar', 'imo_sub_debitar', 'imo_taxas_editar'];

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(TODAS);
  let fichaActual = ficha();
  obterMock.mockImplementation((url: string, params?: Record<string, string>) => {
    if (url === '/imobiliario/contratos/listas') return Promise.resolve(LISTAS);
    if (url === '/imobiliario/contratos/resumo') return Promise.resolve(RESUMO);
    if (url === '/imobiliario/contratos/avisos') return Promise.resolve([{ tipo: 'MORA', contrato_id: 1, numero: 'AR 2026/0001', texto: 'Contrato AR 2026/0001: 1 renda(s) em atraso.' }, { tipo: 'RASCUNHO', contrato_id: 2, numero: 'AR 2026/0002', texto: 'O contrato AR 2026/0002 está em rascunho.' }]);
    if (url === '/imobiliario/contratos') return Promise.resolve(LISTA);
    if (url === '/imobiliario/contratos/1') return Promise.resolve(fichaActual);
    if (url === '/imobiliario/contratos/2') return Promise.resolve((fichaActual = ficha({ id: 2, numero: 'AR 2026/0002', estado: 'RASCUNHO', filhos: [{ id: 5, numero: 'SB 2026/0001', estado: 'ACTIVO' }] })));
    if (url === '/imobiliario/contratos/1/conta-corrente' || url === '/imobiliario/contratos/2/conta-corrente') return Promise.resolve({ linhas: [renda({ estado: 'FACTURADA', documento: 'FT 2026/0007', divida: 85000, atraso: 15, juros: 425 })], totais: ficha().conta_corrente });
    if (url === '/imobiliario/rendas') return Promise.resolve(params?.posicao === 'INQUILINO' ? { ...RENDAS, linhas: [renda({ id: 21, posicao: 'INQUILINO', estado: 'APROVADA', contrato_numero: 'AT 2026/0001' })] } : RENDAS);
    if (url === '/imobiliario/subarrendamento/margem') return Promise.resolve(MARGEM);
    if (url.startsWith('/imobiliario/subarrendamento/cadeia/')) return Promise.resolve([{ id: 4, numero: 'AT 2026/0001', estado: 'ACTIVO', inicio: '2026-01-01', fim: null, permite_sub: true, paga: 300000, recebe: 450000, margem: 150000, vivos: 1,
      subarrendamentos: [{ id: 5, numero: 'SB 2026/0001', estado: 'ACTIVO', inicio: '2026-02-01', fim: null, facturado: 450000, em_divida: 0 }] }]);
    if (url === '/imobiliario/configuracao') return Promise.resolve({ produto_renda: null, produto_renda_alojamento: null, conta_caucao_passivo: null, conta_caucao_activo: null, conta_caucao_receita: null, conta_ip_credito: null, completa_facturacao: false, completa_caucao: false, aviso: 'O ERP não traz contas nem artigos pré-escolhidos.' });
    if (url === '/imobiliario/configuracao/artigos' || url === '/imobiliario/contratos/contas') return Promise.resolve([]);
    if (url === '/imobiliario/taxas') return Promise.resolve({ ano: 2026, origem: 'BASE', herdada_de: null, estado_validacao: 'POR_VALIDAR', validada_por: null, validada_em: null, validacao_nota: null, ip_efectivo: 15, aviso: 'a validar', anos_gravados: [], taxas: {} });
    if (url === '/imobiliario/modelos') return Promise.resolve([]);
    if (url === '/imobiliario/modelos/catalogo') return Promise.resolve({ tipos: {}, variaveis: [] });
    return Promise.resolve([]);
  });
  enviarMock.mockResolvedValue({ dados: { aprovadas: 2, ignoradas: [], feitas: [], falhas: [] }, mensagem: 'ok' });
});

describe('Gestão Imobiliária — Contratos', () => {
  it('lista os contratos com indicadores e avisos e filtra por texto', async () => {
    ecra(<Contratos />);
    expect(await screen.findByText('AR 2026/0001')).toBeInTheDocument();
    expect(screen.getByText('Contratos activos (de 2)')).toBeInTheDocument();
    expect(screen.getByText('2 ponto(s) a tratar')).toBeInTheDocument();
    expect(screen.getByText(/está em rascunho/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Novo contrato/ })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Procurar contratos'), { target: { value: 'Outro Inq' } });
    await waitFor(() => expect(screen.queryByText('AR 2026/0001')).toBeNull());
    expect(screen.getByText('AR 2026/0002')).toBeInTheDocument();
  });

  it('sem permissão de escrita não mostra Novo contrato', async () => {
    permissoes = new Set(['imo_contratos_view']);
    ecra(<Contratos />);
    expect(await screen.findByText('AR 2026/0001')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Novo contrato/ })).toBeNull();
  });

  it('abre a ficha: fiscalidade, conta corrente com juros de mora, caução e as acções do estado', async () => {
    ecra(<Contratos />);
    fireEvent.click(await screen.findByText('AR 2026/0001'));
    expect(await screen.findByText(/Fiscalidade de um período, à taxa de 2026/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Actualizar renda' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Suspender' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Terminar/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Activar' })).toBeNull();   // já está activo
    expect(screen.getAllByText(/1 renda\(s\) em atraso/).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('tab', { name: /Rendas e conta corrente/ }));
    expect(await screen.findByText('Juros de mora')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Caução' }));
    expect(await screen.findByRole('button', { name: 'Devolver' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Reter/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Receber caução' })).toBeDisabled();   // já está tudo recebido
    expect(screen.getByText('IMO2026000001')).toBeInTheDocument();
  });

  it('um rascunho pode activar-se e o contrato com subarrendamentos exige confirmar a cascata ao terminar', async () => {
    ecra(<Contratos />);
    fireEvent.click(await screen.findByText('AR 2026/0002'));
    expect(await screen.findByRole('button', { name: 'Activar' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Suspender' })).toBeNull();
  });

  it('terminar um contrato com subarrendamentos mostra o aviso e a confirmação em cascata', async () => {
    obterMock.mockImplementation((url: string) => {
      if (url === '/imobiliario/contratos/1') return Promise.resolve(ficha({ filhos: [{ id: 5, numero: 'SB 2026/0001', estado: 'ACTIVO' }] }));
      if (url === '/imobiliario/contratos/listas') return Promise.resolve(LISTAS);
      if (url === '/imobiliario/contratos/resumo') return Promise.resolve(RESUMO);
      if (url === '/imobiliario/contratos') return Promise.resolve(LISTA);
      if (url === '/imobiliario/contratos/1/conta-corrente') return Promise.resolve({ linhas: [], totais: ficha().conta_corrente });
      return Promise.resolve([]);
    });
    ecra(<Contratos />);
    fireEvent.click(await screen.findByText('AR 2026/0001'));
    fireEvent.click(await screen.findByRole('button', { name: /Terminar/ }));
    expect(await screen.findByText(/dá título a 1 subarrendamento\(s\): SB 2026\/0001/)).toBeInTheDocument();
    expect(screen.getByText(/confirmo a cessação em cascata/)).toBeInTheDocument();
    expect(screen.getByText(/de caução em poder/)).toBeInTheDocument();
  });
});

describe('Gestão Imobiliária — Rendas do período', () => {
  it('marca as rendas previstas e aprovadas e aprova as marcadas', async () => {
    ecra(<Rendas />);
    expect(await screen.findByText('AR 2026/0002')).toBeInTheDocument();
    expect(screen.getByText('Previstas')).toBeInTheDocument();
    const aprovar = screen.getByRole('button', { name: 'Aprovar marcadas' });
    expect(aprovar).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Marcar todas' }));
    expect(aprovar).not.toBeDisabled();
    fireEvent.click(aprovar);
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/rendas/aprovar', { ids: [11, 12] }));
  });

  it('factura as marcadas depois de confirmar que o documento fiscal não se corrige', async () => {
    ecra(<Rendas />);
    await screen.findByText('AR 2026/0002');
    fireEvent.click(screen.getByRole('button', { name: 'Marcar todas' }));
    fireEvent.click(screen.getByRole('button', { name: 'Facturar marcadas' }));
    expect(await screen.findByText(/Vão ser emitidos 2 documento\(s\) fiscal\(is\)/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Facturar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/rendas/facturar', { ids: [11, 12] }));
  });

  it('o lado a pagar mostra Pagar marcadas e não Facturar; sem permissões só consulta', async () => {
    ecra(<Rendas />);
    await screen.findByText('AR 2026/0002');
    fireEvent.click(screen.getByRole('tab', { name: 'A pagar' }));
    expect(await screen.findByText('AT 2026/0001')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pagar marcadas' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Facturar marcadas' })).toBeNull();
  });

  it('com permissão só de consulta não há botões de escrita', async () => {
    permissoes = new Set(['imo_rendas_view']);
    ecra(<Rendas />);
    expect(await screen.findByText('AR 2026/0002')).toBeInTheDocument();
    for (const nome of ['Aprovar marcadas', 'Facturar marcadas', 'Gerar rendas em falta']) expect(screen.queryByRole('button', { name: nome })).toBeNull();
  });
});

describe('Gestão Imobiliária — Subarrendamento e configuração', () => {
  it('mostra a margem por fracção e abre a cadeia', async () => {
    ecra(<Subarrendamento />);
    expect(await screen.findByText('Recebido dos subarrendatários')).toBeInTheDocument();
    expect(await screen.findByText('50.0 %')).toBeInTheDocument();
    expect(screen.getByText('AT 2026/0001')).toBeInTheDocument();
    const linha = screen.getByText('50.0 %').closest('tr') as HTMLElement;
    fireEvent.click(within(linha).getByRole('button', { name: 'Ver a cadeia' }));
    expect(await screen.findByText(/Cadeia da fracção 101/)).toBeInTheDocument();
    expect(await screen.findByText('SB 2026/0001')).toBeInTheDocument();
  });

  it('a configuração da facturação e das contas vem vazia e avisa que o ERP não escolhe contas', async () => {
    ecra(<ModelosTaxas />, '/?separador=contabilizacao');
    expect(await screen.findByText('Sem artigo e sem contas não se factura nem se movimenta a caução')).toBeInTheDocument();
    expect(screen.getByText(/Cauções de terceiros \(passivo\)/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Gravar a configuração' })).toBeInTheDocument();
  });
});
