/**
 * Criação de várias unidades de uma vez e estrutura base (legado: estGravarUnidades, estCriarBase,
 * js/modules/estrutura/estrutura_dados.js:253-310). Regras puras, testáveis.
 */
import type { Unidade } from './arvore';

export interface LinhaNovaUnidade {
  nome: string;
  codigo?: string;
  tipo?: string;
}

/** Estrutura de partida do legado (criarEstruturaBase): [chave, nome, tipo, chave do pai, código, ordem, apoio]. */
export const ESTRUTURA_BASE: [string, string, string, string | null, string, number, boolean][] = [
  ['ca', 'Conselho de Administração', 'ORGAO_SOCIAL', null, 'CA', 1, false],
  ['dg', 'Direcção-Geral', 'DIRECCAO_GERAL', 'ca', 'DG', 1, false],
  ['gjc', 'Gabinete Jurídico e de Compliance', 'GABINETE', 'dg', 'GJC', 1, true],
  ['daf', 'Direcção Administrativa e Financeira', 'DIRECCAO', 'dg', 'DAF', 2, false],
  ['dcont', 'Departamento de Contabilidade', 'DEPARTAMENTO', 'daf', 'DCONT', 1, false],
  ['dtes', 'Departamento de Tesouraria', 'DEPARTAMENTO', 'daf', 'DTES', 2, false],
  ['drh', 'Departamento de Recursos Humanos', 'DEPARTAMENTO', 'daf', 'DRH', 3, false],
  ['dcom', 'Direcção Comercial', 'DIRECCAO', 'dg', 'DCOM', 3, false],
  ['dven', 'Departamento de Vendas', 'DEPARTAMENTO', 'dcom', 'DVEN', 1, false],
  ['dcl', 'Departamento de Compras e Logística', 'DEPARTAMENTO', 'dcom', 'DCL', 2, false],
  ['dop', 'Direcção de Operações', 'DIRECCAO', 'dg', 'DOP', 4, false],
];

/** Texto colado (uma unidade por linha; «código<TAB>nome» opcional) → linhas. */
export function lerListaColada(texto: string): LinhaNovaUnidade[] {
  return texto
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => {
      const c = l.split('\t').map((x) => x.trim());
      return c.length > 1 ? { codigo: c[0], nome: c[1] } : { nome: c[0] };
    });
}

/**
 * Valida tudo antes de gravar (como o legado: tudo ou nada): nomes repetidos no mesmo nível (no ficheiro ou já existentes)
 * e códigos repetidos na empresa. Devolve a lista de erros (vazia = pode gravar).
 */
export function validarNovasUnidades(linhas: LinhaNovaUnidade[], paiId: number | null, existentes: Pick<Unidade, 'nome' | 'codigo' | 'unidade_organica_pai_id'>[]): string[] {
  const validas = linhas.filter((l) => l.nome.trim());
  if (!validas.length) return ['Indique pelo menos uma unidade.'];
  const erros: string[] = [];
  const irmaos = new Set(existentes.filter((u) => (u.unidade_organica_pai_id ?? null) === paiId).map((u) => u.nome.trim().toLowerCase()));
  const codigos = new Set(existentes.map((u) => (u.codigo ?? '').trim().toLowerCase()).filter(Boolean));
  const vistosN = new Set<string>();
  const vistosC = new Set<string>();
  validas.forEach((l, i) => {
    const n = l.nome.trim().toLowerCase();
    const c = (l.codigo ?? '').trim().toLowerCase();
    if (irmaos.has(n) || vistosN.has(n)) erros.push(`Linha ${i + 1}: já existe a unidade «${l.nome.trim()}» neste nível.`);
    if (c && (codigos.has(c) || vistosC.has(c))) erros.push(`Linha ${i + 1}: o código ${l.codigo?.trim()} já está a ser usado.`);
    vistosN.add(n);
    if (c) vistosC.add(c);
  });
  return erros;
}
