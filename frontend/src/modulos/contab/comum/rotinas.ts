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
  /** N.º da linha no Excel (o importador envia-o, para os erros apontarem a linha do ficheiro). */
  linha?: number;
  lancamento_id: number | string | null;
  campo_a_modificar: string | null;
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
  { valor: 'unidade_negocio_id', rotulo: 'Unidade de negócio (id)' },
  { valor: 'centro_custo_id', rotulo: 'Centro de custo (id)' },
];

/** Linha da pré-visualização (valor actual → novo valor) devolvida pelo servidor. */
export interface LinhaPrevia {
  linha: number;
  lancamento_id: number | null;
  numero_lan: string | null;
  codigo_conta: string | null;
  campo: string;
  anterior: string | null;
  novo: string | null;
  estado: 'OK' | 'SEM_ALTERACAO' | 'ERRO';
  motivo: string | null;
}

/** Resposta de POST /contabilidade/rotinas/actualizacao-massa (e /importar, que traz também as `linhas` lidas). */
export interface ResultadoActualizacao {
  codigo: string | null;
  actualizadas: number;
  validas: number;
  erros: { linha: number; lancamento_id: number | null; motivo: string }[];
  previa: LinhaPrevia[];
  simulacao: boolean;
  avisos: string[];
  linhas?: LinhaActualizacao[];
}

/** Contagens da pré-visualização/resultado. */
export function resumoActualizacao(r: Pick<ResultadoActualizacao, 'previa' | 'erros' | 'validas'>) {
  return {
    validas: r.validas,
    erros: r.erros.length,
    alterar: r.previa.filter((p) => p.estado === 'OK').length,
    semAlteracao: r.previa.filter((p) => p.estado === 'SEM_ALTERACAO').length,
  };
}

const escapar = (v: unknown) =>
  String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string);

/** Documento (HTML) do relatório para imprimir/PDF/Excel: a pré-visualização completa, ou só os erros depois de aplicar. */
export function documentoActualizacao(r: ResultadoActualizacao): string {
  if (!r.simulacao && r.previa.length === 0) {
    const linhas = r.erros.map((e) => `<tr><td>${e.linha}</td><td>${escapar(e.lancamento_id)}</td><td>${escapar(e.motivo)}</td></tr>`).join('');
    return `<table><thead><tr><th>Linha</th><th>Lançamento</th><th>Motivo</th></tr></thead><tbody>${linhas || '<tr><td colspan="3">Sem erros.</td></tr>'}</tbody></table>`;
  }
  const rotulo = new Map(CAMPOS_ACTUALIZAVEIS.map((c) => [c.valor, c.rotulo]));
  const estado = { OK: 'Alterar', SEM_ALTERACAO: 'Sem alteração', ERRO: 'Erro' } as const;
  const linhas = r.previa
    .map(
      (p) =>
        `<tr><td>${p.linha}</td><td>${escapar(p.lancamento_id)}</td><td>${escapar(p.numero_lan)}</td><td>${escapar(p.codigo_conta)}</td><td>${escapar(rotulo.get(p.campo) ?? p.campo)}</td><td>${escapar(p.anterior ?? '(vazio)')}</td><td>${escapar(p.novo ?? '(vazio)')}</td><td>${estado[p.estado]}</td><td>${escapar(p.motivo)}</td></tr>`,
    )
    .join('');
  return `<table><thead><tr><th>Linha</th><th>ID</th><th>Nº Lanç.</th><th>Conta</th><th>Campo</th><th>Valor actual</th><th>Novo valor</th><th>Estado</th><th>Motivo</th></tr></thead><tbody>${linhas}</tbody></table>`;
}

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
