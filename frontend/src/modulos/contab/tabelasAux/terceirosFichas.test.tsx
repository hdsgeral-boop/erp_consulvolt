import { describe, expect, it } from 'vitest';
import { avisoOutroPapel, corpoTerceiro } from '@/modulos/compras/comum/GestaoTerceiros';
import { corpoTerceiroAux } from './SeparadorTerceiros';

describe('fichas de terceiros com contas por papel (Fase A)', () => {
  it('Tabelas auxiliares: o tipo substitui o anterior e só seguem as contas que o tipo pede', () => {
    const c = corpoTerceiroAux({ tipo: 'FORNECEDOR', nif: ' 5000000001 ', nome: 'Entidade Fictícia', conta_cliente: '31.1', conta_fornecedor: '32.1', rua: 'Rua A', bairro: '' });
    expect(c).toMatchObject({ papel: 'FORNECEDOR', substituir_tipo: true, nif: '5000000001', conta_fornecedor: '32.1' });
    expect(c.conta_cliente).toBeUndefined();
    expect(c.morada).toEqual({ rua: 'Rua A', numero_porta: null, bairro: null, municipio: null, provincia: null, pais: null });
    const ambos = corpoTerceiroAux({ tipo: 'CLIENTE_FORNECEDOR', conta_cliente: '31.1', conta_fornecedor: '37.0.1' });
    expect([ambos.conta_cliente, ambos.conta_fornecedor]).toEqual(['31.1', '37.0.1']);
  });

  it('fichas de clientes e fornecedores gravam só a conta do seu papel', () => {
    expect(corpoTerceiro('CLIENTE', { nome: 'X', conta_cliente: '31.1' })).toMatchObject({ papel: 'CLIENTE', conta_cliente: '31.1', conta_fornecedor: undefined });
    expect(corpoTerceiro('FORNECEDOR', { nome: 'X', conta_fornecedor: '32.1' })).toMatchObject({ papel: 'FORNECEDOR', conta_fornecedor: '32.1', conta_cliente: undefined });
    // compatível com o campo antigo
    expect(corpoTerceiro('CLIENTE', { nome: 'X', codigo_conta: '31.2' }).conta_cliente).toBe('31.2');
  });

  it('avisa quando a entidade também tem o outro papel', () => {
    expect(avisoOutroPapel('CLIENTE', { tipo: 'CLIENTE_FORNECEDOR', conta_fornecedor: '32.1' })).toMatch(/também é fornecedor \(conta 32.1\)/);
    expect(avisoOutroPapel('FORNECEDOR', { tipo: 'CLIENTE_FORNECEDOR' })).toMatch(/também é cliente \(conta por definir\)/);
    expect(avisoOutroPapel('CLIENTE', { tipo: 'CLIENTE' })).toBeNull();
    expect(avisoOutroPapel('CLIENTE', null)).toBeNull();
  });
});
