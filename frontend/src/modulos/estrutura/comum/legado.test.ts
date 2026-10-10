import { describe, expect, it } from 'vitest';
import { construirArvore, tipoInfo, TIPOS_UNIDADE, type Posto, type Unidade } from './arvore';
import {
  caminho, documentoMapaLegado, estadoVagas, idsEmColuna, idsEmpilharUltimos, idsRecolher, linhasMapaLegado, linhasMembros, mapaSalarios, proximoZoom, totaisMapaLegado,
  type ColaboradorEstrutura,
} from './legado';

const posto = (id: number, unidade: number, p: Partial<Posto> = {}): Posto => ({
  id, unidade_organica_id: unidade, cargo_funcao_id: null, titulo: `Cargo ${id}`, vagas: 1, posto_superior_id: null, responsabilidades: null, chefia: false, ordem: id, ocupados: 0, livres: 1, ...p,
});
const unidade = (id: number, nome: string, pai: number | null, p: Partial<Unidade> = {}): Unidade => ({
  id, codigo: null, nome, tipo: 'DEPARTAMENTO', unidade_organica_pai_id: pai, colaborador_responsavel_id: null, missao: null, atribuicoes: null, centro_custo_id: null,
  unidade_negocio_id: null, ordem: id, ativo: true, apoio: false, cor: null, disposicao: null, membros: 0, postos: [], ...p,
});
const colab = (id: number, nome: string, u: number | null, p: Partial<ColaboradorEstrutura> = {}): ColaboradorEstrutura => ({
  id, nome_completo: nome, estado: 'ACTIVO', unidade_organica_id: u, posto_trabalho_id: null, colaborador_gestor_id: null, cargo_funcao_id: null, ...p,
});

// Direcção-Geral (1, resp. Ana) › Finanças (2: Contabilista 2 vagas, Tesoureiro 1 vaga) e Comercial (3) › Vendas (4), Marketing (5)
const UNIDADES = [
  unidade(1, 'Direcção-Geral', null, { tipo: 'DIRECCAO_GERAL', colaborador_responsavel_id: 1, membros: 1, postos: [posto(10, 1, { titulo: 'Director-Geral', chefia: true, ocupados: 1, livres: 0 })] }),
  unidade(2, 'Finanças', 1, { membros: 3, disposicao: 'COLUNA', postos: [posto(20, 2, { titulo: 'Contabilista', vagas: 2, ocupados: 1, livres: 1 }), posto(21, 2, { titulo: 'Tesoureiro', vagas: 1, ocupados: 2, livres: 0 })] }),
  unidade(3, 'Comercial', 1),
  unidade(4, 'Vendas', 3),
  unidade(5, 'Marketing', 3),
];
const COLABS = [
  colab(1, 'Ana', 1, { posto_trabalho_id: 10, cargo_funcao_id: 7 }),
  colab(2, 'Bruno', 2, { posto_trabalho_id: 20, cargo_funcao_id: 8 }),
  colab(3, 'Carla', 2, { posto_trabalho_id: 21, colaborador_gestor_id: 2 }),
  colab(4, 'Dinis', 2, { posto_trabalho_id: 21 }),
  colab(5, 'Eva', 2),
  colab(6, 'Fora', 2, { estado: 'INACTIVO', posto_trabalho_id: 20 }),
];
const nomeCargo = (id: number | null) => (id === 7 ? 'Gestora' : id === 8 ? 'Técnico' : '—');

describe('estrutura orgânica — paridade com o antigo', () => {
  const arvore = construirArvore(UNIDADES);

  it('conta as vagas em aberto do ramo e usa os tipos do antigo', () => {
    expect(arvore[0].livresTotal).toBe(1);
    expect(arvore[0].membrosTotal).toBe(4);
    expect(tipoInfo('DIRECCAO_GERAL')).toEqual({ nome: 'Direcção-Geral / Administração', icone: 'crown', cor: '#1e3a8a' });
    expect(tipoInfo('XPTO').nome).toBe('Outra unidade');
    expect(TIPOS_UNIDADE.SECCAO).toBe('Secção / Serviço');
    expect(caminho(arvore, 4).map((n) => n.unidade.nome)).toEqual(['Direcção-Geral', 'Comercial', 'Vendas']);
    expect(caminho(arvore, 99)).toEqual([]);
  });

  it('colaboradores afectos: função, cargo, chefia directa (explícita ou responsável) e salário', () => {
    const fin = arvore[0].filhos.find((n) => n.unidade.id === 2)!;
    const sal = mapaSalarios({ periodo_salarial: '08/2026', por_colaborador: [{ colaborador_id: 2, valor: '150000.50' }] });
    const m = linhasMembros(fin, COLABS, nomeCargo, sal);
    expect(m.map((x) => x.nome)).toEqual(['Bruno', 'Carla', 'Dinis', 'Eva']); // o inactivo não aparece
    expect(m[0]).toMatchObject({ funcao: 'Técnico', cargo: 'Contabilista', chefia: '—', chefiaDeduzida: true, salario: 150000.5 });
    expect(m[1]).toMatchObject({ cargo: 'Tesoureiro', chefia: 'Bruno', chefiaDeduzida: false, salario: 0 });
    expect(m[3].cargo).toBeNull();
    // sem permissão de salários: null
    expect(linhasMembros(fin, COLABS, nomeCargo, null)[0].salario).toBeNull();
    // na unidade com responsável, os outros vêem «X (responsável)»; o próprio responsável não
    const dg = linhasMembros(arvore[0], [...COLABS, colab(9, 'Gil', 1)], nomeCargo, null);
    expect(dg.find((x) => x.nome === 'Ana')).toMatchObject({ responsavel: true, chefia: '—' });
    expect(dg.find((x) => x.nome === 'Gil')!.chefia).toBe('Ana (responsável)');
  });

  it('mapa de pessoal numa só tabela: uma linha por cargo, «(sem cargo atribuído)» e totais', () => {
    const sal = mapaSalarios({ periodo_salarial: null, por_colaborador: [{ colaborador_id: 3, valor: '100.10' }, { colaborador_id: 4, valor: '200.20' }, { colaborador_id: 5, valor: '50' }] });
    const l = linhasMapaLegado(arvore, COLABS, nomeCargo, sal);
    expect(l.map((x) => `${x.unidade}|${x.cargo}`)).toEqual(['Direcção-Geral|Director-Geral', 'Finanças|Contabilista', 'Finanças|Tesoureiro', 'Finanças|(sem cargo atribuído)']);
    expect(l[2]).toMatchObject({ vagas: 1, ocupados: 2, emAberto: 0, acima: 1, pessoas: ['Carla', 'Dinis'], massa: 300.3, caminho: 'Direcção-Geral › Finanças', nivel: 1 });
    expect(l[1]).toMatchObject({ vagas: 2, ocupados: 1, emAberto: 1, acima: 0, pessoas: ['Bruno'] });
    expect(l[3]).toMatchObject({ semCargo: true, vagas: 0, ocupados: 1, acima: 0, pessoas: ['Eva'], massa: 50 });
    expect(totaisMapaLegado(l)).toEqual({ vagas: 4, ocupados: 5, vagasOcupadas: 4, emAberto: 1, acima: 1, massa: 350.3 });
  });

  it('documento de impressão/Excel com indicadores, colunas do antigo e massa só com permissão', () => {
    const l = linhasMapaLegado(arvore, COLABS, nomeCargo, null);
    const sem = documentoMapaLegado(l, { activos: 5, semUnidade: 0, massaTotal: null }, false);
    expect(sem).toContain('Vagas previstas');
    expect(sem).toContain('<th>Pessoas</th>');
    expect(sem).not.toContain('Massa salarial');
    const com = documentoMapaLegado(l, { activos: 5, semUnidade: 0, massaTotal: 10 }, true, true);
    expect(com).toContain('Massa salarial mensal');
    expect(com).toContain('Direcção-Geral › Finanças');
    // nomes escapados (sem HTML injectado)
    const mau = linhasMapaLegado(construirArvore([unidade(1, '<img src=x onerror=alert(1)>', null, { postos: [posto(1, 1)] })]), [], nomeCargo, null);
    expect(documentoMapaLegado(mau, { activos: 0, semUnidade: 0, massaTotal: null }, false)).not.toContain('<img');
  });

  it('organigrama: empilhar últimos níveis, tudo lado a lado, recolher e zoom', () => {
    expect(idsEmpilharUltimos(arvore)).toEqual([3]); // Comercial: 2 subunidades sem mais níveis
    expect(idsEmColuna(arvore)).toEqual([2]);
    expect(idsRecolher(arvore)).toEqual([3]); // a raiz fica aberta
    expect(proximoZoom(1, 1)).toBe(1.15);
    expect(proximoZoom(1, -1)).toBe(0.85);
    expect(proximoZoom(0.4, -1)).toBe(0.35);
    expect(proximoZoom(1.55, 1)).toBe(1.6);
    expect(proximoZoom(0.5, 0)).toBe(1);
  });

  it('etiqueta de ocupação como no antigo', () => {
    expect(estadoVagas(0, 3)).toEqual({ texto: '3 sem limite', tipo: 'neutro' });
    expect(estadoVagas(2, 3)).toEqual({ texto: '3/2 · +1', tipo: 'exc' });
    expect(estadoVagas(3, 1)).toEqual({ texto: '1/3 · 2 em aberto', tipo: 'aberto' });
    expect(estadoVagas(2, 2)).toEqual({ texto: '2/2', tipo: 'ok' });
  });
});
