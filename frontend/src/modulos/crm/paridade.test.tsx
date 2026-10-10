import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { colunasContas, textoEmAtraso, textoProximaActividade } from './comum/colunasContas';
import { linhasMotivosPerda, previsaoPorResponsavel, rotuloMesPrevisao, totaisPrevisao, type IndicadoresCRM, type PrevisaoCRM } from './comum/previsao';
import type { ContaCRM } from './comum/tipos';
import { LegendaCoresPipeline } from './Pipeline';
import { SEPARADORES_CONFIG_CRM, SUBTITULO_CONFIG_CRM, linhasParaLista, rotuloTipoEtapa, textosActividadesEtapa, textosPassosSequencia } from './ConfigCRM';
import { OPCOES_DIAS_AGENDA, OPCOES_RESPONSAVEL_AGENDA, SUBTITULO_AGENDA_CRM, responsavelDaAgenda } from './Agenda';
import { OPCOES_TIPO_CAMPANHA, SUBTITULO_CAMPANHAS_CRM, opcoesEtapaCampanha } from './Campanhas';

const conta = (x: Partial<ContaCRM> = {}): ContaCRM => ({
  id: 1, tipo: 'CLIENTE', terceiro_id: 3, nome: 'Cliente Fictício', nif: '500000000', email: null, telefone: null, morada: null,
  origem: 'REFERENCIA', origem_original: 'Referência', responsavel: null, setor: null, website: null, notas: null, ...x,
});

describe('CRM — paridade com o antigo', () => {
  it('9.1.1 subtítulo do Pipeline com a legenda das cores', () => {
    render(<LegendaCoresPipeline />);
    expect(screen.getByText('Vermelho')).toBeTruthy();
    expect(document.body.textContent).toContain('Arraste os cartões entre etapas. Vermelho: sem actividade agendada ou estagnado; âmbar: actividade em atraso ou fecho ultrapassado.');
  });

  it('9.3.1 colunas das contas na ordem do antigo', () => {
    expect(colunasContas.map((c) => c.title)).toEqual(['Conta', 'Contacto', 'Origem', 'Oportunidades abertas', 'Valor em aberto', 'Facturado 12 meses', 'Em atraso', 'Próxima actividade']);
  });

  it('9.3.1 textos de «Em atraso» e «Próxima actividade»', () => {
    expect(textoEmAtraso(conta({ financeiro: { em_aberto: '100', em_atraso: '1500.50', max_dias_atraso: 12, n_atrasadas: 1 } }))).toMatch(/^1.500,50 · 12 d$/);
    expect(textoEmAtraso(conta({ financeiro: { em_aberto: '0', em_atraso: '0.00', max_dias_atraso: 0, n_atrasadas: 0 } }))).toBe('');
    expect(textoProximaActividade(conta({ proxima_atividade: { id: 1, tipo: 'CHAMADA', titulo: 'Ligar', data_prevista: '2026-10-09' } }))).toBe('Ligar · 09/10/2026');
    expect(textoProximaActividade(conta())).toBe('');
  });

  it('9.4.1 totais, responsáveis e motivos de perda da previsão', () => {
    const p: PrevisaoCRM = {
      por_mes: [
        { mes: '2026-10', bruto: '1000.00', ponderado: '250.00', n: 2, compromisso: '0.00' },
        { mes: '2026-11', bruto: '500.00', ponderado: '400.00', n: 1, compromisso: '500.00' },
      ],
      atrasadas: { bruto: '0', ponderado: '0', n: 0 },
      por_responsavel: [{ responsavel: 'A', bruto: '1', ponderado: '10.00', n: 1 }, { responsavel: 'B', bruto: '1', ponderado: '90.00', n: 1 }],
      abertas: 3,
    };
    expect(totaisPrevisao(p)).toEqual({ n: 3, bruto: '1500.00', ponderado: '650.00', compromisso: '500.00' });
    expect(previsaoPorResponsavel(p).map((r) => r.responsavel)).toEqual(['B', 'A']);
    expect(rotuloMesPrevisao('2026-10')).toBe('Out/26');
    const k = { valor_perdido: '400.00', motivos: [{ motivo: 'Preço', n: 1, valor: '100.00' }, { motivo: 'Prazo', n: 2, valor: '300.00' }] } as IndicadoresCRM;
    expect(linhasMotivosPerda(k)).toEqual([
      { motivo: 'Prazo', n: 2, valor: '300.00', percentagem: 75 },
      { motivo: 'Preço', n: 1, valor: '100.00', percentagem: 25 },
    ]);
  });

  it('9.7 configuração: subtítulo, separadores e tabela das etapas como o antigo', () => {
    expect(SUBTITULO_CONFIG_CRM).toBe('Pipelines e etapas (probabilidade, dias de estagnação e actividades automáticas), modelos de email, sequências automáticas, motivos de perda e origens das leads.');
    expect(SEPARADORES_CONFIG_CRM.map((x) => x.label)).toEqual(['Pipelines e etapas', 'Modelos de email', 'Sequências', 'Motivos e origens']);
    expect(['ABERTA', 'GANHA', 'PERDIDA'].map(rotuloTipoEtapa)).toEqual(['Em aberto', 'Ganha', 'Perdida']);
    const tipos = { CHAMADA: 'Telefonar', EMAIL: 'Enviar email' };
    expect(textosActividadesEtapa({ tarefas: [{ tipo: 'CHAMADA', titulo: 'Primeiro contacto com o cliente', dias: 1 }, { tipo: 'EMAIL', titulo: 'Apresentação', dias: 0 }] }, tipos)).toEqual([
      'Telefonar: Primeiro contacto com o cliente (+1d)',
      'Enviar email: Apresentação (+0d)',
    ]);
    expect(textosPassosSequencia([{ dias: 0, modelo_email_crm_id: 1 }, { dias: 3, modelo_email_crm_id: 9 }], [{ id: 1, nome: 'Seguimento' }])).toEqual(['+0d: Seguimento', '+3d: ?']);
    expect(linhasParaLista('Preço\n\n  Prazo  \n')).toEqual(['Preço', 'Prazo']);
    expect(linhasParaLista(['A', ' '])).toEqual(['A']);
  });

  it('9.5 agenda: subtítulo e filtros «Responsável» e «Próximos» como o antigo', () => {
    expect(SUBTITULO_AGENDA_CRM).toBe('Chamadas, emails, reuniões e tarefas — incluindo as criadas automaticamente quando um negócio muda de etapa.');
    expect(OPCOES_RESPONSAVEL_AGENDA.map((o) => o.label)).toEqual(['As minhas', 'Minha equipa', 'Todos']);
    expect(OPCOES_DIAS_AGENDA.map((o) => o.label)).toEqual(['7 dias', '14 dias', '30 dias']);
    expect(responsavelDaAgenda('__eu', 'ana')).toBe('ana');
    expect(responsavelDaAgenda('__equipa', 'ana')).toBe('ana');
    expect(responsavelDaAgenda('', 'ana')).toBeUndefined();
    expect(responsavelDaAgenda('rui', 'ana')).toBe('rui');
  });

  it('9.6 campanhas: subtítulo e opções dos filtros como o antigo', () => {
    expect(SUBTITULO_CAMPANHAS_CRM).toBe('Mensagem personalizada para cada destinatário a partir de um modelo. Abre-se no seu programa de email (uma a uma ou em lote) ou exporta-se para uma ferramenta de email marketing. Fica registado no histórico de cada conta.');
    expect(OPCOES_TIPO_CAMPANHA.map((o) => o.label)).toEqual(['Todas', 'Prospects', 'Clientes']);
    const ops = opcoesEtapaCampanha([{ id: 2, nome: 'Vendas', etapas: [{ id: 'lead', nome: 'Lead', tipo: 'ABERTA' }, { id: 'ganha', nome: 'Ganha', tipo: 'GANHA' }] }]);
    expect(ops[0]).toEqual({ value: '', label: 'Qualquer / sem oportunidade' });
    expect(ops[1]).toEqual({ label: 'Vendas', title: 'Vendas', options: [{ value: '2|lead', label: 'Lead' }] });
  });
});
