/**
 * Mapeamento contabilístico dos salários (GET/PUT /rh/mapeamentos-contabeis): grelha rubrica × (tipo de organização | avençado)
 * → conta. O servidor substitui cada célula enviada (apaga e volta a criar se tiver conta), por isso só se enviam as alteradas.
 */

export interface MapeamentoRubrica {
  id?: number;
  infotipo_salarial_id: number;
  tipo_organizacao_id: number | null;
  avencado: boolean;
  numero_conta: string | null;
}

export interface MapeamentoSistema {
  id?: number;
  codigo: string;
  tipo_organizacao_id: number | null;
  avencado: boolean;
  numero_conta: string | null;
}

/** Coluna da grelha: id do tipo de organização, ou 'AV' para avençados. */
export type Coluna = number | 'AV';

export function chaveCelula(linha: string | number, coluna: Coluna): string {
  return `${linha}|${coluna}`;
}

export function grelhaRubricas(lista: MapeamentoRubrica[]): Record<string, string> {
  return Object.fromEntries(lista.map((m) => [chaveCelula(m.infotipo_salarial_id, m.avencado ? 'AV' : (m.tipo_organizacao_id ?? 0)), m.numero_conta ?? '']));
}

export function grelhaSistema(lista: MapeamentoSistema[]): Record<string, string> {
  return Object.fromEntries(lista.map((m) => [chaveCelula(m.codigo, m.avencado ? 'AV' : (m.tipo_organizacao_id ?? 0)), m.numero_conta ?? '']));
}

function separar(chave: string): { linha: string; coluna: Coluna } {
  const i = chave.lastIndexOf('|');
  const c = chave.slice(i + 1);
  return { linha: chave.slice(0, i), coluna: c === 'AV' ? 'AV' : Number(c) };
}

/** Células alteradas entre o original e o editado (inclui as limpas, que o servidor apaga). */
export function alteracoes(original: Record<string, string>, editado: Record<string, string>): { linha: string; coluna: Coluna; numero_conta: string | null }[] {
  const chaves = new Set([...Object.keys(original), ...Object.keys(editado)]);
  const saida: { linha: string; coluna: Coluna; numero_conta: string | null }[] = [];
  for (const k of chaves) {
    const a = (original[k] ?? '').trim();
    const b = (editado[k] ?? '').trim();
    if (a !== b) saida.push({ ...separar(k), numero_conta: b || null });
  }
  return saida;
}

/**
 * Linhas do cartão «Mapeamento de Impostos e Encargos» (sysTaxes, js/app_v2.js:2462-2469): nome e natureza como no
 * legado; `avencado` = a coluna Avençado (Conta) é editável (só o salário líquido — os avençados não têm INSS e o IRT
 * avençado tem linha própria; nas outras aparece «—»). ROUNDING_DIFF não aparecia no ecrã antigo (vinha do assistente):
 * fica numa linha própria, sem tipo de organização.
 */
export const IMPOSTOS_SISTEMA: { codigo: string; nome: string; natureza: string; avencado: boolean; semTipo?: boolean }[] = [
  { codigo: 'NET_PAY_CREDIT', nome: 'Salário Líquido a Pagar', natureza: 'Passivo (CRÉDITO)', avencado: true },
  { codigo: 'IRT_CREDIT', nome: 'IRT Retido na Fonte (Tabela Progressiva)', natureza: 'Passivo (CRÉDITO)', avencado: false },
  { codigo: 'IRT_AVENCADO_CREDIT', nome: 'IRT Avençado (Taxa Fixa 6.5%)', natureza: 'Passivo (CRÉDITO)', avencado: false },
  { codigo: 'INSS_FUNC_CREDIT', nome: 'INSS Trabalhador (3%)', natureza: 'Passivo (CRÉDITO)', avencado: false },
  { codigo: 'INSS_EMP_DEBIT', nome: 'INSS Encargo Empresa (8%)', natureza: 'Gasto (DÉBITO)', avencado: false },
  { codigo: 'INSS_EMP_CREDIT', nome: 'INSS Passivo Empresa (8%)', natureza: 'Passivo (CRÉDITO)', avencado: false },
  { codigo: 'ROUNDING_DIFF', nome: 'Diferenças de Arredondamento', natureza: 'Ajuste (DÉBITO/CRÉDITO)', avencado: false, semTipo: true },
];

/** «N de M com conta» (nInfMapeados, js/app_v2.js:2460): linhas com pelo menos uma célula preenchida. */
export function contarComConta(linhas: (string | number)[], valores: Record<string, string>): number {
  const comConta = new Set(
    Object.entries(valores)
      .filter(([, v]) => (v ?? '').trim() !== '')
      .map(([k]) => k.slice(0, k.lastIndexOf('|'))),
  );
  return linhas.filter((l) => comConta.has(String(l))).length;
}

/** Corpo do PUT a partir das alterações das duas grelhas. */
export function corpoGravacao(
  rubricas: ReturnType<typeof alteracoes>,
  sistema: ReturnType<typeof alteracoes>,
): { rubricas: MapeamentoRubrica[]; sistema: MapeamentoSistema[] } {
  const org = (c: Coluna) => (c === 'AV' ? null : c === 0 ? null : c);
  return {
    rubricas: rubricas.map((a) => ({ infotipo_salarial_id: Number(a.linha), tipo_organizacao_id: org(a.coluna), avencado: a.coluna === 'AV', numero_conta: a.numero_conta })),
    sistema: sistema.map((a) => ({ codigo: a.linha, tipo_organizacao_id: org(a.coluna), avencado: a.coluna === 'AV', numero_conta: a.numero_conta })),
  };
}
