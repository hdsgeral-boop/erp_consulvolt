import { describe, expect, it } from 'vitest';
import { temIconeFa } from '@/componentes/fluxos/IconeFa';
import { estadoConclusao, legendaConclusao, temErros, textosConclusao } from './conclusao';
import { origemDemonstracoes } from './DemonstracoesRC';

const todas = () => true;
const so = (...permitidas: string[]) => (...c: string[]) => c.some((x) => permitidas.includes(x));

describe('Relatório e Contas — Concluir / Reabrir', () => {
  it('rascunho: «Concluir» visível mesmo com o exercício em aberto (como o legado), mas indisponível', () => {
    const e = estadoConclusao({ estado: 'RASCUNHO' }, false, todas);
    expect(e).toMatchObject({ concluido: false, podeEditar: true, mostrarConcluir: true, concluirDisponivel: false, podeReabrir: false, legenda: '' });
    expect(estadoConclusao({ estado: 'RASCUNHO' }, true, todas).concluirDisponivel).toBe(true);
  });

  it('concluído: edição e «Concluir» desaparecem, «Reabrir» só com permissão; legenda com data e utilizador', () => {
    const reg = { estado: 'APROVADO', concluido_em: '2026-10-08T09:30:00+01:00', concluido_por: 'contabilista.teste' };
    expect(estadoConclusao(reg, true, todas)).toMatchObject({
      concluido: true,
      podeEditar: false,
      mostrarConcluir: false,
      podeReabrir: true,
      legenda: 'concluído em 2026-10-08 por contabilista.teste',
    });
    expect(estadoConclusao(reg, true, so('rc_editar', 'rc_concluir')).podeReabrir).toBe(false);
  });

  it('sem permissões: nada de editar nem concluir', () => {
    expect(estadoConclusao({ estado: 'RASCUNHO' }, true, so('relatorio_contas_view'))).toMatchObject({ podeEditar: false, mostrarConcluir: false });
    expect(estadoConclusao(undefined, true, so('rc_concluir')).mostrarConcluir).toBe(true);
  });

  it('legenda sem utilizador e sem data', () => {
    expect(legendaConclusao('2026-10-08T00:00:00Z', null)).toBe('concluído em 2026-10-08');
    expect(legendaConclusao(null, 'x')).toBe('');
  });

  it('erros nos alertas pedem confirmação extra; textos do legado', () => {
    expect(temErros([{ tipo: 'info' }, { tipo: 'erro' }])).toBe(true);
    expect(temErros([{ tipo: 'aviso' }])).toBe(false);
    expect(temErros(undefined)).toBe(false);
    expect(textosConclusao.exercicioAberto(2026)).toContain('O exercício de 2026 ainda não está encerrado');
    expect(textosConclusao.confirmarTitulo(2025)).toBe('Concluir o Relatório e Contas de 2025?');
  });

  it('ícones do legado (fa-check, fa-lock-open) existem', () => {
    expect(temIconeFa('check')).toBe(true);
    expect(temIconeFa('lock-open')).toBe(true);
  });
});

describe('Demonstrações de um relatório concluído (legado relatorio_contas.js:944-945)', () => {
  it('concluído com fotografia → números fixados; concluído antigo → actuais com aviso; rascunho → actuais', () => {
    expect(origemDemonstracoes(true, { dr: {} as never })).toBe('fixadas');
    expect(origemDemonstracoes(true, undefined)).toBe('actuais_concluido');
    expect(origemDemonstracoes(true, [] as never)).toBe('actuais_concluido');
    expect(origemDemonstracoes(false, { dr: {} as never })).toBe('actuais');
  }, 60_000);
});
