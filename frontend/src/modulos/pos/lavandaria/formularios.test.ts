import { describe, expect, it } from 'vitest';
import { errosLinha } from '@/componentes/formularios';
import { CAMPOS_PECA, CAMPOS_SERVICO, dadosPeca, dadosServico } from './formularios';

describe('lavandaria — «Nova peça» / «Novo serviço» (grade e linhas com o mesmo pedido)', () => {
  it('peça: grade (interruptor) e linha dão o mesmo corpo', () => {
    const grade = dadosPeca({ nome: ' Camisa ', tecido: 'Algodão', cor: '', unidade: 'PECA', preco: 1500, ativo: true, precos_servico: [{ produto_id: 3, preco: 2000 }] });
    expect(grade).toEqual({ nome: 'Camisa', tecido: 'Algodão', cor: null, unidade: 'PECA', preco: 1500, ativo: true, precos_servico: [{ produto_id: 3, preco: 2000 }] });
    const linha = dadosPeca({ nome: 'Roupa diversa', tecido: '', cor: '', unidade: 'KG', preco: null });
    expect(linha).toEqual({ nome: 'Roupa diversa', tecido: null, cor: null, unidade: 'KG', preco: 0, ativo: true, precos_servico: [] });
  });

  it('serviço: «Orçamento» Sim/Não das linhas e o interruptor da grade; omissões do legado (IVA 14 %, 2 dias)', () => {
    expect(dadosServico({ nome: 'Lavar e engomar', grupo: 'LAVANDARIA', codigo_conta: '6211', taxa_imposto: null, dias_entrega: null, orcamento: 'S' })).toEqual({
      nome: 'Lavar e engomar',
      grupo: 'LAVANDARIA',
      codigo_conta: '6211',
      conta_iva_liquidado: undefined,
      taxa_imposto: 14,
      dias_entrega: 2,
      codigo_isencao_fe: undefined,
      requer_orcamento: true,
      ativa: true,
    });
    const grade = dadosServico({ nome: 'Bainha', grupo: 'ALFAIATARIA', codigo_conta: '6212', taxa_imposto: 0, dias_entrega: 3.4, requer_orcamento: false, ativa: false, codigo_isencao_fe: 'M10' });
    expect(grade).toMatchObject({ taxa_imposto: 0, dias_entrega: 3, requer_orcamento: false, ativa: false, codigo_isencao_fe: 'M10' });
  });

  it('validação das linhas: designação obrigatória e conta de proveitos da classe 62', () => {
    expect(errosLinha(CAMPOS_PECA, { nome: '' })).toMatch(/Designação da peça/);
    expect(errosLinha(CAMPOS_SERVICO, { nome: 'Lavar', grupo: 'LAVANDARIA', codigo_conta: '7111' })).toMatch(/classe 62/);
    expect(errosLinha(CAMPOS_SERVICO, { nome: 'Lavar', grupo: 'LAVANDARIA', codigo_conta: '6211' })).toBeNull();
  });
});
