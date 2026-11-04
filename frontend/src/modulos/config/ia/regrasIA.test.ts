import { describe, expect, it } from 'vitest';
import type { FornecedorIA } from '@/componentes/assistente/tipos';
import { corpoFornecedor, estadoDaChave, fornecedoresAtribuiveis, nivelQuota, problemaAtribuicao, totaisConsumo } from './regrasIA';

const F = (o: Partial<FornecedorIA>): FornecedorIA => ({
  id: 1, fornecedor: 'anthropic', nome: 'Anthropic', configurado: true, chave_final: null, modelos: ['m1', 'm2'], modelo_padrao: 'm1',
  preco_entrada_mtok: 3, preco_saida_mtok: 15, ativo: true, testado_em: null, ultimo_erro: null, ...o,
});

describe('corpoFornecedor', () => {
  it('chave em branco ou só espaços não vai no pedido (o servidor mantém a actual)', () => {
    expect(corpoFornecedor({ chave: '', ativo: true })).toEqual({ ativo: true });
    expect(corpoFornecedor({ chave: '   ', ativo: true })).toEqual({ ativo: true });
    expect(corpoFornecedor({ ativo: true })).toEqual({ ativo: true });
  });
  it('chave digitada vai aparada', () => {
    expect(corpoFornecedor({ chave: '  sk-abc  ', modelo_padrao: 'm1' })).toEqual({ chave: 'sk-abc', modelo_padrao: 'm1' });
  });
});

describe('estadoDaChave', () => {
  it('só diz se está configurada, nunca parte da chave', () => {
    expect(estadoDaChave({ configurado: false, ativo: false }).texto).toBe('Sem chave');
    expect(estadoDaChave({ configurado: true, ativo: true }).texto).toContain('configurada');
    expect(estadoDaChave({ configurado: true, ativo: false }).texto).toContain('inactivo');
  });
});

describe('atribuição', () => {
  const fs = [F({}), F({ fornecedor: 'openai', nome: 'OpenAI', configurado: false, modelos: [] }), F({ fornecedor: 'gemini', nome: 'Gemini', ativo: false })];

  it('só fornecedores com chave e activos são atribuíveis', () => {
    expect(fornecedoresAtribuiveis(fs).map((f) => f.fornecedor)).toEqual(['anthropic']);
  });

  it('valida fornecedor, modelo e quota mínima', () => {
    expect(problemaAtribuicao({ fornecedor: null, modelo: null, quota_tokens_mes: null }, fs)).toMatch(/fornecedor/);
    expect(problemaAtribuicao({ fornecedor: 'openai', modelo: 'x', quota_tokens_mes: null }, fs)).toMatch(/chave/);
    expect(problemaAtribuicao({ fornecedor: 'anthropic', modelo: null, quota_tokens_mes: null }, fs)).toMatch(/modelo/);
    expect(problemaAtribuicao({ fornecedor: 'anthropic', modelo: 'outro', quota_tokens_mes: null }, fs)).toMatch(/lista/);
    expect(problemaAtribuicao({ fornecedor: 'anthropic', modelo: 'm1', quota_tokens_mes: 500 }, fs)).toMatch(/mínima/);
    expect(problemaAtribuicao({ fornecedor: 'anthropic', modelo: 'm1', quota_tokens_mes: null }, fs)).toBeNull();
    expect(problemaAtribuicao({ fornecedor: 'anthropic', modelo: 'm2', quota_tokens_mes: 1000 }, fs)).toBeNull();
  });
});

describe('consumo', () => {
  it('soma tokens, custo, pedidos e pesquisas dos clientes', () => {
    expect(totaisConsumo({ clientes_mes: [
      { cliente: 'A', tokens: 100, custo_usd: 0.5, pedidos: 2, pesquisas: 1 },
      { cliente: 'B', tokens: 50, custo_usd: 0.25, pedidos: 1, pesquisas: 0 },
    ] })).toEqual({ tokens: 150, custoUsd: 0.75, pedidos: 3, pesquisas: 1 });
    expect(totaisConsumo(undefined)).toEqual({ tokens: 0, custoUsd: 0, pedidos: 0, pesquisas: 0 });
  });

  it('nível da quota: sem limite, normal, alerta (80 %) e esgotada', () => {
    expect(nivelQuota({ quota_tokens_mes: null, tokens_mes: 9 })).toBe('sem_limite');
    expect(nivelQuota({ quota_tokens_mes: 1000, tokens_mes: 500 })).toBe('normal');
    expect(nivelQuota({ quota_tokens_mes: 1000, tokens_mes: 800 })).toBe('alerta');
    expect(nivelQuota({ quota_tokens_mes: 1000, tokens_mes: 1000 })).toBe('esgotada');
  });
});
