import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import { corpoUnidade } from './UnidadesNegocio';

describe('unidades de negócio (M-14)', () => {
  it('converte datas e textos vazios', () => {
    expect(corpoUnidade({ codigo: 'UN1', nome: 'Sede', email: '', valido_de: dayjs('2026-01-01'), valido_ate: null }))
      .toMatchObject({ codigo: 'UN1', nome: 'Sede', email: null, valido_de: '2026-01-01', valido_ate: null });
  });
});
