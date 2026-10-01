/** Lógica auxiliar das rotinas contabilísticas (sem dependências de UI, para testar). */

export interface LinhaActualizacao {
  lancamento_id: number;
  campo_a_modificar: string;
  novo_valor: string | null;
}

/** Campos que o servidor aceita na actualização em massa (nome português e alias do legado). */
export const CAMPOS_ACTUALIZAVEIS: { valor: string; rotulo: string }[] = [
  { valor: 'descricao', rotulo: 'Descrição' },
  { valor: 'codigo_conta', rotulo: 'Conta' },
  { valor: 'terceiro_id', rotulo: 'Terceiro (id)' },
  { valor: 'diario_id', rotulo: 'Diário (id)' },
  { valor: 'nota_demonstracao_id', rotulo: 'Nota DEMO (id)' },
  { valor: 'nota_fluxo_caixa_id', rotulo: 'Nota de fluxo (id)' },
];

/**
 * Lê linhas «id;campo;novo valor» coladas de uma folha de cálculo (separador «;», tabulação ou «,»).
 * Ignora linhas vazias e um cabeçalho (1.ª célula não numérica). Valor vazio = limpar o campo (null).
 */
export function lerLinhasActualizacao(texto: string): LinhaActualizacao[] {
  const linhas = texto.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const saida: LinhaActualizacao[] = [];
  for (const l of linhas) {
    const sep = l.includes('\t') ? '\t' : l.includes(';') ? ';' : ',';
    // só os dois primeiros separadores contam: o valor pode conter o separador (texto livre)
    const [id, campo, ...resto] = l.split(sep);
    const limpar = (c: string | undefined) => (c ?? '').trim().replace(/^"(.*)"$/, '$1');
    if (!/^\d+$/.test(limpar(id))) continue;
    const valor = limpar(resto.join(sep));
    saida.push({ lancamento_id: Number(limpar(id)), campo_a_modificar: limpar(campo), novo_valor: valor === '' ? null : valor });
  }
  return saida;
}
