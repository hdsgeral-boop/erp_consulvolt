import { exigeMotivo, filtrarCartoes, marcadoresDesconhecidos, podeMover, somar, totalItens, validarFunil, type Cartao } from './tipos';

const cartao = (id: number, titulo: string, conta: string, nivel: string): Cartao =>
  ({
    oportunidade: { id, titulo, conta_crm: { id, nome: conta, tipo: 'CLIENTE', terceiro_id: null } },
    probabilidade_efectiva: '10',
    valor_ponderado: '0.00',
    saude: { nivel, motivos: [], proxima: null, dias_etapa: 1 },
    atraso_financeiro: null,
  }) as unknown as Cartao;

describe('regras do CRM', () => {
  it('pede motivo só nas etapas de perda e só move para outra etapa', () => {
    expect(exigeMotivo({ id: 'p', nome: 'Perdida', tipo: 'PERDIDA' })).toBe(true);
    expect(exigeMotivo({ id: 'g', nome: 'Ganha', tipo: 'GANHA' })).toBe(false);
    expect(exigeMotivo(undefined)).toBe(false);
    expect(podeMover('a', 'b')).toBe(true);
    expect(podeMover('a', 'a')).toBe(false);
    expect(podeMover('a', '')).toBe(false);
  });

  it('soma linhas em cêntimos, com e sem IVA', () => {
    const itens = [
      { quantidade: 3, preco: '0.10', taxa: 14 },
      { quantidade: '2', preco: 1000.555, taxa: 0 },
    ];
    expect(totalItens(itens)).toBe('2001.42');
    expect(totalItens(itens, true)).toBe('2001.46');
    expect(totalItens(null)).toBe('0.00');
    expect(somar(['0.10', '0.20', null, 1])).toBe('1.30');
  });

  it('filtra os cartões do quadro por texto e risco', () => {
    const cs = [cartao(1, 'Projecto Bengo', 'Wipro', 'RISCO'), cartao(2, 'Estágio', 'Acme', 'OK'), cartao(3, 'Licenças', 'Bengo SA', 'ATENCAO')];
    expect(filtrarCartoes(cs, 'bengo').map((c) => c.oportunidade.id)).toEqual([1, 3]);
    expect(filtrarCartoes(cs, '', true).map((c) => c.oportunidade.id)).toEqual([1, 3]);
    expect(filtrarCartoes(cs, 'acme', true)).toHaveLength(0);
  });

  it('valida um funil antes de gravar', () => {
    expect(validarFunil([{ nome: 'A', tipo: 'ABERTA' }, { nome: 'G', tipo: 'GANHA' }, { nome: 'P', tipo: 'PERDIDA' }])).toEqual([]);
    const erros = validarFunil([{ nome: 'A', tipo: 'ABERTA' }, { nome: '', tipo: 'ABERTA' }]);
    expect(erros).toContain('O funil precisa de pelo menos 3 etapas.');
    expect(erros).toContain('Falta uma etapa de ganho.');
    expect(erros).toContain('Todas as etapas precisam de nome.');
  });

  it('detecta marcadores desconhecidos nos modelos de email', () => {
    expect(marcadoresDesconhecidos('Olá {{contacto}}, {{ valor }} {{xpto}} {{xpto}}', ['contacto', 'valor'])).toEqual(['xpto']);
  });
});
