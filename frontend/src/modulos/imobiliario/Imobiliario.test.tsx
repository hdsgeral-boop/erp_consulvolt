import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import type { Fracao, Imovel, Listas, ModeloDocumento, Resumo, Taxas } from './api';

vi.setConfig({ testTimeout: 120_000 });
configure({ asyncUtilTimeout: 15_000 });

const opc = (pares: [string, string][]) => pares.map(([valor, rotulo]) => ({ valor, rotulo }));
const LISTAS: Listas = {
  papeis: [{ valor: 'PROPRIO', rotulo: 'Próprio', exige_proprietario: false }, { valor: 'ARRENDADO', rotulo: 'Arrendado a terceiros', exige_proprietario: true }],
  tipos_imovel: [{ valor: 'PREDIO', rotulo: 'Prédio / edifício', pisos: true, habitacoes: false }, { valor: 'CONDOMINIO', rotulo: 'Condomínio', pisos: true, habitacoes: true }],
  naturezas: opc([['HABITACIONAL', 'Fracção'], ['ESTACIONAMENTO', 'Vaga de estacionamento'], ['ARRECADACAO', 'Arrecadação']]),
  estados_fracao: opc([['DISPONIVEL', 'Disponível'], ['RESERVADA', 'Reservada'], ['ARRENDADA', 'Arrendada'], ['VENDIDA', 'Vendida'], ['EM_OBRA', 'Em obra'], ['INDISPONIVEL', 'Indisponível']]),
  tipologias: opc([['T2', 'T2'], ['OUTRA', 'Outra']]), finalidades: opc([['HABITACAO', 'Habitação']]), mobilia: opc([['NAO', 'Não mobilada']]),
  estados_imovel: opc([['ACTIVO', 'Activo'], ['INACTIVO', 'Inactivo']]), isencoes_detencao: opc([['NAO', 'Sem isenção']]),
  esquemas: opc([['PORTA', 'Número de porta (101, 102, 201…)'], ['NUMERO', 'Número por piso (1, 2, 3…)']]), tipos_documento: opc([['MATRIZ', 'Caderneta predial / matriz'], ['OUTRO', 'Outro']]),
  provincias: ['Luanda', 'Benguela'],
  transicoes: { DISPONIVEL: ['RESERVADA', 'ARRENDADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'], ARRENDADA: ['DISPONIVEL', 'RESERVADA', 'VENDIDA', 'EM_OBRA', 'INDISPONIVEL'] },
  naturezas_activas: ['HABITACIONAL', 'ARRECADACAO'],
};
const IMOVEL: Imovel = {
  id: 7, codigo: 'PR0001', nome: 'Edifício Girassol', tipo: 'PREDIO', papel: 'PROPRIO', n_pisos: 2, piso_inicial: 1, n_habitacoes: 0, proprietario_terceiro_id: null, ativo_edificio_id: null,
  morada: 'Rua da Missão', municipio: 'Luanda', provincia: 'Luanda', coordenadas: null, matriz_predial: null, registo_predial: null, licenca_utilizacao: null,
  area_terreno: 0, area_bruta: 300, area_util: 0, valor_patrimonial: null, valor_patrimonial_data: null, isencao_detencao: 'NAO', valor_aquisicao: null, data_aquisicao: null, estado: 'ACTIVO',
  documentos: [{ tipo: 'MATRIZ', nome: 'Caderneta', url: 'https://exemplo.ao/m.pdf', data: '2026-01-10' }], notas: null, proprietario_nome: null,
  total_fracoes: 4, ocupadas: 3, livres: 1, taxa_ocupacao: 75, area_fracoes: 200, renda_referencia: 600000, acessorios: 1,
  avisos: ['Sem artigo matricial: é o que identifica o prédio perante a AGT.'], edificio: null,
};
const fr = (o: Partial<Fracao>): Fracao => ({
  id: 1, imovel_id: 7, codigo: 'PR0001-01', designacao: '101', natureza: 'HABITACIONAL', porta_id: null, bloco: null, andar: '1.º andar', numero: '101', piso: 1, tipologia: 'T2',
  finalidade: 'HABITACAO', area_bruta: 50, area_util: 40, quartos: 2, casas_banho: 1, mobilada: 'NAO', estado: 'DISPONIVEL', estado_desde: '2026-11-06', estado_motivo: null, matriz_predial: null,
  valor_patrimonial: null, renda_referencia: 150000, venda_referencia: null, documentos: [], ordem: 1, notas: null, ...o,
});
const FRACOES: Fracao[] = [
  fr({ id: 1, designacao: '101', estado: 'ARRENDADA', ordem: 1 }), fr({ id: 2, codigo: 'PR0001-02', designacao: '102', estado: 'ARRENDADA', ordem: 2 }),
  fr({ id: 3, codigo: 'PR0001-03', designacao: '201', andar: '2.º andar', piso: 2, estado: 'VENDIDA', ordem: 3 }), fr({ id: 4, codigo: 'PR0001-04', designacao: '202', andar: '2.º andar', piso: 2, ordem: 4 }),
  fr({ id: 5, codigo: 'PR0001-05', designacao: 'Arrumos', natureza: 'ARRECADACAO', andar: '2.º andar', piso: 2, ordem: 5, area_bruta: 5 }),
];
const RESUMO: Resumo = { imoveis: 1, unidades: 4, ocupadas: 3, taxa_ocupacao: 75, area_bruta: 200, vagas: 0, arrecadacoes: 1, por_estado: {}, por_papel: {} };
const TAXAS: Taxas = {
  ano: new Date().getFullYear(), origem: 'BASE', herdada_de: null, estado_validacao: 'POR_VALIDAR', validada_por: null, validada_em: null, validacao_nota: null, ip_efectivo: 15,
  aviso: 'Valores de arranque e editáveis: a validar pelo contabilista ou jurista.', anos_gravados: [],
  taxas: { ip_taxa: 25, ip_base: 60, selo_habitacao: 0.1, selo_outro: 0.4, iva_arrendamento: 0, iva_alojamento: 7, mora_mensal: 0, prazo_registo: 10, ip_transmissao: 2, selo_transmissao: 0.3, escritura_fixo: 2000, isencao_ate: 0, isencao_metade_ate: 0, det_escalao1_ate: 5000000, det_escalao1_pct: 0.1, det_escalao2_ate: 6000000, det_escalao2_fixo: 5000, det_escalao3_pct: 0.5, det_terreno_pct: 0.6, det_agravamento_pct: 50, det_agravamento_meses: 12 },
};
const MODELO: ModeloDocumento = { id: 3, tipo: 'RECIBO', nome: 'Recibo de renda', assunto: 'Recibo de renda — {{periodo}}', corpo: 'Recebi {{renda}}\nde {{inquilino}}', activo: true, sistema: true, versao: 1 };

const obterMock = vi.fn();
const enviarMock = vi.fn();
let permissoes = new Set<string>();
vi.mock('@/api/cliente', () => ({ obter: (...a: unknown[]) => obterMock(...a), enviar: (...a: unknown[]) => enviarMock(...a) }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: (...p: string[]) => p.some((x) => permissoes.has(x)), menu: [] }) }));

import Imoveis from './Imoveis';
import MapaOcupacao from './MapaOcupacao';
import ModelosTaxas from './ModelosTaxas';

const ecra = (ui: ReactNode, url = '/') =>
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter initialEntries={[url]}>{ui}</MemoryRouter></QueryClientProvider>);

beforeEach(() => {
  obterMock.mockReset(); enviarMock.mockReset();
  permissoes = new Set(['imo_imoveis_view', 'imo_ocupacao_view', 'imo_modelos_view', 'imo_imovel_editar', 'imo_fraccao_editar', 'imo_estado_mudar', 'imo_modelo_editar', 'imo_taxas_editar']);
  obterMock.mockImplementation((url: string) => {
    if (url === '/imobiliario/listas') return Promise.resolve(LISTAS);
    if (url === '/imobiliario/resumo') return Promise.resolve(RESUMO);
    if (url === '/imobiliario/imoveis') return Promise.resolve([IMOVEL]);
    if (url === '/imobiliario/imoveis/7') return Promise.resolve(IMOVEL);
    if (url === '/imobiliario/imoveis/7/fracoes') return Promise.resolve(FRACOES);
    if (url === '/imobiliario/edificios' || url === '/imobiliario/terceiros') return Promise.resolve([]);
    if (url.startsWith('/imobiliario/fracoes/')) return Promise.resolve({ ...FRACOES[0], imovel: { id: 7, codigo: 'PR0001', nome: 'Edifício Girassol', papel: 'PROPRIO', matriz_predial: null }, porta: null, historico: [{ id: 1, estado_de: null, estado_para: 'DISPONIVEL', motivo: null, utilizador_id: 1, em: '2026-11-06T10:00:00+01:00' }] });
    if (url === '/imobiliario/taxas') return Promise.resolve(TAXAS);
    if (url === '/imobiliario/modelos') return Promise.resolve([MODELO]);
    if (url === '/imobiliario/modelos/catalogo') return Promise.resolve({ tipos: { RECIBO: 'Recibo de renda' }, variaveis: [{ chave: 'renda', rotulo: 'Renda' }, { chave: 'inquilino', rotulo: 'Inquilino' }] });
    return Promise.resolve(null);
  });
  enviarMock.mockResolvedValue({ dados: {}, mensagem: 'ok' });
});

describe('Gestão Imobiliária — Imóveis e Fracções', () => {
  it('lista os imóveis com o resumo e abre a ficha com avisos, fracções e documentos', async () => {
    ecra(<Imoveis />);
    expect(await screen.findByText('Edifício Girassol')).toBeInTheDocument();
    expect(screen.getByText('Taxa de ocupação')).toBeInTheDocument();
    expect(screen.getAllByText('75 %').length + screen.getAllByText('75').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: /Novo imóvel/ })).toBeInTheDocument();
    fireEvent.click(screen.getByText('PR0001'));
    expect(await screen.findByText('Avisos (não impedem nada)')).toBeInTheDocument();
    expect(screen.getByText(/Sem artigo matricial/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: /Fracções \(5\)/ }));
    expect(await screen.findByRole('button', { name: 'Gerar por piso' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nova fracção' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: /Documentos \(1\)/ }));
    expect(await screen.findByRole('link', { name: 'Caderneta' })).toHaveAttribute('href', 'https://exemplo.ao/m.pdf');
  });

  it('sem permissão de escrita não mostra Novo imóvel nem as acções de fracções', async () => {
    permissoes = new Set(['imo_imoveis_view']);
    ecra(<Imoveis />);
    expect(await screen.findByText('Edifício Girassol')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Novo imóvel/ })).toBeNull();
    fireEvent.click(screen.getByText('PR0001'));
    fireEvent.click(await screen.findByRole('tab', { name: /Fracções \(5\)/ }));
    await screen.findByText('Arrumos');
    expect(screen.queryByRole('button', { name: 'Gerar por piso' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Nova fracção' })).toBeNull();
  });

  it('o novo imóvel exige a designação e grava com a mensagem do servidor', async () => {
    ecra(<Imoveis />);
    fireEvent.click(await screen.findByRole('button', { name: /Novo imóvel/ }));
    const dialogo = await screen.findByRole('dialog', { name: 'Novo imóvel' });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Gravar' }));
    expect(await within(dialogo).findByText('Dê uma designação ao imóvel.')).toBeInTheDocument();
    expect(enviarMock).not.toHaveBeenCalled();
    fireEvent.change(within(dialogo).getByLabelText('Designação do imóvel'), { target: { value: 'Moradia Azul' } });
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Gravar' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalled());
    const [metodo, url, corpo] = enviarMock.mock.calls[0];
    expect([metodo, url]).toEqual(['post', '/imobiliario/imoveis']);
    expect(corpo).toMatchObject({ nome: 'Moradia Azul', tipo: 'PREDIO', papel: 'PROPRIO', estado: 'ACTIVO' });
  });

  it('gerar por piso mostra quantas fracções vai criar e bloqueia o que passa de 500', async () => {
    ecra(<Imoveis />);
    fireEvent.click(await screen.findByText('PR0001'));
    fireEvent.click(await screen.findByRole('tab', { name: /Fracções \(5\)/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Gerar por piso' }));
    // no ambiente de teste do antd todos os títulos têm o mesmo id: o diálogo acha-se pelo texto do título
    const dialogo = (await screen.findByText('Gerar fracções de PR0001')).closest('[role="dialog"]') as HTMLElement;
    expect(await within(dialogo).findByText('Vai gerar até 2 fracção(ões); as designações que já existem são ignoradas.')).toBeInTheDocument();
    fireEvent.change(within(dialogo).getByLabelText('Fracções por piso'), { target: { value: '300' } });
    expect(await within(dialogo).findByText(/São 600 fracções: acima do limite de 500/)).toBeInTheDocument();
    expect(within(dialogo).getByRole('button', { name: 'Gerar' })).toBeDisabled();
  });
});

describe('Gestão Imobiliária — Mapa de Ocupação', () => {
  it('mostra as fracções por piso com o estado em texto e a taxa de ocupação', async () => {
    ecra(<MapaOcupacao />);
    const celula = await screen.findByRole('button', { name: /Fracção 101, Arrendada/ });
    expect(celula).toHaveTextContent('Arrendada');
    expect(screen.getByRole('button', { name: /Fracção 201, Vendida/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Fracção Arrumos, Disponível/ })).toHaveTextContent('acessório');
    expect(screen.getByRole('region', { name: 'Piso 1.º andar' })).toHaveTextContent('2/2 ocupadas');
    expect(screen.getByRole('region', { name: 'Piso 2.º andar' })).toHaveTextContent('1/3 ocupadas');
    expect(screen.getByText('75 %')).toBeInTheDocument();
    fireEvent.click(screen.getByText(/Arrendada \(2\)/));
    await waitFor(() => expect(screen.queryByRole('button', { name: /Fracção 202/ })).toBeNull());
    expect(screen.getByRole('button', { name: /Fracção 102, Arrendada/ })).toBeInTheDocument();
  });

  it('abrir uma fracção mostra a ficha e o histórico; mudar o estado só com permissão', async () => {
    ecra(<MapaOcupacao />);
    fireEvent.click(await screen.findByRole('button', { name: /Fracção 101, Arrendada/ }));
    expect(await screen.findByRole('button', { name: 'Mudar estado' })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: /Histórico de estados \(1\)/ }));
    expect((await screen.findAllByText('Disponível')).length).toBeGreaterThan(0);
  });

  it('sem imo_estado_mudar a ficha não tem o botão Mudar estado', async () => {
    permissoes = new Set(['imo_ocupacao_view']);
    ecra(<MapaOcupacao />);
    fireEvent.click(await screen.findByRole('button', { name: /Fracção 101, Arrendada/ }));
    await screen.findByRole('tab', { name: /Histórico de estados/ });
    expect(screen.queryByRole('button', { name: 'Mudar estado' })).toBeNull();
  });
});

describe('Gestão Imobiliária — Modelos e Taxas', () => {
  it('as taxas aparecem por validar, com o aviso de que o ERP não afirma a conformidade legal e a taxa efectiva', async () => {
    ecra(<ModelosTaxas />);
    expect(await screen.findByText('Por validar')).toBeInTheDocument();
    expect(screen.getByText(/O ERP não confirma a sua conformidade legal/)).toBeInTheDocument();
    expect(screen.getByText('Efectivo: 15 %')).toBeInTheDocument();
    expect(screen.getByText('Transmissão (compra e venda)')).toBeInTheDocument();
    expect(screen.getByText('Detenção (pagar por ter o imóvel)')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Gravar taxas de/ }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalled());
    const [metodo, url, corpo] = enviarMock.mock.calls[0];
    expect([metodo, url]).toEqual(['put', `/imobiliario/taxas/${TAXAS.ano}`]);
    expect(corpo).toMatchObject({ ip_taxa: 25, ip_base: 60, escritura_fixo: 2000, det_agravamento_meses: 12 });
  });

  it('sem imo_taxas_editar as taxas ficam só de leitura', async () => {
    permissoes = new Set(['imo_modelos_view']);
    ecra(<ModelosTaxas />);
    await screen.findByText('Por validar');
    expect(screen.queryByRole('button', { name: /Gravar taxas de/ })).toBeNull();
  });

  it('os modelos listam-se e importam-se por omissão; o editor insere variáveis no texto', async () => {
    ecra(<ModelosTaxas />, '/?sep=modelos');
    expect((await screen.findAllByText('Recibo de renda')).length).toBeGreaterThan(0);
    fireEvent.click(screen.getByRole('button', { name: 'Importar modelos por omissão' }));
    await waitFor(() => expect(enviarMock).toHaveBeenCalledWith('post', '/imobiliario/modelos/importar-padrao'));
    fireEvent.click(screen.getByRole('button', { name: 'Novo modelo' }));
    const dialogo = await screen.findByRole('dialog', { name: 'Novo modelo' });
    fireEvent.click(await within(dialogo).findByRole('button', { name: '{{renda}}' }));
    expect((within(dialogo).getByLabelText('Texto') as HTMLTextAreaElement).value).toBe('{{renda}}');
    fireEvent.click(within(dialogo).getByRole('button', { name: 'Gravar' }));
    expect(await within(dialogo).findByText('Escolha o tipo de documento.')).toBeInTheDocument();
  });
});
