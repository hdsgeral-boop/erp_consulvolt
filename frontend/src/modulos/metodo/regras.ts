import type { DadosAssunto, EstadoAssunto, EstadoDeliberacao, ResumoAssunto, Responsavel, TipoResponsavel, Vista } from './tipos';

/** Rótulos e regras do Registo de Assunto 5W1H (js/modules/metodo/assuntos.js). O servidor valida sempre; isto só ajuda o ecrã. */

export const ESTADOS: Record<EstadoAssunto, string> = { RASCUNHO: 'Rascunho', EM_CURSO: 'Em curso', CONCLUIDO: 'Concluído', CANCELADO: 'Cancelado' };
export const CORES_ESTADO: Record<EstadoAssunto, string> = { RASCUNHO: 'default', EM_CURSO: 'blue', CONCLUIDO: 'green', CANCELADO: 'red' };
export const TIPOS_RESP: Record<TipoResponsavel, string> = { COLABORADOR: 'Colaborador interno', EXTERNO: 'Prestador de serviço externo', FORNECEDOR: 'Fornecedor' };
export const LOCAIS = { INTERNO: 'Interno (departamento)', EXTERNO: 'Externo (cliente/encomenda)' } as const;
export const METODOS = { ACCAO: 'Acção directa', PROJECTO: 'Projecto (Projectos e Obras)' } as const;
export const MODOS_PROJECTO = { NOVO: 'Criar um projecto novo', EXISTENTE: 'Usar um projecto já existente' } as const;
export const DELIB: Record<EstadoDeliberacao, string> = { PENDENTE: 'Aguarda aprovação', APROVADO: 'Aprovado', DEVOLVIDO: 'Devolvido para correcção', RECUSADO: 'Recusado' };
export const HORAS_AVISO = 48;

export const VISTAS: { chave: Vista; rotulo: string; icone: string }[] = [
  { chave: 'lista', rotulo: 'Todos', icone: 'list' },
  { chave: 'departamento', rotulo: 'Por departamento', icone: 'sitemap' },
  { chave: 'cliente', rotulo: 'Por cliente', icone: 'briefcase' },
  { chave: 'responsavel', rotulo: 'Por responsável', icone: 'user-check' },
  { chave: 'prazo', rotulo: 'Por prazo', icone: 'calendar-alt' },
  { chave: 'custo', rotulo: 'Por custo', icone: 'coins' },
];

/** Chave «TIPO:ref» do responsável (ou «TIPO:nome» sem referência) — chaveResp do legado. */
export function chaveResp(r: Pick<Responsavel, 'tipo' | 'ref_id' | 'nome'>): string {
  return `${r.tipo}:${r.ref_id != null && String(r.ref_id) !== '' ? r.ref_id : String(r.nome ?? '').trim()}`;
}

/** Horas entre duas datas AAAA-MM-DD (negativo se o fim é anterior). */
export function horasEntre(inicio: string, fim: string): number {
  return (Date.parse(`${fim}T00:00:00Z`) - Date.parse(`${inicio}T00:00:00Z`)) / 36e5;
}

/** Texto do aviso do prazo (WHEN) ou null. */
export function avisoPrazo(inicio?: string | null, fim?: string | null): { tipo: 'erro' | 'aviso' | 'info'; texto: string } | null {
  if (!inicio || !fim) return null;
  const h = horasEntre(inicio, fim);
  if (h < 0) return { tipo: 'erro', texto: 'A data de fim é anterior à de início.' };
  if (h < HORAS_AVISO) return { tipo: 'aviso', texto: `Prazo de menos de ${HORAS_AVISO} horas — confirme que é mesmo assim.` };
  return { tipo: 'info', texto: `Prazo de ${Math.round(h / 24)} dia(s).` };
}

/**
 * Validação do legado (validar, assuntos.js:60-98) para mostrar os problemas antes de enviar.
 * Em rascunho só o título conta.
 */
export function validar(d: DadosAssunto, rascunho: boolean): { erros: string[]; avisos: string[] } {
  const erros: string[] = [];
  const avisos: string[] = [];
  const titulo = (d.titulo ?? '').trim();
  if (!titulo) erros.push('WHAT: indique o título do assunto.');
  if (titulo.length > 120) erros.push('WHAT: o título não pode ter mais de 120 caracteres.');
  const resp = (d.responsaveis ?? []).filter((r) => r.nome?.trim());
  if (!resp.length) erros.push('WHO: indique pelo menos um responsável.');
  if (resp.length && !d.responsavel_principal) erros.push('WHO: escolha o responsável principal.');
  if (d.responsavel_principal && !resp.some((r) => chaveResp(r) === d.responsavel_principal)) erros.push('WHO: o responsável principal tem de estar na lista de responsáveis.');
  if (!d.data_inicio) erros.push('WHEN: indique a data de início.');
  if (!d.data_fim) erros.push('WHEN: indique a data de fim.');
  const prazo = avisoPrazo(d.data_inicio, d.data_fim);
  if (prazo?.tipo === 'erro') erros.push('WHEN: a data de fim é anterior à data de início.');
  if (prazo?.tipo === 'aviso') avisos.push(`WHEN: o prazo é de menos de ${HORAS_AVISO} horas. Confirme que é mesmo assim.`);
  if (d.local_tipo !== 'INTERNO' && d.local_tipo !== 'EXTERNO') erros.push('WHERE: escolha se o assunto é interno ou externo.');
  if (d.local_tipo === 'INTERNO' && !d.unidade_negocio_id) erros.push('WHERE: escolha a unidade de negócio (departamento).');
  if (d.local_tipo === 'EXTERNO' && !d.cliente_id) erros.push('WHERE: escolha o cliente.');
  const fund = (d.fundamentacao ?? '').trim().length;
  if (fund < 50) erros.push(`WHY: a fundamentação tem de ter pelo menos 50 caracteres (tem ${fund}).`);
  if (!(d.recursos ?? '').trim()) erros.push('HOW: descreva os recursos envolvidos.');
  if (!(Number(d.custo_estimado) > 0)) erros.push('HOW: indique o custo estimado.');
  if (d.metodo_resolucao === 'PROJECTO') {
    if (d.projeto_modo === 'EXISTENTE') {
      if (!d.projeto_id) erros.push('HOW: escolha o projecto existente que vai resolver este assunto.');
    } else if (d.local_tipo === 'INTERNO' && !d.centro_custo_id) erros.push('HOW: o projecto interno precisa de centro de custo.');
    else if (d.local_tipo === 'EXTERNO' && !d.encomenda_venda_id) erros.push('HOW: o projecto externo precisa da nota de encomenda (NE) do cliente.');
  }
  const temFornecedor = resp.some((r) => r.tipo === 'FORNECEDOR');
  if (d.gerar_pedido_compra) {
    if (!temFornecedor) erros.push('HOW: para gerar pedido de compra é preciso um fornecedor em WHO.');
    if (!(d.linhas_pedido_compra ?? []).some((l) => l.produto_id)) erros.push('HOW: indique pelo menos um artigo para o pedido de compra.');
  }
  if (temFornecedor && !d.gerar_pedido_compra) avisos.push('WHO: há um fornecedor indicado — normalmente isso implica gerar pedido de compra.');
  return { erros: rascunho ? erros.filter((e) => e.startsWith('WHAT')) : erros, avisos };
}

/** Linhas dos «Recursos» que passam a tarefas do projecto (gerarProjecto, :392-393). */
export function tarefasDosRecursos(recursos: string | null | undefined, titulo: string): string[] {
  const linhas = String(recursos ?? '').split(/\r?\n/).map((s) => s.trim().replace(/^[-•*]\s*/, '').trim()).filter(Boolean);
  return linhas.length ? linhas : [titulo.trim()].filter(Boolean);
}

/** Onde (WHERE) em texto: unidade de negócio ou cliente · encomenda. */
export function onde(a: Pick<ResumoAssunto, 'local_tipo' | 'unidade_negocio_nome' | 'cliente_nome' | 'encomenda'>): string {
  if (a.local_tipo === 'INTERNO') return a.unidade_negocio_nome ?? '—';
  if (a.local_tipo === 'EXTERNO') return `${a.cliente_nome ?? '—'}${a.encomenda ? ` · ${a.encomenda}` : ''}`;
  return '—';
}

/** Agrupamento das vistas por departamento / cliente / responsável (desenharVista, assuntos_ui.js:96-99). */
export function agrupar(lista: ResumoAssunto[], vista: Vista): { chave: string; itens: ResumoAssunto[]; custo: number }[] {
  const chaveDe: Partial<Record<Vista, (a: ResumoAssunto) => string>> = {
    departamento: (a) => (a.local_tipo === 'INTERNO' ? a.unidade_negocio_nome ?? '—' : '— externos —'),
    cliente: (a) => (a.local_tipo === 'EXTERNO' ? onde(a) : '— internos —'),
    responsavel: (a) => a.responsavel_principal_nome || '—',
    prazo: (a) => (a.data_fim ?? '').slice(0, 7),
  };
  const f = chaveDe[vista];
  if (!f) return [{ chave: '', itens: lista, custo: somaCusto(lista) }];
  const base = vista === 'prazo' ? lista.filter((a) => a.data_fim).sort((x, y) => String(x.data_fim).localeCompare(String(y.data_fim))) : lista;
  const grupos = new Map<string, ResumoAssunto[]>();
  base.forEach((a) => { const k = f(a); grupos.set(k, [...(grupos.get(k) ?? []), a]); });
  const ordenados = [...grupos.entries()].sort((a, b) => a[0].localeCompare(b[0], 'pt'));
  return ordenados.map(([chave, itens]) => ({ chave, itens, custo: somaCusto(itens) }));
}

export function somaCusto(lista: Pick<ResumoAssunto, 'custo_estimado'>[]): number {
  return Math.round(lista.reduce((s, a) => s + Number(a.custo_estimado ?? 0), 0) * 100) / 100;
}

/** Painel da vista «Por custo» (desenharCusto, :131-145). */
export function resumoCusto(lista: ResumoAssunto[]): { total: number; comprometido: number; rascunho: number; cancelado: number } {
  const soma = (f: (a: ResumoAssunto) => boolean) => somaCusto(lista.filter(f));
  return {
    total: somaCusto(lista),
    comprometido: soma((a) => a.estado === 'EM_CURSO' || a.estado === 'CONCLUIDO'),
    rascunho: soma((a) => a.estado === 'RASCUNHO'),
    cancelado: soma((a) => a.estado === 'CANCELADO'),
  };
}
