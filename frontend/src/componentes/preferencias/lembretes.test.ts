import { describe, expect, it } from 'vitest';
import { alternarFeitoNaLista, alternarFixadoNaLista, novoLembrete, ordenarLembretes, removerDaLista, type Lembrete } from './lembretes';

const l = (id: string, fixado = false, criado_em = '2026-01-01T00:00:00.000Z'): Lembrete => ({ id, texto: id, feito: false, fixado, criado_em });

describe('lembretes — caixa «Lembretes» do Início', () => {
  it('novoLembrete: aparado, por fazer, não fixado', () => {
    const n = novoLembrete('  Ligar ao fornecedor  ');
    expect(n).toMatchObject({ texto: 'Ligar ao fornecedor', feito: false, fixado: false });
    expect(n.id).toBeTruthy();
  });

  it('ordenarLembretes: fixados primeiro; dentro de cada grupo, os mais recentes primeiro', () => {
    const lista = [l('antigo', false, '2026-01-01'), l('fixado', true, '2026-01-02'), l('recente', false, '2026-01-03')];
    expect(ordenarLembretes(lista).map((x) => x.id)).toEqual(['fixado', 'recente', 'antigo']);
  });

  it('alternarFeitoNaLista / alternarFixadoNaLista: só muda o lembrete indicado', () => {
    const lista = [l('a'), l('b')];
    expect(alternarFeitoNaLista(lista, 'a').find((x) => x.id === 'a')?.feito).toBe(true);
    expect(alternarFeitoNaLista(lista, 'a').find((x) => x.id === 'b')?.feito).toBe(false);
    expect(alternarFixadoNaLista(lista, 'b').find((x) => x.id === 'b')?.fixado).toBe(true);
  });

  it('removerDaLista: tira só o indicado', () => {
    const lista = [l('a'), l('b')];
    expect(removerDaLista(lista, 'a')).toEqual([l('b')]);
  });
});
