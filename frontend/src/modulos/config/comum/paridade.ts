/**
 * Regras puras da paridade das Configurações com o sistema anterior (verificação visual de 2026-10-08, secção 14).
 */

/** Coluna «Endereço sede» (renderEmpresas, js/app_v2.js:3636): `c.address || '-'`. */
export function enderecoSede(endereco: string | null | undefined): string {
  const t = (endereco ?? '').trim();
  return t === '' ? '-' : t;
}

/** «Usar Empresa» só para empresas activas a que o utilizador tem acesso (as da sessão). */
export function podeUsarEmpresa(e: { id: number; estado: string }, acessiveis: ReadonlySet<number>): boolean {
  return e.estado === 'ATIVO' && acessiveis.has(e.id);
}

/** Título do antigo: «Plano de Contas (N contas)» (renderPlanoContas, js/app_v2.js:8360). */
export function tituloPlanoContas(n: number | undefined): string {
  return n === undefined ? 'Plano de Contas' : `Plano de Contas (${new Intl.NumberFormat('pt-PT').format(n)} ${n === 1 ? 'conta' : 'contas'})`;
}

/** Ficheiros aceites em «Importar Excel» do plano de contas (o servidor repete a validação: tipo e 5 MB). */
export const MAX_IMPORTACAO_PLANO = 5 * 1024 * 1024;

export function validarFicheiroPlano(f: { name: string; size: number }): string | null {
  if (!/\.(xlsx|xls|csv)$/i.test(f.name)) return 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo «Baixar Template»).';
  if (f.size > MAX_IMPORTACAO_PLANO) return 'O ficheiro não pode ter mais de 5 MB.';
  return null;
}

/** Modelo de câmbios do antigo (descarregarModeloCambios, js/moedas.js:494): cabeçalhos e linhas de exemplo. */
export const MODELO_CAMBIOS: (string | number)[][] = [
  ['Data', 'Moeda', 'Taxa', 'Origem', 'Âmbito'],
  ['2026-09-01', 'USD', 912.5, 'BNA', 'TODAS'],
  ['2026-09-01', 'EUR', 1050.25, 'BNA', 'TODAS'],
  ['01/09/2026', 'ZAR', '51,20', 'BNA', 'EMPRESA'],
];
