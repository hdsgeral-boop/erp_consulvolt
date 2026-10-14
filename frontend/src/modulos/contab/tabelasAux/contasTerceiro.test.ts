import { describe, expect, it } from 'vitest';
import {
  camposConta,
  classeCorrecta,
  contaCliente,
  contaFornecedor,
  daClasse,
  papeis,
  rotuloTipo,
  temPapel,
  textoMorada,
  validarContasTerceiro,
} from './contasTerceiro';

describe('contas do terceiro por papel (contas_terceiro.js)', () => {
  it('compara classes ignorando pontos e hífenes', () => {
    expect(daClasse('32.1.1', '32')).toBe(true);
    expect(daClasse('321001', '32')).toBe(true);
    expect(daClasse('37.0.1', '370')).toBe(true);
    expect(daClasse('37.1', '370')).toBe(false);
    expect(daClasse('', '31')).toBe(false);
  });

  it('valida a classe de cada papel', () => {
    expect(classeCorrecta('31.1.1', 'CLIENTE')).toBe(true);
    expect(classeCorrecta('32.1.1', 'CLIENTE')).toBe(false);
    expect(classeCorrecta('32.1.1', 'FORNECEDOR')).toBe(true);
    expect(classeCorrecta('37.0.2', 'FORNECEDOR')).toBe(true);
    expect(classeCorrecta('31.1', 'FORNECEDOR')).toBe(false);
    expect(classeCorrecta('', 'FORNECEDOR')).toBe(true);
  });

  it('lê a conta própria e, na falta, o codigo_conta antigo só se for da classe', () => {
    expect(contaCliente({ conta_cliente: '31.1.2', codigo_conta: '31.1.9' })).toBe('31.1.2');
    expect(contaCliente({ codigo_conta: '31.1.9' })).toBe('31.1.9');
    expect(contaFornecedor({ codigo_conta: '31.1.9' })).toBe('');
    expect(contaFornecedor({ codigo_conta: '37.0.1' })).toBe('37.0.1');
    expect(contaCliente({ codigo_conta: '32.1' })).toBe('');
    expect(contaCliente(null)).toBe('');
  });

  it('papéis e rótulos do tipo', () => {
    expect(papeis('CLIENTE_FORNECEDOR')).toEqual(['CLIENTE', 'FORNECEDOR']);
    expect(papeis(null)).toEqual([]);
    expect(temPapel('CLIENTE_FORNECEDOR', 'FORNECEDOR')).toBe(true);
    expect(temPapel('COLABORADOR', 'CLIENTE')).toBe(false);
    expect(rotuloTipo('CLIENTE_FORNECEDOR')).toBe('Fornecedor + Cliente');
    expect(rotuloTipo(null)).toBe('—');
    expect(camposConta('FORNECEDOR')).toEqual({ cliente: false, fornecedor: true });
    expect(camposConta('COLABORADOR')).toEqual({ cliente: false, fornecedor: false });
  });

  it('exige a conta de cada papel do tipo, da classe certa', () => {
    expect(validarContasTerceiro('CLIENTE', '', '')).toMatch(/conta de cliente/);
    expect(validarContasTerceiro('CLIENTE_FORNECEDOR', '31.1', '')).toMatch(/conta de fornecedor/);
    expect(validarContasTerceiro('CLIENTE', '32.1', '')).toMatch(/não é da classe 31/);
    expect(validarContasTerceiro('FORNECEDOR', '', '31.1')).toMatch(/não é da classe 32 nem 37.0/);
    expect(validarContasTerceiro('CLIENTE_FORNECEDOR', '31.1', '37.0.1')).toBeNull();
    expect(validarContasTerceiro('COLABORADOR', '', '')).toBeNull();
  });

  it('compõe o texto do endereço estruturado', () => {
    expect(textoMorada({ rua: 'Rua A', numero_porta: '12', bairro: 'Bairro B', municipio: 'Município C', provincia: 'Província D', pais: 'País E' })).toBe(
      'Rua A, n.º 12, Bairro B, Município C, Província D, País E',
    );
    expect(textoMorada({ bairro: 'Bairro B' })).toBe('Bairro B');
    expect(textoMorada(null)).toBe('');
  });
});
