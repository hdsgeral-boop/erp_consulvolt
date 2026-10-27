import { colagemParaLinhas, pareceColagemDeGrade, posicaoColagem } from './colagemGrade';
import type { CampoFormulario } from './tipos';

const CAMPOS: CampoFormulario[] = [
  { nome: 'codigo_conta', rotulo: 'Conta' },
  { nome: 'tipo_dc', rotulo: 'D/C', tipo: 'select', opcoes: [{ value: 'D', label: 'D (DÉBITO)' }, { value: 'C', label: 'C (CRÉDITO)' }] },
  { nome: 'valor', rotulo: 'Valor', tipo: 'numero' },
  { nome: 'descricao', rotulo: 'Descrição' },
];

describe('colagemParaLinhas (grades fora do PainelLinhas: linhas de produtos, débito/crédito, inventário…)', () => {
  it('sem cabeçalho: lê pela ordem dos campos dados', () => {
    const r = colagemParaLinhas('43101001\tD\t70 004,54\tRenda de Outubro\n62100000\tC\t70004,54\t', CAMPOS);
    expect(r.comCabecalho).toBe(false);
    expect(r.naoReconhecidos).toBe(0);
    expect(r.linhas).toEqual([
      { codigo_conta: '43101001', tipo_dc: 'D', valor: 70004.54, descricao: 'Renda de Outubro' },
      { codigo_conta: '62100000', tipo_dc: 'C', valor: 70004.54 },
    ]);
  });

  it('com cabeçalho reconhecido: a ordem das colunas coladas deixa de importar', () => {
    const r = colagemParaLinhas('Valor\tConta\tD/C\n1000\t43101001\tD', CAMPOS);
    expect(r.comCabecalho).toBe(true);
    expect(r.linhas).toEqual([{ valor: 1000, codigo_conta: '43101001', tipo_dc: 'D' }]);
  });

  it('valor de lista aceita o código ou o nome', () => {
    const r = colagemParaLinhas('43101001\tD (DÉBITO)\t100\t', CAMPOS);
    expect(r.linhas[0].tipo_dc).toBe('D');
  });

  it('assinala valores não reconhecidos sem bloquear a linha', () => {
    const r = colagemParaLinhas('43101001\tX\tabc\t', CAMPOS);
    expect(r.naoReconhecidos).toBe(2);
    expect(r.exemplos['D/C']).toEqual(['X']);
    expect(r.exemplos.Valor).toEqual(['abc']);
    expect(r.linhas[0].codigo_conta).toBe('43101001');
  });

  it('colagem directa numa célula (posicional, a partir da coluna clicada, sem detectar cabeçalho)', () => {
    // clicou na coluna «Valor» (índice 2): só Valor e Descrição se preenchem nesta linha
    const r = colagemParaLinhas('1000\tRenda\n2000\tÁgua', CAMPOS, 2);
    expect(r.comCabecalho).toBe(false);
    expect(r.linhas).toEqual([{ valor: 1000, descricao: 'Renda' }, { valor: 2000, descricao: 'Água' }]);
  });

  it('texto vazio ou sem colunas a partir do índice dado → sem linhas', () => {
    expect(colagemParaLinhas('', CAMPOS)).toEqual({ linhas: [], comCabecalho: false, naoReconhecidos: 0, exemplos: {} });
    expect(colagemParaLinhas('a\tb', CAMPOS, 99)).toEqual({ linhas: [], comCabecalho: false, naoReconhecidos: 0, exemplos: {} });
  });
});

describe('pareceColagemDeGrade', () => {
  it('reconhece texto com tabulação, várias linhas ou vários separadores «|»/«;»', () => {
    expect(pareceColagemDeGrade('a\tb')).toBe(true);
    expect(pareceColagemDeGrade('a\nb')).toBe(true);
    expect(pareceColagemDeGrade('a|b|c')).toBe(true);
    expect(pareceColagemDeGrade('a;b')).toBe(true);
    expect(pareceColagemDeGrade('43101001')).toBe(false);
  });
});

describe('posicaoColagem', () => {
  it('lê a linha/coluna dos atributos data-colar-linha/data-colar-coluna do elemento mais próximo', () => {
    document.body.innerHTML = '<div data-colar-linha="2"><div data-colar-coluna="1"><input id="alvo"></div></div>';
    const alvo = document.getElementById('alvo')!;
    expect(posicaoColagem(alvo)).toEqual({ linha: 2, coluna: 1 });
  });

  it('sem os atributos à volta → null', () => {
    document.body.innerHTML = '<input id="solto">';
    expect(posicaoColagem(document.getElementById('solto'))).toBeNull();
  });
});
