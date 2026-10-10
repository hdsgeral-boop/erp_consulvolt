/** Lógica auxiliar das rotinas contabilísticas (sem dependências de UI, para testar). */

/** Separadores das rotinas (SECCOES_ROTINAS, js/ui_rotinas.js:5-13): ordem, nomes, ícones e dicas do legado. */
export const SECCOES_ROTINAS: { chave: string; nome: string; icone: string; dica: string }[] = [
  { chave: 'capitalizacao', nome: 'Capitalização de Obras', icone: 'hard-hat', dica: 'Custos de obras internas (65) para activos (1)' },
  { chave: 'compensacao', nome: 'Compensação Automática', icone: 'magic', dica: 'Pares débito/crédito com o mesmo documento e valor' },
  { chave: 'transferencia', nome: 'Transferência de Saldos', icone: 'exchange-alt', dica: 'Saldos de várias contas para contas de destino' },
  { chave: 'selo', nome: 'Imposto de Selo', icone: 'stamp', dica: 'Apuramento mensal do Imposto de Selo (1%)' },
  { chave: 'actualizacao', nome: 'Actualização em Massa', icone: 'file-import', dica: 'Alterar campos de lançamentos a partir de Excel' },
  { chave: 'limpeza', nome: 'Limpeza de Reconciliações', icone: 'broom', dica: 'Falsas reconciliações de tesouraria (com aprovação)' },
  { chave: 'historico', nome: 'Histórico', icone: 'history', dica: 'Rotinas executadas e anulação' },
];

/**
 * Separadores visíveis e o activo (js/ui_rotinas.js:59-62): rotinas com contab_rotinas_*, Imposto de Selo com
 * imposto_selo_view/selo_lancar; o activo é o pedido (rota imposto_selo → «selo»), senão o guardado, senão o 1.º.
 */
export function seccoesRotinas(podeRotinas: boolean, podeSelo: boolean, pedida?: string | null, guardada?: string | null) {
  const seccoes = SECCOES_ROTINAS.filter((s) => (s.chave === 'selo' ? podeSelo : podeRotinas));
  const escolha = [pedida, guardada].find((k) => k && seccoes.some((s) => s.chave === k));
  return { seccoes, activa: escolha ?? seccoes[0]?.chave ?? null };
}

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
