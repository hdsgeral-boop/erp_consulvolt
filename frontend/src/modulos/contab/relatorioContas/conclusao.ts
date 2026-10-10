/**
 * «Concluir» / «Reabrir» do Relatório e Contas (legado: js/relatorio_contas.js:987-1047 e 1170-1194).
 *
 * No legado, enquanto o relatório está em rascunho o topo mostra «Actualizar números», «Gravar» e «Concluir» (este
 * sempre visível; com o exercício em aberto tem a dica «Disponível após o encerramento do exercício» e, ao clicar,
 * avisa que só se concluem exercícios encerrados). Concluído: esses três desaparecem, aparece «Reabrir» (vermelho)
 * e o subtítulo mostra «Concluído» + «concluído em AAAA-MM-DD por utilizador»; textos, notas e configuração ficam
 * só de leitura. O servidor faz as mesmas verificações (permissões rc_concluir / rc_reabrir, exercício encerrado,
 * gravação recusada com o relatório concluído).
 */

type Pode = (...chaves: string[]) => boolean;

export interface RegistoConclusao {
  estado?: string | null;
  concluido_em?: string | null;
  concluido_por?: string | null;
}

export interface EstadoConclusao {
  concluido: boolean;
  /** Campos, textos e notas editáveis (e «Actualizar números» / «Gravar» visíveis). */
  podeEditar: boolean;
  /** Botão «Concluir» visível (rascunho + permissão), como no legado mesmo com o exercício em aberto. */
  mostrarConcluir: boolean;
  /** O exercício está encerrado: a conclusão pode avançar; senão o clique só mostra o aviso do legado. */
  concluirDisponivel: boolean;
  podeReabrir: boolean;
  /** «concluído em AAAA-MM-DD por utilizador» (vazio enquanto em rascunho). */
  legenda: string;
}

export function estadoConclusao(reg: RegistoConclusao | undefined, exercicioEncerrado: boolean, pode: Pode): EstadoConclusao {
  const concluido = reg?.estado === 'APROVADO';
  return {
    concluido,
    podeEditar: !concluido && pode('rc_editar'),
    mostrarConcluir: !concluido && pode('rc_concluir'),
    concluirDisponivel: exercicioEncerrado,
    podeReabrir: concluido && pode('rc_reabrir'),
    legenda: concluido ? legendaConclusao(reg?.concluido_em, reg?.concluido_por) : '',
  };
}

/** Como o legado: data (10 primeiros caracteres do ISO) e, se houver, o utilizador. */
export function legendaConclusao(em: string | null | undefined, por: string | null | undefined): string {
  if (!em) return '';
  return `concluído em ${String(em).substring(0, 10)}${por ? ` por ${por}` : ''}`;
}

/** Mensagens do legado (rcConcluir / rcReabrir). */
export const textosConclusao = {
  dicaIndisponivel: 'Disponível após o encerramento do exercício',
  exercicioAberto: (ano: number) =>
    `O exercício de ${ano} ainda não está encerrado. Só é possível concluir o Relatório e Contas de exercícios encerrados. Pode imprimir esta versão como PROVISÓRIO.`,
  comErros: 'O relatório tem erros (ver alertas). Concluir mesmo assim?',
  confirmarTitulo: (ano: number) => `Concluir o Relatório e Contas de ${ano}?`,
  confirmarTexto: 'Os números e textos ficam bloqueados. Pode reabrir mais tarde, se necessário.',
  reabrirTitulo: (ano: number) => `Reabrir o Relatório e Contas de ${ano}?`,
  reabrirTexto: 'Os números serão recalculados a partir da contabilidade actual.',
} as const;

/** Há alertas de erro: o legado pede uma confirmação extra antes da normal. */
export function temErros(alertas: readonly { tipo: string }[] | undefined): boolean {
  return (alertas ?? []).some((a) => a.tipo === 'erro');
}
