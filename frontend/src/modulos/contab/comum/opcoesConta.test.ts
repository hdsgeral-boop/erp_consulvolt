import { describe, expect, it } from 'vitest';
import { opcoesConta, SUFIXO_TOTALIZADORA } from './Seletores';

const PLANO = [
  { codigo: '31', descricao: 'Clientes', tipo: 'T' },
  { codigo: '3111', descricao: 'Clientes nacionais', tipo: 'M' },
  { codigo: '43', descricao: 'Depósitos', tipo: 'T' },
];

describe('selector de contas: totalizadoras visíveis mas bloqueadas (Versão Actual)', () => {
  it('as totalizadoras aparecem com o sufixo e desactivadas; as de movimento escolhem-se', () => {
    const o = opcoesConta(PLANO);
    expect(o.map((x) => [x.value, x.disabled])).toEqual([['31', true], ['3111', false], ['43', true]]);
    expect(o[0].label).toBe(`31 - Clientes${SUFIXO_TOTALIZADORA}`);
    expect(SUFIXO_TOTALIZADORA).toContain('totalizadora — não pode ser mapeada');
    expect(o[1].label).toBe('3111 - Clientes nacionais');
  });

  it('respeita os prefixos e, com incluirTotalizadoras, deixa escolher todas', () => {
    expect(opcoesConta(PLANO, ['31']).map((x) => x.value)).toEqual(['31', '3111']);
    expect(opcoesConta(PLANO, undefined, true).every((x) => !x.disabled)).toBe(true);
  });
});
