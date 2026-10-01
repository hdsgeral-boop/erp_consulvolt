import type { ItemPrestacao, Liquidacao, SessaoPOS, Terminal } from './tipos';

/**
 * Visibilidade das acções do POS: a mesma regra do servidor (permissão + estado), para só mostrar o que se pode fazer.
 * O servidor valida sempre; aqui evita-se oferecer acções que falhariam de certeza.
 */

export type Pode = (...chaves: string[]) => boolean;

export interface Accao {
  visivel: boolean;
  /** Motivo pelo qual a acção aparece desactivada (ex.: segregação de funções). */
  bloqueio?: string;
}

const nao: Accao = { visivel: false };
const sim: Accao = { visivel: true };

// ───────────── Frente de caixa ─────────────

export function accoesFrente(pode: Pode, terminal: Pick<Terminal, 'ativo' | 'sessao_aberta'> | null | undefined) {
  const aberta = !!terminal?.sessao_aberta;
  return {
    abrir: !!terminal?.ativo && !aberta && pode('pos_venda'),
    vender: aberta && pode('pos_venda'),
    desconto: pode('pos_desconto'),
    relatorioX: aberta && pode('pos_venda', 'pos_fecho'),
    fecharZ: aberta && pode('pos_fecho'),
  };
}

// ───────────── Integração e desvios ─────────────

export function accoesIntegracao(pode: Pode, s: Pick<SessaoPOS, 'estado' | 'estado_contabilizacao' | 'estado_liquidacao'>) {
  return {
    contabilizar: s.estado === 'FECHADA' && s.estado_contabilizacao === 'PENDENTE' && pode('pos_integrar'),
    descontabilizar: s.estado_contabilizacao === 'CONTABILIZADA' && pode('pos_descontabilizar'),
  };
}

export const DECISOES: Record<string, { rotulo: string; descricao: string }> = {
  SOBRA_PROVEITO: { rotulo: 'Sobra (proveito)', descricao: 'D transitória / C conta de sobras (6)' },
  FALTA_CUSTO: { rotulo: 'Falta (custo)', descricao: 'D conta de quebras (7) / C transitória' },
  FALTA_OPERADOR: { rotulo: 'Falta (responsabilidade do operador)', descricao: 'D conta do operador (3) / C transitória' },
  SEM_EFEITO: { rotulo: 'Sem efeito', descricao: 'Sem lançamento' },
};

/** Decisões possíveis para o desvio: sobra → proveito ou sem efeito; falta → custo, operador ou sem efeito. */
export function decisoesPermitidas(s: Pick<SessaoPOS, 'desvio' | 'estado_contabilizacao'>): { valor: string; desactivada?: string }[] {
  const sobra = Number(s.desvio ?? 0) > 0;
  const integrada = s.estado_contabilizacao === 'CONTABILIZADA' || s.estado_contabilizacao === 'SEM_MOVIMENTO';
  const comEfeito = sobra ? ['SOBRA_PROVEITO'] : ['FALTA_CUSTO', 'FALTA_OPERADOR'];
  return [
    ...comEfeito.map((valor) => ({ valor, desactivada: integrada ? undefined : 'Integre primeiro a sessão na contabilidade.' })),
    { valor: 'SEM_EFEITO' },
  ];
}

export function accoesDesvio(pode: Pode, s: Pick<SessaoPOS, 'estado_desvio' | 'deliberacao' | 'operador_id'>, utilizadorId: number | null | undefined) {
  const permitido = pode('pos_desvio_deliberar');
  const proprio = !!utilizadorId && !!s.operador_id && s.operador_id === utilizadorId;
  const segregacao = proprio ? 'Segregação de funções: quem operou a sessão não delibera o próprio desvio.' : undefined;
  return {
    deliberar: permitido && s.estado_desvio === 'PENDENTE' ? { ...sim, bloqueio: segregacao } : nao,
    anular: permitido && s.estado_desvio === 'DELIBERADO' && !!s.deliberacao && !s.deliberacao.automatica ? sim : nao,
  };
}

// ───────────── Prestação de contas ─────────────

export function accoesItemPrestacao(pode: Pode, item: Pick<ItemPrestacao, 'estado'>, operadorId: number | null | undefined, utilizadorId: number | null | undefined): Accao {
  if (!pode('pos_prestar') || item.estado !== 'POR_PRESTAR') return nao;
  if (utilizadorId && operadorId && utilizadorId === operadorId) return { visivel: true, bloqueio: 'Segregação de funções: quem operou a sessão não presta contas dela.' };
  return sim;
}

export function podeAnularLiquidacao(pode: Pode, l: Pick<Liquidacao, 'estado'>): boolean {
  return l.estado === 'REGISTADO' && pode('pos_prestacao_anular');
}

// ───────────── Terminais ─────────────

export function accoesTerminal(pode: Pode, t: Pick<Terminal, 'ativo' | 'sessao_aberta'>) {
  const gerir = pode('pos_terminais_gerir');
  return {
    editar: gerir,
    copiarMeios: gerir,
    activar: gerir && !t.ativo,
    desactivar: gerir && t.ativo ? { ...sim, bloqueio: t.sessao_aberta ? 'O terminal tem uma sessão aberta: feche-a antes de o desactivar.' : undefined } : nao,
    eliminar: gerir && !t.sessao_aberta,
  };
}

// ───────────── Lavandaria ─────────────

export interface ItemOrdemLav {
  linha_id: number;
  estado: string;
  estado_orcamento?: string | null;
  requer_orcamento?: boolean | null;
}

export function accoesOrdem(
  pode: Pode,
  o: { estado: string; itens?: ItemOrdemLav[] | null; saldo?: string | number | null; por_facturar?: string | number | null },
  sessaoAberta: boolean,
) {
  const itens = o.itens ?? [];
  const activa = o.estado !== 'ANULADA' && o.estado !== 'ENTREGUE';
  const ordens = pode('lav_ordens');
  return {
    iniciar: activa && ordens && itens.some((i) => i.estado === 'RECEBIDA'),
    pronta: activa && ordens && itens.some((i) => i.estado === 'EM_EXECUCAO'),
    orcamentos: activa && ordens && itens.some((i) => i.estado_orcamento === 'PENDENTE'),
    entregar: activa && ordens && sessaoAberta && itens.some((i) => i.estado === 'PRONTA'),
    receber: activa && pode('lav_receber') && sessaoAberta && Number(o.saldo ?? 0) > 0,
    faturar: activa && ordens && sessaoAberta && Number(o.por_facturar ?? 0) > 0,
    anular: activa && o.estado !== 'ENTREGA_PARCIAL' && pode('lav_anular'),
    atribuir: activa && ordens,
  };
}

export function accoesReclamacao(pode: Pode, r: { estado: string; decidido_por?: string | null }, nomeUtilizador: string | null | undefined) {
  const decidir = pode('lav_dano_decidir');
  const proprio = !!nomeUtilizador && r.decidido_por === nomeUtilizador;
  return {
    comprovar: decidir && r.estado === 'AGUARDA_COMPROVATIVO',
    decidir: decidir && (r.estado === 'AGUARDA_COMPROVATIVO' || r.estado === 'COMPROVADO'),
    pagar:
      pode('lav_dano_pagar') && r.estado === 'APROVADA'
        ? { ...sim, bloqueio: proprio ? 'Segregação de funções: quem decidiu a reclamação não a paga.' : undefined }
        : nao,
  };
}

// ───────────── Hotelaria ─────────────

export function accoesEstadia(pode: Pode, e: { estado: string; itens?: unknown[] | null }, sessaoAberta: boolean) {
  const aberta = e.estado === 'ABERTA';
  const comConsumos = (e.itens ?? []).length > 0;
  return {
    alterar: aberta && pode('hotel_estadias'),
    consumos: aberta && pode('hotel_estadias', 'pos_venda'),
    checkout: aberta && sessaoAberta && pode('hotel_checkout'),
    anular: aberta && pode('hotel_anular') ? { ...sim, bloqueio: comConsumos ? 'A estadia tem consumos: não pode ser anulada.' : undefined } : nao,
  };
}
