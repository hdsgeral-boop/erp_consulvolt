import { describe, expect, it } from 'vitest';
import { bytesModeloCambios, NOME_MODELO_CAMBIOS } from './modeloCambios';
import { enderecoSede, MODELO_CAMBIOS, podeUsarEmpresa, tituloPlanoContas, validarFicheiroPlano } from './paridade';

/** Configurações — paridade com o sistema anterior (verificação visual de 2026-10-08, linhas 14.2.1, 14.3.1 e 14.4.1). */
describe('Configurações — paridade', () => {
  it('Empresas: «Endereço sede» com «-» quando vazio; «Usar Empresa» só nas activas a que tem acesso', () => {
    expect(enderecoSede('  Rua Fictícia, 1 ')).toBe('Rua Fictícia, 1');
    expect(enderecoSede(null)).toBe('-');
    expect(enderecoSede('   ')).toBe('-');
    const acessiveis = new Set([10, 18]);
    expect(podeUsarEmpresa({ id: 18, estado: 'ATIVO' }, acessiveis)).toBe(true);
    expect(podeUsarEmpresa({ id: 18, estado: 'INATIVO' }, acessiveis)).toBe(false);
    expect(podeUsarEmpresa({ id: 99, estado: 'ATIVO' }, acessiveis)).toBe(false);
  });

  it('Plano de Contas: contagem no título e validação do ficheiro a importar', () => {
    expect(tituloPlanoContas(undefined)).toBe('Plano de Contas');
    expect(tituloPlanoContas(1)).toBe('Plano de Contas (1 conta)');
    expect(tituloPlanoContas(1421)).toBe('Plano de Contas (1421 contas)'); // como no antigo
    expect(validarFicheiroPlano({ name: 'plano.xlsx', size: 1000 })).toBeNull();
    expect(validarFicheiroPlano({ name: 'plano.CSV', size: 1000 })).toBeNull();
    expect(validarFicheiroPlano({ name: 'plano.pdf', size: 1000 })).toMatch(/XLSX, XLS ou CSV/);
    expect(validarFicheiroPlano({ name: 'plano.xlsx', size: 6 * 1024 * 1024 })).toMatch(/5 MB/);
  });

  it('Moedas: «Modelo» com os cabeçalhos do antigo, num XLSX gerado no navegador', () => {
    expect(MODELO_CAMBIOS[0]).toEqual(['Data', 'Moeda', 'Taxa', 'Origem', 'Âmbito']);
    expect(MODELO_CAMBIOS.length).toBeGreaterThan(1);
    const b = bytesModeloCambios();
    expect([b[0], b[1]]).toEqual([0x50, 0x4b]); // «PK» — contentor ZIP do XLSX
    expect(NOME_MODELO_CAMBIOS).toBe('modelo_cambios.xlsx');
  });
});
