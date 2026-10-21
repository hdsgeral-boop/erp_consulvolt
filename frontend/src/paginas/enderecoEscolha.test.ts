import { beforeEach, describe, expect, it } from 'vitest';
import { enderecoDepoisDaEscolha, marcarEnderecoEscolha, ROTA_ESCOLHA_EMPRESA } from './EscolherEmpresa';

describe('escolha de empresa com endereço próprio (/selecionar-empresa)', () => {
  beforeEach(() => sessionStorage.clear());

  it('depois de entrar ou de trocar de empresa: /selecionar-empresa e, escolhida a empresa, o início', () => {
    window.history.replaceState(null, '', '/');
    marcarEnderecoEscolha();
    expect(window.location.pathname).toBe(ROTA_ESCOLHA_EMPRESA);
    expect(enderecoDepoisDaEscolha()).toBe('/');
  });

  it('um link directo aberto antes da escolha é retomado depois (uma só vez)', () => {
    window.history.replaceState(null, '', '/rh/colaboradores?aba=2');
    marcarEnderecoEscolha();
    expect(window.location.pathname).toBe(ROTA_ESCOLHA_EMPRESA);
    expect(enderecoDepoisDaEscolha()).toBe('/rh/colaboradores?aba=2');
    expect(enderecoDepoisDaEscolha()).toBe('/');
  });

  it('o portal mantém o seu endereço e um destino externo é ignorado', () => {
    window.history.replaceState(null, '', '/portal');
    marcarEnderecoEscolha();
    expect(window.location.pathname).toBe('/portal');
    sessionStorage.setItem('erp.destino_apos_escolha', '//exemplo.com');
    expect(enderecoDepoisDaEscolha()).toBe('/');
  });
});
