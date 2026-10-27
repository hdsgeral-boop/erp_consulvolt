import { describe, expect, it } from 'vitest';
import { contaCombinaComMeio, contaFinanceiraPermitida, contaParaCopia, documentoAnteriorEmCaixa, meioDaConta, opcoesMeio, PREFIXO_MEIO } from './contaFinanceira';

describe('tesouraria só por bancos (Fase A)', () => {
  it('meio e prefixo da conta', () => {
    expect(PREFIXO_MEIO).toEqual({ BANCO: '43', PRAZO: '42', CAIXA: '45' });
    expect(meioDaConta('4311')).toBe('BANCO');
    expect(meioDaConta('4211')).toBe('PRAZO');
    expect(meioDaConta('4511')).toBe('CAIXA');
    expect(meioDaConta(undefined)).toBe('BANCO');
  });

  it('o meio CAIXA só aparece para o documento anterior em 45', () => {
    expect(opcoesMeio(undefined).map((o) => o.value)).toEqual(['BANCO', 'PRAZO']);
    expect(opcoesMeio('4311').map((o) => o.value)).toEqual(['BANCO', 'PRAZO']);
    expect(opcoesMeio('4511').map((o) => o.value)).toEqual(['BANCO', 'PRAZO', 'CAIXA']);
    expect(opcoesMeio('4511')[2].label).toBe('Caixa (45) — documento anterior');
    expect(documentoAnteriorEmCaixa('4511')).toBe(true);
    expect(documentoAnteriorEmCaixa('4311')).toBe(false);
  });

  it('documentos novos só em 42/43; o anterior em 45 pode manter uma conta 45', () => {
    expect(contaFinanceiraPermitida('4311', undefined)).toBe(true);
    expect(contaFinanceiraPermitida('4211', undefined)).toBe(true);
    expect(contaFinanceiraPermitida('4511', undefined)).toBe(false);
    expect(contaFinanceiraPermitida('611', undefined)).toBe(false);
    expect(contaFinanceiraPermitida('4512', '4511')).toBe(true);
    expect(contaFinanceiraPermitida('4511', '4311')).toBe(false);
    expect(contaFinanceiraPermitida(undefined, '4511')).toBe(false);
  });

  it('a cópia de um documento em caixa fica sem conta (a escolher)', () => {
    expect(contaParaCopia('4511')).toBeUndefined();
    expect(contaParaCopia('4311')).toBe('4311');
    expect(contaParaCopia(null)).toBeUndefined();
  });
});

describe('conta-cartão de tesouraria (classe 3) na Tesouraria', () => {
  it('o meio CARTAO só aparece se a empresa tiver contas marcadas; não é um prefixo', () => {
    expect(opcoesMeio(undefined, false).map((o) => o.value)).toEqual(['BANCO', 'PRAZO']);
    expect(opcoesMeio(undefined, true).map((o) => o.value)).toEqual(['BANCO', 'PRAZO', 'CARTAO']);
    expect(meioDaConta('3282', true)).toBe('CARTAO');
    expect(meioDaConta('3282', false)).toBe('BANCO');   // sem a flag, "3282" não é reconhecida por prefixo
  });

  it('uma conta-cartão é sempre permitida, mesmo não sendo 42/43', () => {
    expect(contaFinanceiraPermitida('3282', undefined, true)).toBe(true);
    expect(contaFinanceiraPermitida('3282', undefined, false)).toBe(false);
  });

  it('contaCombinaComMeio: CARTAO depende só da marcação, os outros meios do prefixo', () => {
    expect(contaCombinaComMeio('3282', 'CARTAO', true)).toBe(true);
    expect(contaCombinaComMeio('3282', 'CARTAO', false)).toBe(false);
    expect(contaCombinaComMeio('4311', 'BANCO', false)).toBe(true);
    expect(contaCombinaComMeio('4311', 'CARTAO', false)).toBe(false);
  });

  it('a cópia de um documento em cartão mantém a conta', () => {
    expect(contaParaCopia('3282', true)).toBe('3282');
    expect(contaParaCopia('3282', false)).toBeUndefined();
  });
});
