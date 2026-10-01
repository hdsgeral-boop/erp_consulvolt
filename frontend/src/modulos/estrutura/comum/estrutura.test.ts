import { aplanar, construirArvore, descendentes, mapaPessoal, totaisMapa, type Posto, type Unidade } from './arvore';

const posto = (id: number, unidade: number, vagas: number, ocupados: number, extra: Partial<Posto> = {}): Posto => ({
  id, unidade_organica_id: unidade, cargo_funcao_id: null, titulo: `Posto ${id}`, vagas, posto_superior_id: null, responsabilidades: null, chefia: false, ordem: id, ocupados, livres: Math.max(0, vagas - ocupados), ...extra,
});

const unidade = (id: number, nome: string, pai: number | null, membros: number, postos: Posto[] = [], extra: Partial<Unidade> = {}): Unidade => ({
  id, codigo: null, nome, tipo: 'DEPARTAMENTO', unidade_organica_pai_id: pai, colaborador_responsavel_id: null, missao: null, atribuicoes: null, centro_custo_id: null,
  unidade_negocio_id: null, ordem: 0, ativo: true, apoio: false, cor: null, membros, postos, ...extra,
});

const UNIDADES = [
  unidade(1, 'Administração', null, 1, [posto(10, 1, 1, 1, { chefia: 1 })]),
  unidade(2, 'Finanças', 1, 3, [posto(20, 2, 2, 3)]),
  unidade(3, 'Contabilidade', 2, 2, [posto(30, 3, 4, 2)]),
  unidade(4, 'Comercial', 1, 0, [], { ordem: -1 }),
  unidade(5, 'Órfã', 99, 1),
  unidade(6, 'Ciclo A', 7, 0),
  unidade(7, 'Ciclo B', 6, 0),
  unidade(8, 'Inactiva', 1, 0, [], { ativo: false }),
];

describe('estrutura orgânica', () => {
  it('constrói a árvore com totais por ramo e trata órfãs e ciclos', () => {
    const a = construirArvore(UNIDADES);
    const raiz = a.map((n) => n.unidade.nome);
    expect(raiz).toContain('Administração');
    expect(raiz).toContain('Órfã');
    // num ciclo nenhuma das unidades desaparece
    expect(aplanar(a).map((n) => n.unidade.id).sort()).toEqual([1, 2, 3, 4, 5, 6, 7, 8]);
    const adm = a.find((n) => n.unidade.id === 1)!;
    expect(adm.filhos.map((f) => f.unidade.nome)).toEqual(['Comercial', 'Finanças', 'Inactiva']);
    expect(adm.membrosTotal).toBe(6);
    expect(adm.vagasTotal).toBe(7);
    expect(adm.ocupadosTotal).toBe(6);
    expect(construirArvore(UNIDADES, true).find((n) => n.unidade.id === 1)!.filhos.map((f) => f.unidade.id)).toEqual([4, 2]);
  });

  it('não deixa escolher um descendente como unidade superior', () => {
    expect([...descendentes(construirArvore(UNIDADES), 2)].sort()).toEqual([2, 3]);
    expect(descendentes(construirArvore(UNIDADES), 999).size).toBe(0);
  });

  it('calcula o mapa de pessoal e os totais', () => {
    const m = mapaPessoal(construirArvore(UNIDADES, true), () => 'Cargo');
    const fin = m.find((l) => l.chave === 'p20')!;
    expect(fin).toMatchObject({ unidade: 'Finanças', nivel: 1, vagas: 2, ocupados: 3, livres: 0, acima: 1 });
    expect(m.find((l) => l.chave === 'u4')).toMatchObject({ posto: null, vagas: 0 });
    expect(m.find((l) => l.chave === 'p10')!.chefia).toBe(true);
    expect(totaisMapa(m)).toEqual({ vagas: 7, ocupados: 6, livres: 2, acima: 1, taxa: 71.4 });
    expect(totaisMapa([]).taxa).toBeNull();
  });
});
