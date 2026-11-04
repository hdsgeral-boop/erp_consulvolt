import { beforeEach, describe, expect, it } from 'vitest';
import { armazenamento, limparChavesAntigas } from './armazenamento';
import { idOpaco } from './chaveOpaca';

describe('armazenamento da sessão — nada de autenticação no navegador', () => {
  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    armazenamento.limpar();
  });

  it('a empresa activa só existe em memória: não escreve em localStorage nem sessionStorage', () => {
    armazenamento.definirEmpresa(7);
    expect(armazenamento.empresaId()).toBe(7);
    expect(localStorage.length).toBe(0);
    expect(sessionStorage.length).toBe(0);
    armazenamento.limpar();
    expect(armazenamento.empresaId()).toBeNull();
  });

  it('apaga o que versões anteriores deixaram: dispositivo, empresa activa e empresas recentes', () => {
    localStorage.setItem('erp.dispositivo', 'web-1a2b3c4d');
    localStorage.setItem('erp.empresa', '3');
    localStorage.setItem('erp.empresas_recentes.12', '[1,2,3]');
    localStorage.setItem('erp.empresas_recentes.admin', '[1]');
    localStorage.setItem('erp.colunas', '{}'); // preferência de interface sem utilizador: fica
    limparChavesAntigas(idOpaco);
    expect(Object.keys(localStorage).sort()).toEqual(['erp.colunas']);
  });

  it('converte as chaves de interface com o id do utilizador legível em chaves opacas, sem perder o valor', () => {
    localStorage.setItem('erp.aspecto.12', '{"tema":"escuro","menu":"completo"}');
    localStorage.setItem('erp.menu.largura.12', '300');
    localStorage.setItem('erp.pesquisa.posicao.12', '{"x":1,"y":2}');
    localStorage.setItem('erp.pos.terminal.5', '9');
    limparChavesAntigas(idOpaco);

    expect(localStorage.getItem('erp.aspecto.12')).toBeNull();
    expect(localStorage.getItem(`erp.aspecto.${idOpaco('12')}`)).toBe('{"tema":"escuro","menu":"completo"}');
    expect(localStorage.getItem(`erp.menu.largura.${idOpaco('12')}`)).toBe('300');
    expect(localStorage.getItem(`erp.pesquisa.posicao.${idOpaco('12')}`)).toBe('{"x":1,"y":2}');
    expect(localStorage.getItem(`erp.pos.terminal.${idOpaco('5')}`)).toBe('9');
    // nenhuma chave restante tem o id nu
    expect(Object.keys(localStorage).filter((k) => /\.\d+$/.test(k))).toEqual([]);
  });

  it('não sobrepõe uma chave opaca já existente', () => {
    localStorage.setItem(`erp.menu.largura.${idOpaco('12')}`, '250');
    localStorage.setItem('erp.menu.largura.12', '300');
    limparChavesAntigas(idOpaco);
    expect(localStorage.getItem(`erp.menu.largura.${idOpaco('12')}`)).toBe('250');
    expect(localStorage.getItem('erp.menu.largura.12')).toBeNull();
  });
});

describe('idOpaco', () => {
  it('é estável, não contém o valor e distingue valores', () => {
    expect(idOpaco(12)).toBe(idOpaco('12'));
    expect(idOpaco(12)).not.toBe(idOpaco(13));
    expect(idOpaco(12)).not.toContain('12');
    expect(idOpaco(12)).toMatch(/^u[0-9a-z]+$/);
    expect(idOpaco(null)).toBe('anonimo');
    expect(idOpaco(undefined)).toBe('anonimo');
  });
});
