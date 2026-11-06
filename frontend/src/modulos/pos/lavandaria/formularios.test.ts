import { describe, expect, it } from 'vitest';
import { errosLinha } from '@/componentes/formularios';
import { CAMPOS_PECA, CAMPOS_SERVICO, dadosPeca, dadosServico, matrizDaPeca, precosServicoDaMatriz, validarOpcoesPeca } from './formularios';
import { chaveOpcao, opcoesPeca, precoPecaServico, rotuloOpcao } from './tipos';

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

  it('peça com opções: o pedido segue a lista (o tamanho só no modo Tamanho) e não leva unidade/preço', () => {
    const d = dadosPeca({ nome: 'Fato', ativo: true, opcoes_preco: [{ modo: 'PECA', tamanho: 'x', preco: 10000 }, { modo: 'TAMANHO', tamanho: ' M ', preco: '12000' }], precos_servico: [] });
    expect(d).toEqual({ nome: 'Fato', tecido: null, cor: null, ativo: true, precos_servico: [], opcoes_preco: [{ modo: 'PECA', tamanho: '', preco: 10000 }, { modo: 'TAMANHO', tamanho: 'M', preco: 12000 }] });
  });

  it('validações do editor com as mensagens do legado', () => {
    expect(validarOpcoesPeca([])).toMatch(/pelo menos uma opção/);
    expect(validarOpcoesPeca([{ modo: 'TAMANHO', tamanho: ' ', preco: 1 }])).toBe('Dê nome ao tamanho (ex.: M, Casal) ou remova essa opção de preço.');
    expect(validarOpcoesPeca([{ modo: 'TAMANHO', tamanho: 'M', preco: 1 }, { modo: 'TAMANHO', tamanho: 'm', preco: 2 }])).toBe('Há duas opções de preço iguais. Cada opção só pode aparecer uma vez.');
    expect(validarOpcoesPeca([{ modo: 'PECA', preco: 1 }, { modo: 'KG', preco: 2 }, { modo: 'TAMANHO', tamanho: 'M', preco: 0 }])).toBeNull();
  });

  it('matriz serviço × opção: ida e volta sem perder modo nem tamanho; entradas antigas sem modo', () => {
    const peca = {
      preco: '1000.00',
      unidade: 'PECA' as const,
      opcoes_preco: [
        { modo: 'PECA' as const, tamanho: '', preco: '1000.00' },
        { modo: 'KG' as const, tamanho: '', preco: '800.00' },
        { modo: 'TAMANHO' as const, tamanho: 'M', preco: '1300.00' },
      ],
      precos_servico: [
        { produto_id: 5, modo: 'KG' as const, tamanho: '', preco: '900.00' },
        { produto_id: 5, modo: 'TAMANHO' as const, tamanho: 'M', preco: '1500.00' },
        { produto_id: 6, preco: '700.00' },
      ],
    };
    const { matriz, semModo } = matrizDaPeca(peca);
    expect(matriz).toEqual({ '5': { 'KG|': 900, 'TAMANHO|m': 1500 } });
    expect(semModo).toEqual([{ produto_id: 6, preco: 700 }]); // 3 opções: a entrada antiga segue como está
    expect(precosServicoDaMatriz(matriz, peca.opcoes_preco, semModo)).toEqual([
      { produto_id: 5, modo: 'KG', tamanho: '', preco: 900 },
      { produto_id: 5, modo: 'TAMANHO', tamanho: 'M', preco: 1500 },
      { produto_id: 6, preco: 700 },
    ]);
    // retirar as opções Kg e M descarta os preços delas; vazio/0 = usa o preço da opção
    expect(precosServicoDaMatriz({ '5': { 'KG|': 900, 'TAMANHO|m': 0 } }, peca.opcoes_preco.slice(0, 1))).toEqual([]);
    // peça de uma só opção: a entrada antiga sem modo passa para essa opção
    const unica = matrizDaPeca({ preco: '1000', unidade: 'PECA', opcoes_preco: null, precos_servico: [{ produto_id: 5, preco: '900.00' }] });
    expect(unica).toEqual({ matriz: { '5': { 'PECA|': 900 } }, semModo: [] });
  });

  it('regra de preço (opções): entrada da opção, antiga sem modo, preço da opção e 1.ª opção por omissão', () => {
    const peca = {
      preco: '1000.00',
      unidade: 'PECA' as const,
      opcoes_preco: [
        { modo: 'PECA' as const, tamanho: '', preco: '1000.00' },
        { modo: 'KG' as const, tamanho: '', preco: '800.00' },
        { modo: 'TAMANHO' as const, tamanho: 'M', preco: '1300.00' },
        { modo: 'TAMANHO' as const, tamanho: ' ', preco: '9' },
      ],
      precos_servico: [
        { produto_id: 5, modo: 'KG' as const, tamanho: '', preco: '900.00' },
        { produto_id: 5, modo: 'TAMANHO' as const, tamanho: 'M', preco: '1500.00' },
        { produto_id: 6, preco: '700.00' },
      ],
    };
    expect(opcoesPeca(peca).map(rotuloOpcao)).toEqual(['Peça', 'Quilograma (kg)', 'Tamanho M']); // tamanho sem nome ignora-se
    expect(precoPecaServico(peca, 5)).toBe(1000); // sem opção = a 1.ª; sem entrada para ela: preço da opção
    expect(precoPecaServico(peca, 5, { modo: 'KG', tamanho: '' })).toBe(900);
    expect(precoPecaServico(peca, 5, { modo: 'TAMANHO', tamanho: 'm' })).toBe(1500); // o tamanho não distingue maiúsculas
    expect(precoPecaServico(peca, 6, { modo: 'TAMANHO', tamanho: 'M' })).toBe(700); // entrada antiga sem modo vale para qualquer opção (legado)
    expect(precoPecaServico(peca, 7, { modo: 'TAMANHO', tamanho: 'M' })).toBe(1300);
    // peça migrada sem opções (só unidade + preço): uma opção, o mesmo preço de sempre
    expect(precoPecaServico({ preco: '800.00', unidade: 'KG', precos_servico: [{ produto_id: 5, preco: 900 }] }, 5)).toBe(900);
    expect(chaveOpcao({ modo: 'TAMANHO', tamanho: 'Casal' })).toBe('TAMANHO|casal');
  });
});
