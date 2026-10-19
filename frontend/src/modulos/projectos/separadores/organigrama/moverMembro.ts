import type { Membro, Organigrama } from '../../comum/tipos';

/**
 * Ronda 4 — arrastar membros entre posições do organigrama (legado: ligarArrasto + projOrgAlocar,
 * projectos_organigrama.js:354-367 e 923-942). Regras puras do ecrã: onde está o membro, como fica o destino
 * (pré-visualização das vagas), as opções do menu «Mover para…» e a actualização optimista do organigrama.
 * A validação que conta é a do servidor (POST /projetos/{p}/organigrama/mover).
 */

/** Destino de um movimento: uma posição ou `null` = «Equipa sem posição». */
export type Destino = number | null;

/** Pedido de POST /projetos/{p}/organigrama/mover. */
export interface PedidoMoverMembro {
  membro_id: number;
  no_destino_id: Destino;
  no_origem_id: Destino;
  confirmar_excesso?: boolean;
  repor_responsavel?: boolean;
}

/** Resposta do servidor (o «Desfazer» usa `de` e `era_responsavel`). */
export interface ResultadoMoverMembro {
  membro_id: number;
  de: Destino;
  para: Destino;
  era_responsavel: boolean;
  alterado: boolean;
}

/** Onde está o membro (posição ou sem posição) e os seus dados. */
export function localizarMembro(o: Organigrama, membroId: number): { membro: Membro; de: Destino } | null {
  for (const p of o.posicoes) {
    const m = p.membros.find((x) => x.id === membroId);
    if (m) return { membro: m, de: p.id };
  }
  const m = o.sem_posicao.find((x) => x.id === membroId);
  return m ? { membro: m, de: null } : null;
}

export interface PrevisaoDestino {
  /** Texto curto mostrado sobre o destino enquanto se arrasta. */
  texto: string;
  /** O destino ficaria acima das vagas (pede confirmação, como no legado). */
  excede: boolean;
  /** Largar aqui não muda nada (é a posição actual). */
  mesmo: boolean;
  /** Ocupantes e vagas do destino depois do movimento. */
  ficaria: number;
  vagas: number;
}

/** Como fica o destino se o membro for largado lá (vagas = 0/nulo → sem limite). */
export function previsaoDestino(o: Organigrama, membroId: number, para: Destino): PrevisaoDestino | null {
  const origem = localizarMembro(o, membroId);
  if (!origem) return null;
  if (origem.de === para) return { texto: para === null ? 'Já está sem posição' : 'Já está nesta posição', excede: false, mesmo: true, ficaria: 0, vagas: 0 };
  if (para === null) return { texto: `Retirar «${origem.membro.nome}» da posição`, excede: false, mesmo: false, ficaria: o.sem_posicao.length + 1, vagas: 0 };
  const destino = o.posicoes.find((p) => p.id === para);
  if (!destino) return null;
  const ficaria = destino.membros.filter((m) => m.id !== membroId).length + 1;
  const vagas = Number(destino.vagas ?? 0);
  const excede = vagas > 0 && ficaria > vagas;
  const ocupacao = vagas > 0 ? `${ficaria}/${vagas} vaga(s)${excede ? ' — excede' : ''}` : `${ficaria} membro(s)`;
  return { texto: `Mover «${origem.membro.nome}» para «${destino.titulo}» · ficará com ${ocupacao}`, excede, mesmo: false, ficaria, vagas };
}

/** Opções do menu «Mover para…» (teclado): todas as posições menos a actual e, se tiver posição, «Sem posição». */
export function opcoesDestino(o: Organigrama, de: Destino): { valor: Destino; rotulo: string }[] {
  const ops: { valor: Destino; rotulo: string }[] = o.posicoes.filter((p) => p.id !== de).map((p) => {
    const vagas = Number(p.vagas ?? 0);
    return { valor: p.id, rotulo: `${p.titulo}${p.area ? ` · ${p.area}` : ''} (${vagas > 0 ? `${p.membros.length}/${vagas}` : p.membros.length})` };
  });
  if (de !== null) ops.push({ valor: null, rotulo: 'Equipa sem posição' });
  return ops;
}

/** Chave de menu de um destino (`null` → «sem») e o inverso. */
export const chaveDestino = (d: Destino): string => (d === null ? 'sem' : String(d));
export const destinoDaChave = (k: string): Destino => (k === 'sem' ? null : Number(k));

/**
 * Organigrama depois do movimento (actualização optimista enquanto o servidor responde): o membro sai da origem e entra no
 * destino; sair de uma posição retira-lhe a responsabilidade; `reporResponsavel` volta a pô-lo como responsável (Desfazer).
 */
export function aplicarMovimento(o: Organigrama, membroId: number, para: Destino, reporResponsavel = false): Organigrama {
  const origem = localizarMembro(o, membroId);
  if (!origem || origem.de === para) return o;
  const membro: Membro = { ...origem.membro, no_organigrama_projeto_id: para };
  const posicoes = o.posicoes.map((p) => {
    if (p.id === origem.de) {
      return { ...p, membros: p.membros.filter((m) => m.id !== membroId), membro_responsavel_id: p.membro_responsavel_id === membroId ? null : p.membro_responsavel_id };
    }
    if (p.id === para) {
      return { ...p, membros: [...p.membros, membro], membro_responsavel_id: reporResponsavel && !p.membro_responsavel_id ? membroId : p.membro_responsavel_id };
    }
    return p;
  });
  const semPosicao = para === null ? [...o.sem_posicao, membro] : o.sem_posicao.filter((m) => m.id !== membroId);
  const alocados = o.totais.alocados + (origem.de === null ? 1 : 0) - (para === null ? 1 : 0);
  return { ...o, posicoes, sem_posicao: semPosicao, totais: { ...o.totais, alocados } };
}

/** Tipo MIME próprio do arrasto (outros arrastos — ficheiros, texto — não são largados como membros). */
export const MIME_MEMBRO = 'application/x-erp-membro';
