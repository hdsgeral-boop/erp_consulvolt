import { describe, expect, it } from 'vitest';
import {
  accoesValidacao, agruparAtrasosPorUnidade, chaveUnidade, contadorEntregaveis, foraDePrazo, limiteDaEntrega, normalizarUrl, opcoesMes, pctMeta, periodoCorrente, periodoServido,
  prazoTexto, resumirAnalise, somarMeses, textoSincronizacao, totalMultas, totalValidado,
} from './regras';
import type { AnaliseEntregaveis, Atraso, Execucao } from './tipos';

const ent = (prazo_dia: number | null, prazo_mes = 1) => ({ prazo_dia, prazo_mes });

describe('Prazo e período servido (estrutura_tarefas.js:55-128, §3.1)', () => {
  it('soma meses encurtando ao último dia do mês de chegada', () => {
    expect(somarMeses('2026-01-31', 1)).toBe('2026-02-28');
    expect(somarMeses('2026-03-31', -1)).toBe('2026-02-28');
    expect(somarMeses('2026-11-15', 2)).toBe('2027-01-15');
  });

  it('prazo «dia 15 do mês seguinte»: a entrega de 10/02/2026 serve Janeiro de 2026', () => {
    expect(periodoServido(ent(15, 2), 'MENSAL', '2026-02-10')).toEqual({ de: '2026-01-01', ate: '2026-01-31' });
    expect(periodoServido(ent(15, 1), 'MENSAL', '2026-02-10')).toEqual({ de: '2026-02-01', ate: '2026-02-28' });
  });

  it('trimestre e ano civis; o mês M do período recua M−1 meses', () => {
    expect(periodoServido(ent(10, 1), 'TRIMESTRAL', '2026-05-20')).toEqual({ de: '2026-04-01', ate: '2026-06-30' });
    expect(periodoServido(ent(10, 4), 'TRIMESTRAL', '2026-07-05')).toEqual({ de: '2026-04-01', ate: '2026-06-30' });
    expect(periodoServido(ent(31, 13), 'ANUAL', '2027-01-20')).toEqual({ de: '2026-01-01', ate: '2026-12-31' });
  });

  it('limite da entrega: dia D do mês início + (M − 1); dia 31 num mês curto passa para o último dia', () => {
    expect(limiteDaEntrega(ent(15, 2), '2026-01-01')).toBe('2026-02-15');
    expect(limiteDaEntrega(ent(31, 2), '2026-01-01')).toBe('2026-02-28');
    expect(limiteDaEntrega(ent(5, 4), '2026-04-01')).toBe('2026-07-05');
    expect(limiteDaEntrega(ent(null), '2026-01-01')).toBeNull();
  });

  it('fora de prazo compara o limite do período servido com a data', () => {
    expect(foraDePrazo(ent(15, 2), 'MENSAL', '2026-02-15')).toBe(false);
    expect(foraDePrazo(ent(15, 2), 'MENSAL', '2026-02-16')).toBe(true);
    expect(foraDePrazo(ent(null), 'MENSAL', '2030-01-01')).toBe(false);
  });

  it('período corrente = o mais recente cujo limite já passou', () => {
    // hoje 10/03: o limite de Fevereiro (15/03) ainda não passou; o de Janeiro (15/02) já → serve Janeiro
    expect(periodoCorrente(ent(15, 2), 'MENSAL', '2026-03-10')).toEqual({ de: '2026-01-01', ate: '2026-01-31', limite: '2026-02-15' });
    expect(periodoCorrente(ent(15, 2), 'MENSAL', '2026-03-16')).toEqual({ de: '2026-02-01', ate: '2026-02-28', limite: '2026-03-15' });
    expect(periodoCorrente(ent(null), 'MENSAL', '2026-03-16')).toEqual({ de: '2026-03-01', ate: '2026-03-31', limite: null });
  });

  it('rótulos do prazo e opções «de que mês» até n+1', () => {
    expect(prazoTexto(ent(15, 2), 'MENSAL')).toBe('dia 15 do mês seguinte');
    expect(prazoTexto(ent(5, 1), 'MENSAL')).toBe('dia 5 do próprio mês');
    expect(prazoTexto(ent(5, 2), 'TRIMESTRAL')).toBe('dia 5 do 2.º mês do período');
    expect(prazoTexto(ent(5, 4), 'TRIMESTRAL')).toBe('dia 5 do mês seguinte ao período');
    expect(prazoTexto(ent(null), 'MENSAL')).toBeNull();
    expect(opcoesMes('MENSAL').map((o) => o.value)).toEqual([1, 2]);
    expect(opcoesMes('TRIMESTRAL').map((o) => o.value)).toEqual([1, 2, 3, 4]);
    expect(opcoesMes('ANUAL')).toHaveLength(13);
  });
});

describe('Unidades de medida e URLs', () => {
  it('compara sem acentos, minúsculas e com sinónimos', () => {
    expect(chaveUnidade('m²')).toBe(chaveUnidade('M2'));
    expect(chaveUnidade('Unidades')).toBe(chaveUnidade('un'));
    expect(chaveUnidade('Horas')).toBe(chaveUnidade('h'));
    expect(chaveUnidade('Tonelada')).toBe(chaveUnidade('t'));
    expect(chaveUnidade('%')).toBe(chaveUnidade('pct'));
    expect(chaveUnidade('Relatórios')).toBe('relatorios');
    expect(chaveUnidade('m³')).not.toBe(chaveUnidade('m²'));
  });

  it('só http(s): acrescenta https:// sem esquema; rejeita outros esquemas e credenciais', () => {
    expect(normalizarUrl('exemplo.invalid/doc.pdf')).toBe('https://exemplo.invalid/doc.pdf');
    expect(normalizarUrl('http://exemplo.invalid')).toBe('http://exemplo.invalid');
    expect(normalizarUrl('ftp://exemplo.invalid')).toBeNull();
    expect(normalizarUrl('javascript:alert(1)')).toBeNull();
    expect(normalizarUrl('https://user:pass@exemplo.invalid')).toBeNull();
    expect(normalizarUrl('  ')).toBeNull();
  });
});

describe('Estados, agrupamentos e contadores', () => {
  const x = (d: Partial<Execucao>): Execucao => ({
    id: 1, entregavel_id: 1, tarefa_unidade_id: 1, unidade_organica_id: 1, colaborador_id: 1, quantidade: '2', data: '2026-01-10', observacoes: null, url_evidencia: null,
    registado_por: 'x', origem: 'MANUAL', validacao: 'PENDENTE', validacao_motivo: null, validada_por: null, validada_em: null, validada_como: null, periodo_produtividade_id: null, pode_validar: true, ...d,
  });

  it('botões de validação por estado; nada depois de convertida ou sem direito', () => {
    expect(accoesValidacao(x({ validacao: 'PENDENTE' }))).toEqual({ validar: true, devolver: true, recusar: true, repor: false });
    expect(accoesValidacao(x({ validacao: 'VALIDADA' }))).toEqual({ validar: false, devolver: true, recusar: true, repor: true });
    expect(accoesValidacao(x({ validacao: 'DEVOLVIDA' }))).toEqual({ validar: true, devolver: false, recusar: true, repor: true });
    expect(accoesValidacao(x({ validacao: 'VALIDADA', periodo_produtividade_id: 9 }))).toEqual({ validar: false, devolver: false, recusar: false, repor: false });
    expect(accoesValidacao(x({ pode_validar: false }))).toEqual({ validar: false, devolver: false, recusar: false, repor: false });
  });

  it('total validado só soma as validadas; percentagem da meta limitada a 999', () => {
    expect(totalValidado([x({ validacao: 'VALIDADA', quantidade: '1.5' }), x({ validacao: 'PENDENTE', quantidade: '10' }), x({ validacao: 'VALIDADA', quantidade: 2 })])).toBe(3.5);
    expect(pctMeta('12', '10')).toBe(120);
    expect(pctMeta(5000, 1)).toBe(999);
    expect(pctMeta(5, null)).toBeNull();
  });

  it('atrasos agrupados por unidade com a multa somada', () => {
    const a = (unidade: string, multa: string | null): Atraso => ({
      entregavel: { id: 1, nome: 'E', unidade: 'un', prazo_dia: 10, prazo_mes: 1 }, tarefa: { id: 1, nome: 'T', codigo: null }, unidade: { id: 1, nome: unidade }, periodo_de: '2026-01-01', periodo_ate: '2026-01-31',
      limite: '2026-01-10', dias_atraso: 3, realizado: 0, meta: 5, falta: 5, multa_valor: multa, multa_descricao: null, impedimento: null, dono: null,
    });
    const grupos = agruparAtrasosPorUnidade([a('Vendas', '100.50'), a('Finanças', null), a('Vendas', '20')]);
    expect(grupos.map((g) => [g.unidade, g.itens.length, g.multa])).toEqual([['Finanças', 1, 0], ['Vendas', 2, 120.5]]);
    expect(totalMultas(grupos.flatMap((g) => g.itens))).toBe(120.5);
  });

  it('resumo da análise da ponte para a produtividade', () => {
    const analise: AnaliseEntregaveis = {
      periodo: { id: 1, mes: '2026-01', data_inicio: '2026-01-01', data_fim: '2026-01-31', estado: 'ABERTO' },
      validas: [{ entregavel_id: 1, entregavel: 'E', tarefa_unidade_id: 1, colaborador_id: 1, colaborador_nome: 'Ana', item_produtividade_id: 3, item_codigo: 'E1', quantidade: 3, registos: 2, preco: '100',
        origem_chave: 'ORG|e1|c1|i3|p1', registo_existente_id: null, quantidade_total: 3, valor: '300', valor_total: '300', ja_elegivel: false }],
      excluidas: [
        { entregavel_id: 1, entregavel: 'E', colaborador_id: 2, colaborador_nome: 'Rui', quantidade: 1, registos: 1, execucoes: [5], codigo: 'NAO_VALIDADA', motivo: 'por validar' },
        { entregavel_id: 1, entregavel: 'E', colaborador_id: 3, colaborador_nome: 'Zé', quantidade: 1, registos: 1, execucoes: [6], codigo: 'NAO_VALIDADA', motivo: 'por validar' },
        { entregavel_id: 2, entregavel: 'F', colaborador_id: 3, colaborador_nome: 'Zé', quantidade: 1, registos: 1, execucoes: [7], codigo: 'SEM_ITEM', motivo: 'sem item' },
      ],
      erros: [{ entregavel_id: 2, entregavel: 'F', colaborador_id: 4, colaborador_nome: 'Lu', quantidade: 1, mensagem: 'inactivo' }],
      por_elegibilizar: [{ colaborador_id: 1, colaborador_nome: 'Ana', item_produtividade_id: 3, item_codigo: 'E1', preco: '100' }],
      total: '300.00',
      convertidas: 0,
    };
    expect(resumirAnalise(analise)).toEqual({ validas: 1, excluidas: 3, erros: 1, porMotivo: [{ codigo: 'NAO_VALIDADA', motivo: 'por validar', n: 2 }, { codigo: 'SEM_ITEM', motivo: 'sem item', n: 1 }], total: 300, elegiveis: 1 });
    expect(resumirAnalise(undefined).total).toBe(0);
  });

  it('contador do separador «Entregáveis» e mensagem da sincronização com o CRM', () => {
    expect(contadorEntregaveis({ recusadas: 1, devolvidas: 2 })).toBe(3);
    expect(contadorEntregaveis(undefined)).toBe(0);
    expect(textoSincronizacao({ criadas: 2, actualizadas: 0, removidas: 1, presas: [], sem_colaborador: 0 })).toBe('Actualizado: 2 nova(s), 1 retirada(s).');
    expect(textoSincronizacao({ criadas: 0, actualizadas: 0, removidas: 0, presas: [], sem_colaborador: 0 })).toBe('Já estava a par do CRM — nada a mudar.');
  });
});
