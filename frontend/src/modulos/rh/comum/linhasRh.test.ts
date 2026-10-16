import { describe, expect, it, vi } from 'vitest';
import dayjs from 'dayjs';
import { errosLinha, converterTexto, type CampoFormulario } from '@/componentes/formularios';

vi.mock('@/api/cliente', () => ({ obter: vi.fn(), enviar: vi.fn(), obterPagina: vi.fn(), descarregar: vi.fn(), http: { post: vi.fn() } }));
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ pode: () => true, empresa: null, utilizador: null, menu: [] }) }));

import { dataDe, horaDe, horaTexto, opcoesColaboradores, semVazios, simNaoTexto } from './linhasRh';
import { camposRegistoEfectividade, camposAusencia, dadosAusenciaNova, dadosRegistoEfectividade } from '../Assiduidade';
import { camposFerias, dadosFerias } from '../Ferias';
import { camposContrato, dadosContrato } from '../Contratos';
import { camposLancamento, dadosLancamento } from '../Calcular';
import { camposIban, pedidoIban } from '../Bancario';
import { camposItemAvaliacao, dadosItemEspecifico, indicadoresAvaliacao } from '../Avaliacao';
import { camposItemProdutividade, dadosRegistoProdutividade } from '../Produtividade';
import { CAMPOS_LINHAS_INFOTIPO } from '../Infotipos';
import type { Colaborador } from '../api';

const COLABS = [{ value: 1, label: 'Ana Silva (500000001)' }, { value: 2, label: 'Bruno Costa (500000002)' }];
const campo = (cs: CampoFormulario[], nome: string) => cs.find((c) => c.nome === nome)!;

describe('peças comuns das vistas Grade | Linhas do RH', () => {
  it('horas: «8:00», «8h30», «0830» e a fracção do dia do Excel; inválidas por reconhecer', () => {
    expect(horaTexto('8:00')).toBe('08:00');
    expect(horaTexto('8h30')).toBe('08:30');
    expect(horaTexto('0830')).toBe('08:30');
    expect(horaTexto('0,375')).toBe('09:00');
    expect(horaTexto('25:00')).toBeUndefined();
    expect(horaTexto('')).toBeNull();
    expect(horaDe(dayjs('2026-01-01T17:45:00'))).toBe('17:45');
    expect(horaDe('xx')).toBeNull();
  });

  it('datas da grade (dayjs) e das linhas (texto) dão o mesmo valor; sim/não colado', () => {
    expect(dataDe(dayjs('2026-07-16'))).toBe('2026-07-16');
    expect(dataDe('2026-07-16')).toBe('2026-07-16');
    expect(dataDe(null)).toBeNull();
    expect(simNaoTexto('Sim')).toBe(true);
    expect(simNaoTexto('não')).toBe(false);
    expect(simNaoTexto('talvez')).toBeUndefined();
    expect(semVazios({ a: 1, b: null, c: '', d: 0 })).toEqual({ a: 1, d: 0 });
  });

  it('colaboradores: «Nome (NIF)», só activos quando pedido; a colagem reconhece o NIF', () => {
    const lista = [
      { id: 1, nome_completo: 'Ana Silva', nif: '500000001', estado: 'ACTIVO' },
      { id: 2, nome_completo: 'Bruno Costa', nif: '500000002', estado: 'INACTIVO' },
    ] as Colaborador[];
    expect(opcoesColaboradores(lista)).toHaveLength(2);
    const activos = opcoesColaboradores(lista, true);
    expect(activos).toEqual([{ value: 1, label: 'Ana Silva (500000001)' }]);
    expect(converterTexto({ nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', opcoes: activos }, '500000001')).toEqual({ ok: true, valor: 1 });
  });
});

describe('Efectividade › «Registo de efectividade» e «Registar ausência»', () => {
  it('o mesmo corpo na grade (TimePicker em texto, data dayjs) e nas linhas; regras do intervalo e das horas', () => {
    const grade = dadosRegistoEfectividade({ colaborador_id: 1, data: dayjs('2026-03-02'), entrada: '08:00', saida: '17:00', observacoes: '' });
    const linha = dadosRegistoEfectividade({ colaborador_id: 1, data: '2026-03-02', entrada: '08:00', saida: '17:00', observacoes: '', autorizado_extra: false });
    expect(linha).toEqual(grade);
    expect(grade).toMatchObject({ data: '2026-03-02', entrada: '08:00', saida: '17:00', saida_intervalo: null, horas: null, observacoes: null, autorizado_extra: null });
    const cs = camposRegistoEfectividade(COLABS);
    expect(errosLinha(cs, { colaborador_id: 1, data: '2026-03-02', saida_intervalo: '12:00' })).toMatch(/duas picagens do intervalo.*entrada\/saída ou as horas/);
    expect(errosLinha(cs, { colaborador_id: 1, data: '2026-03-02', horas: 7.5 })).toBeNull();
    expect(campo(cs, 'saida_intervalo')).toMatchObject({ emLinhas: 'opcional' });
  });

  it('ausência: período da grade (intervalo) ou início/fim das linhas; horas exigidas nos tipos em horas', () => {
    const grade = dadosAusenciaNova({ colaborador_id: 2, tipo: 'NOJO', periodo: [dayjs('2026-03-02'), dayjs('2026-03-04')], motivo: 'Falecimento', documento_url: '' });
    const linha = dadosAusenciaNova({ colaborador_id: 2, tipo: 'NOJO', data_inicio: '2026-03-02', data_fim: '2026-03-04', motivo: 'Falecimento', documento_url: '', horas: null });
    expect(linha).toEqual(grade);
    expect(grade).toMatchObject({ data_inicio: '2026-03-02', data_fim: '2026-03-04', documento_url: null, horas: null });
    const cs = camposAusencia(COLABS, { CONSULTA: { nome: 'Consulta médica', artigo: '206', unidade: 'HORAS', remunerada: 'SIM' } });
    expect(errosLinha(cs, { colaborador_id: 1, data_inicio: '2026-03-05', data_fim: '2026-03-04', tipo: 'CONSULTA', motivo: 'x' })).toMatch(/fim é anterior.*Indique as horas/);
  });
});

describe('Férias › «Marcar férias»', () => {
  it('grade e linhas enviam o mesmo período; estado por omissão PLANEADO', () => {
    expect(dadosFerias({ colaborador_id: 1, data_inicio: '2026-08-03', data_fim: '2026-08-14', direito: null, observacoes: '' }))
      .toEqual(dadosFerias({ colaborador_id: 1, periodo: [dayjs('2026-08-03'), dayjs('2026-08-14')], estado: 'PLANEADO' }));
    expect(dadosFerias({ colaborador_id: 1, data_inicio: '2026-08-03', data_fim: '2026-08-14' }).estado).toBe('PLANEADO');
    expect(errosLinha(camposFerias(COLABS), { colaborador_id: 1, data_inicio: '2026-08-14', data_fim: '2026-08-03' })).toBe('O fim é anterior ao início.');
  });
});

describe('Contratos › «Novo contrato»', () => {
  const rubricas = [{ id: 7, nome: 'Salário base' }, { id: 8, nome: 'Subsídio de alimentação' }];

  it('uma coluna de valor mensal por rubrica (legado), dias/horas/estado/moeda por omissão', () => {
    const cs = camposContrato(COLABS, rubricas);
    expect(cs.map((c) => c.rotulo)).toEqual(['Colaborador', 'Salário base (mensal)', 'Subsídio de alimentação (mensal)', 'Dias/mês', 'Horas/dia', 'Início', 'Fim', 'Estado', 'Moeda']);
    expect(campo(cs, 'dias_contrato_mes').omissao).toBe(22);
    expect(errosLinha(cs, { colaborador_id: 1, dias_contrato_mes: 22, data_inicio: '2026-01-01' })).toMatch(/pelo menos uma rubrica/);
  });

  it('as colunas das rubricas passam a remunerações; a grade mantém a lista, o horário e a minuta', () => {
    const linha = dadosContrato({ colaborador_id: 1, rub_7: 300000, rub_8: null, dias_contrato_mes: 22, horas_por_dia: 8, data_inicio: '2026-01-01', data_fim: null, estado: 'ACTIVO', codigo_moeda: 'aoa' });
    expect(linha).toMatchObject({ remuneracoes: [{ infotipo_salarial_id: 7, valor_mes: 300000 }], data_inicio: '2026-01-01', data_fim: null, codigo_moeda: 'AOA', horario: null, produtividade: [], motivo_termo: null });
    expect(linha).not.toHaveProperty('rub_7');
    const grade = dadosContrato({
      colaborador_id: 1, data_inicio: dayjs('2026-01-01'), data_fim: dayjs('2026-12-31'), dias_contrato_mes: 22, horas_por_dia: 8, estado: 'ACTIVO', codigo_moeda: 'AOA',
      remuneracoes: [{ infotipo_salarial_id: 7, valor_mes: 250000 }], produtividade: [{ item_id: 3 }], motivo_termo: ' Obra ', usar_horario: false, horario: { periodos: 1 },
    });
    expect(grade).toMatchObject({ data_fim: '2026-12-31', motivo_termo: 'Obra', produtividade: [{ item_id: 3, preco_unitario: null }], remuneracoes: [{ infotipo_salarial_id: 7, valor_mes: 250000 }] });
    expect(grade).not.toHaveProperty('usar_horario');
  });
});

describe('Calcular › «Novo lançamento»', () => {
  it('rubricas por hora levam as horas; as outras exigem o valor', () => {
    const porHoras = (r: unknown) => r === 9;
    const cs = camposLancamento(COLABS, [{ value: 5, label: 'Base' }, { value: 9, label: 'Horas extra' }], porHoras);
    expect(errosLinha(cs, { colaborador_id: 1, infotipo_salarial_id: 5 })).toBe('Indique o valor.');
    expect(errosLinha(cs, { colaborador_id: 1, infotipo_salarial_id: 9 })).toBe('Indique as horas (rubrica por hora).');
    expect(dadosLancamento({ colaborador_id: 1, infotipo_salarial_id: 9, horas: 4, valor: null }, true)).toEqual({ colaborador_id: 1, infotipo_salarial_id: 9, valor: null, dias_trabalhados: null, horas: 4 });
    expect(dadosLancamento({ colaborador_id: 1, infotipo_salarial_id: 5, valor: 1000, horas: 4 }, false).horas).toBeNull();
  });
});

describe('Bancário, Infotipos, Avaliação e Produtividade', () => {
  it('«Adicionar IBAN»: PUT por colaborador com o IBAN sem espaços; IBAN inválido assinalado na linha', () => {
    expect(pedidoIban({ colaborador_id: 4, banco_id: 2, iban: 'AO06 0040 0000 1234 5678 1012 3' })).toEqual({ url: '/rh/colaboradores/4/coordenada-bancaria', dados: { banco_id: 2, iban: 'AO06004000001234567810123' } });
    expect(errosLinha(camposIban(COLABS, [{ value: 2, label: 'BFA' }]), { colaborador_id: 4, banco_id: 2, iban: 'AO06123' })).toMatch(/IBAN inválido/);
  });

  it('«Novo Infotipo»: tipo, IRT e INSS por omissão como na grade', () => {
    expect(campo(CAMPOS_LINHAS_INFOTIPO, 'tipo').omissao).toBe('VENCIMENTO');
    expect(campo(CAMPOS_LINHAS_INFOTIPO, 'irt').omissao).toBe('true');
    expect(campo(CAMPOS_LINHAS_INFOTIPO, 'sujeito_inss').omissao).toBe(true);
  });

  it('itens de avaliação: o colaborador é exigido nos específicos; o item específico fixa o âmbito e o colaborador', () => {
    const cs = camposItemAvaliacao(COLABS);
    expect(errosLinha(cs, { tipo: 'CRITERIO', ambito: 'ESPECIFICO', nome: 'Pontualidade' })).toMatch(/Falta o colaborador/);
    expect(camposItemAvaliacao().some((c) => c.nome === 'ambito')).toBe(false);
    expect(dadosItemEspecifico({ tipo: 'OBJECTIVO', nome: 'Vendas', peso: null, meta: 100 }, 12)).toEqual({ tipo: 'OBJECTIVO', nome: 'Vendas', meta: 100, ambito: 'ESPECIFICO', colaborador_id: 12 });
  });

  it('indicadores da avaliação (antigo): concluídas, rascunhos, por avaliar, média e distribuição', () => {
    const i = indicadoresAvaliacao([
      { estado: 'CONCLUIDA', pontuacao: '4.6', classificacao: 'Excelente' },
      { estado: 'CONCLUIDA', pontuacao: '3.4', classificacao: 'Bom' },
      { estado: 'RASCUNHO', pontuacao: null, classificacao: null },
      null,
    ]);
    expect(i).toMatchObject({ colaboradores: 4, concluidas: 2, rascunhos: 1, porAvaliar: 1 });
    expect(i.media).toBeCloseTo(4);
    expect(i.distribuicao.filter((d) => d.n)).toEqual([{ c: 'Excelente', n: 1 }, { c: 'Bom', n: 1 }]);
  });

  it('produtividade: prémio só no objectivo; o registo envia data e observações vazias como null', () => {
    const cs = camposItemProdutividade([{ value: 1, label: 'Subsídio de produtividade' }]);
    expect(errosLinha(cs, { codigo: 'P1', descricao: 'Peças', metrica: 'QUANTIDADE', base_calculo: 'PREMIO', preco_unitario: 10, infotipo_salarial_id: 1 })).toMatch(/prémio só se aplica/);
    expect(dadosRegistoProdutividade({ colaborador_id: 1, item_produtividade_id: 2, quantidade: 5, data: dayjs('2026-03-10'), observacoes: '' }))
      .toEqual({ colaborador_id: 1, item_produtividade_id: 2, quantidade: 5, data: '2026-03-10', observacoes: null });
  });
});
