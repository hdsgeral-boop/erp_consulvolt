import { describe, expect, it } from 'vitest';
import { lerCaminhoEcra, normalizarCaminhoLegado } from './caminhos';

describe('endereços do ERP sem prefixo (/{modulo}/{ecra})', () => {
  it('lê módulo e ecrã', () => {
    expect(lerCaminhoEcra('/config/config_licenca')).toEqual({ modulo: 'config', ecra: 'config_licenca' });
    expect(lerCaminhoEcra('/vendas/faturacao/123')).toEqual({ modulo: 'vendas', ecra: 'faturacao' });
    expect(lerCaminhoEcra('/geral/relatorios_gestao?x=1#topo')).toEqual({ modulo: 'geral', ecra: 'relatorios_gestao' });
  });

  it('o Início e as rotas próprias da aplicação não são ecrãs de módulo', () => {
    expect(lerCaminhoEcra('/')).toBeNull();
    expect(lerCaminhoEcra('')).toBeNull();
    expect(lerCaminhoEcra('/dados-pessoais')).toBeNull();
    expect(lerCaminhoEcra('/portal/recibos')).toBeNull();
    expect(lerCaminhoEcra('/plataforma/clientes')).toBeNull();
    expect(lerCaminhoEcra('/redefinir-senha')).toBeNull();
    expect(lerCaminhoEcra('/config')).toBeNull();
  });

  it('converte os endereços antigos com /m/ e deixa os novos como estão', () => {
    expect(normalizarCaminhoLegado('/m/config/config_licenca')).toBe('/config/config_licenca');
    expect(normalizarCaminhoLegado('/m/vendas/faturacao/55')).toBe('/vendas/faturacao/55');
    expect(normalizarCaminhoLegado('/config/config_licenca')).toBe('/config/config_licenca');
    expect(normalizarCaminhoLegado('/')).toBe('/');
    expect(normalizarCaminhoLegado('/m')).toBe('/m');
  });
});
