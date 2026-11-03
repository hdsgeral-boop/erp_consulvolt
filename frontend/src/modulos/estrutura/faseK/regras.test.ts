import { describe, expect, it } from 'vitest';
import {
  accoesMulta, contar, etiquetaEmNomeDe, normalizarMinhasDelegacoes, podeImportar, podeRevogar, situacaoDelegacao, textoAmbito, textoProposta, textoResumoImportacao,
  totaisMultas, validarFicheiroImportacao, validarPeriodoDelegacao, validarTectoMulta,
} from './regras';
import type { DelegacaoChefia, RelatorioImportacaoEntregas } from './tipos';

const rel = (r: Partial<RelatorioImportacaoEntregas['resumo']>): RelatorioImportacaoEntregas => ({ linhas: [], resumo: { total: 3, validas: 3, erros: 0, duplicadas: 0, ...r } });

describe('importação Excel de entregas (K2)', () => {
  it('só se importa depois de validar, sem erros e com linhas válidas', () => {
    expect(podeImportar(null)).toBe(false);
    expect(podeImportar(rel({}))).toBe(true);
    expect(podeImportar(rel({ erros: 1, validas: 2 }))).toBe(false);
    expect(podeImportar(rel({ validas: 0, duplicadas: 3 }))).toBe(false);
    expect(podeImportar(rel({ validas: 2, duplicadas: 1 }))).toBe(true);
  });
  it('resume em português', () => {
    expect(textoResumoImportacao({ total: 5, validas: 3, erros: 1, duplicadas: 1 })).toBe('5 linha(s) · 3 válida(s) · 1 já importada(s), ignorada(s) · 1 com erro');
  });
  it('recusa extensões e tamanhos fora do limite do servidor', () => {
    expect(validarFicheiroImportacao({ name: 'a.xlsx', size: 100 })).toBeNull();
    expect(validarFicheiroImportacao({ name: 'a.CSV', size: 100 })).toBeNull();
    expect(validarFicheiroImportacao({ name: 'a.pdf', size: 100 })).not.toBeNull();
    expect(validarFicheiroImportacao({ name: 'a.xlsx', size: 6 * 1024 * 1024 })).not.toBeNull();
  });
});

describe('multas (K3)', () => {
  it('ninguém decide a própria multa; só se repõe uma dispensada', () => {
    expect(accoesMulta({ estado: 'PROPOSTA', colaborador_id: 2 }, 1)).toMatchObject({ aplicar: true, dispensar: true, repor: false });
    expect(accoesMulta({ estado: 'PROPOSTA', colaborador_id: 1 }, 1)).toMatchObject({ aplicar: false, dispensar: false, propria: true });
    expect(accoesMulta({ estado: 'DISPENSADA', colaborador_id: 2 }, 1).repor).toBe(true);
    expect(accoesMulta({ estado: 'APLICADA', colaborador_id: 2 }, 1)).toMatchObject({ aplicar: false, dispensar: false, repor: false });
  });
  it('totaliza por estado', () => {
    const t = totaisMultas([{ estado: 'PROPOSTA', valor: '100' }, { estado: 'PROPOSTA', valor: '50.5' }, { estado: 'APLICADA', valor: '10' }]);
    expect(t.PROPOSTA).toEqual({ n: 2, valor: 150.5 });
    expect(t.APLICADA.n).toBe(1);
    expect(t.DESCONTADA.n).toBe(0);
  });
  it('conta criadas/ignoradas em número ou lista', () => {
    expect(contar([1, 2])).toBe(2);
    expect(contar(4)).toBe(4);
    expect(contar(undefined)).toBe(0);
    expect(textoProposta({ criadas: 2, ignoradas: [1] })).toBe('2 multa(s) proposta(s), 1 já existente(s).');
  });
  it('o tecto é uma percentagem de 0 a 100', () => {
    expect(validarTectoMulta(null)).toBeNull();
    expect(validarTectoMulta(0)).toBeNull();
    expect(validarTectoMulta(100)).toBeNull();
    expect(validarTectoMulta(101)).not.toBeNull();
    expect(validarTectoMulta(-1)).not.toBeNull();
  });
});

describe('delegações (K5)', () => {
  it('pré-valida o período: não no passado, fim ≥ início, máximo 90 dias', () => {
    const hoje = '2026-11-03';
    expect(validarPeriodoDelegacao('2026-11-03', '2026-11-10', hoje)).toBeNull();
    expect(validarPeriodoDelegacao('2026-11-02', '2026-11-10', hoje)).toMatch(/passado/);
    expect(validarPeriodoDelegacao('2026-11-10', '2026-11-05', hoje)).toMatch(/anterior/);
    expect(validarPeriodoDelegacao('2026-11-03', '2027-01-31', hoje)).toBeNull(); // 90 dias contados
    expect(validarPeriodoDelegacao('2026-11-03', '2027-02-01', hoje)).toMatch(/máximo/); // 91 dias
    expect(validarPeriodoDelegacao(null, '2026-11-10', hoje)).not.toBeNull();
  });
  it('situação e revogação', () => {
    const d = (estado: 'ATIVA' | 'REVOGADA', i: string, f: string): Pick<DelegacaoChefia, 'estado' | 'data_inicio' | 'data_fim'> => ({ estado, data_inicio: i, data_fim: f });
    expect(situacaoDelegacao(d('ATIVA', '2026-11-01', '2026-11-10'), '2026-11-03')).toBe('vigente');
    expect(situacaoDelegacao(d('ATIVA', '2026-11-05', '2026-11-10'), '2026-11-03')).toBe('futura');
    expect(situacaoDelegacao(d('ATIVA', '2026-10-01', '2026-10-10'), '2026-11-03')).toBe('terminada');
    expect(situacaoDelegacao(d('REVOGADA', '2026-11-01', '2026-11-10'), '2026-11-03')).toBe('revogada');
    expect(podeRevogar(d('ATIVA', '2026-11-05', '2026-11-10'), '2026-11-03')).toBe(true);
    expect(podeRevogar(d('ATIVA', '2026-10-01', '2026-10-10'), '2026-11-03')).toBe(false);
  });
  it('âmbito em branco vale as três áreas', () => {
    expect(textoAmbito([])).toBe('Pedidos do portal, Método de trabalho, Entregáveis');
    expect(textoAmbito(['METODO'])).toBe('Método de trabalho');
  });
  it('normaliza a resposta do portal e a etiqueta «em nome de»', () => {
    expect(normalizarMinhasDelegacoes(undefined)).toEqual({ dadas: [], recebidas: [] });
    expect(normalizarMinhasDelegacoes({ dadas: [], recebidas: [{ id: 1 } as DelegacaoChefia] }).recebidas).toHaveLength(1);
    expect(etiquetaEmNomeDe({ nome: 'Ana', colaborador_id: 3 })).toBe('em nome de Ana');
    expect(etiquetaEmNomeDe({ colaborador_id: 3 })).toBe('em nome de #3');
    expect(etiquetaEmNomeDe(null)).toBeNull();
  });
});
