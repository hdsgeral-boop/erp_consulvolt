/**
 * Regras e transformações do módulo RH (puras, testadas em regras.test.ts). Servem só para mostrar e para decidir que
 * botões aparecem: quem valida é sempre o servidor (ServicoFolhaSalarial, ServicoPortalColaborador, etc.).
 */
import type {
  ChaveTotal, ContratoTrabalho, EtapaPedido, ItemAvaliacao, PedidoPortal, PeriodoSalarial, RemuneracaoContratoBruta, ResultadoSalarial, RubricaResultado,
} from '../api';

type Pode = (...chaves: string[]) => boolean;

// ───────────── Períodos salariais ─────────────

export interface AccoesPeriodo {
  lancar: boolean;
  removerLancamento: boolean;
  importar: boolean;
  encerrar: boolean;
  validar: boolean;
  reabrir: boolean;
  contabilizar: boolean;
  descontabilizar: boolean;
  emitirCarta: boolean;
  recibos: boolean;
}

/** Acções possíveis num período, pelo estado (ABERTO → FECHADO → VALIDADO) e pelas permissões (FolhaSalarialController). */
export function accoesPeriodo(p: Pick<PeriodoSalarial, 'estado' | 'contabilizado'>, pode: Pode): AccoesPeriodo {
  const aberto = p.estado === 'ABERTO';
  const contabilizado = Boolean(p.contabilizado);
  return {
    lancar: aberto && pode('calcular_lancar', 'calcular_bulk'),
    removerLancamento: aberto && pode('rh_lanc_del'),
    importar: aberto && pode('calcular_folha'),
    encerrar: aberto && pode('calcular_folha'),
    validar: p.estado === 'FECHADO' && pode('processamento_validate'),
    reabrir: (p.estado === 'FECHADO' || p.estado === 'VALIDADO') && !contabilizado && pode('processamento_reopen'),
    contabilizar: p.estado === 'VALIDADO' && !contabilizado && pode('processamento_integrate'),
    descontabilizar: contabilizado && pode('contab_lanc_del'),
    emitirCarta: p.estado === 'VALIDADO' && pode('processamento_integrate'),
    recibos: p.estado === 'VALIDADO',
  };
}

/** 'MM/AAAA' → chave ordenável 'AAAAMM'. */
export function chaveMesAno(mesAno: string): string {
  const [m, a] = mesAno.split('/');
  return `${a ?? ''}${(m ?? '').padStart(2, '0')}`;
}

/** 'MM/AAAA' → 'AAAA-MM' (formato da assiduidade e da produtividade). */
export function mesAnoParaMes(mesAno: string): string {
  const [m, a] = mesAno.split('/');
  return `${a}-${m}`;
}

/** 'AAAA-MM' → 'MM/AAAA'. */
export function mesParaMesAno(mes: string): string {
  const [a, m] = mes.split('-');
  return `${m}/${a}`;
}

const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** 'MM/AAAA' ou 'AAAA-MM' → «Setembro de 2026». */
export function mesPorExtenso(valor: string | null | undefined): string {
  if (!valor) return '—';
  const [m, a] = valor.includes('/') ? valor.split('/') : valor.split('-').reverse();
  const nome = MESES[Number(m) - 1];
  return nome ? `${nome} de ${a}` : valor;
}

/** N.º do recibo como no servidor: AAAAMM-NNNN (colaborador com 4 dígitos). */
export function numeroRecibo(mesAno: string, colaboradorId: number): string {
  const [m, a] = mesAno.split('/');
  return `${a}${m}-${String(colaboradorId).padStart(4, '0')}`;
}

// ───────────── Somas em Kz (cêntimos inteiros, sem erros de vírgula flutuante) ─────────────

export function paraCentimos(valor: string | number | null | undefined): number {
  if (valor === null || valor === undefined || valor === '') return 0;
  const n = typeof valor === 'number' ? valor : Number(valor);
  return Number.isFinite(n) ? Math.round(n * 100) : 0;
}

export function deCentimos(c: number): string {
  return (c / 100).toFixed(2);
}

export function somar(valores: (string | number | null | undefined)[]): string {
  return deCentimos(valores.reduce<number>((s, v) => s + paraCentimos(v), 0));
}

export const CHAVES_TOTAIS: ChaveTotal[] = ['bruto', 'inss_trabalhador', 'inss_patronal', 'irt', 'descontos', 'liquido'];

export function totaisResultados(lista: Pick<ResultadoSalarial, ChaveTotal>[]): Record<ChaveTotal, string> {
  return Object.fromEntries(CHAVES_TOTAIS.map((k) => [k, somar(lista.map((r) => r[k]))])) as Record<ChaveTotal, string>;
}

// ───────────── Mapa de remunerações (colunas por rubrica) ─────────────

export interface ColunaRubrica {
  chave: string;
  nome: string;
  tipo: RubricaResultado['tipo'];
}

/** Colunas do mapa: uma por rubrica presente, primeiro os vencimentos e depois os descontos (as informativas ficam de fora). */
export function colunasRubricas(resultados: Pick<ResultadoSalarial, 'rubricas'>[]): ColunaRubrica[] {
  const mapa = new Map<string, ColunaRubrica>();
  for (const r of resultados) {
    for (const x of r.rubricas ?? []) {
      if (x.informativa || x.tipo === 'OUTROS') continue;
      const chave = `${x.tipo}:${x.infotipo_id}:${x.nome}`;
      if (!mapa.has(chave)) mapa.set(chave, { chave, nome: x.nome, tipo: x.tipo });
    }
  }
  const ordem = (t: string) => (t === 'VENCIMENTO' ? 0 : 1);
  return [...mapa.values()].sort((a, b) => ordem(a.tipo) - ordem(b.tipo) || a.nome.localeCompare(b.nome, 'pt'));
}

/** Valor de cada coluna para um colaborador (a mesma rubrica pode aparecer mais de uma vez: soma-se). */
export function valoresRubricas(r: Pick<ResultadoSalarial, 'rubricas'>): Record<string, string> {
  const acc: Record<string, number> = {};
  for (const x of r.rubricas ?? []) {
    const chave = `${x.tipo}:${x.infotipo_id}:${x.nome}`;
    acc[chave] = (acc[chave] ?? 0) + paraCentimos(x.valor);
  }
  return Object.fromEntries(Object.entries(acc).map(([k, v]) => [k, deCentimos(v)]));
}

// ───────────── Contratos ─────────────

export interface RemuneracaoContrato {
  infotipo_salarial_id: number;
  valor_mes: number;
}

/** Lê as remunerações nos dois formatos (português e legado) e devolve o formato de envio da API. */
export function normalizarRemuneracoes(lista: RemuneracaoContratoBruta[] | null | undefined, dias = 22): RemuneracaoContrato[] {
  return (lista ?? [])
    .map((r) => {
      const id = Number(r.infotipo_id ?? r.infotype_id);
      const mes = r.valor_mes ?? r.value_month;
      const dia = r.valor_dia ?? r.value_per_day;
      const valor = mes !== undefined && mes !== null && mes !== '' ? Number(mes) : Number(dia ?? 0) * dias;
      return { infotipo_salarial_id: id, valor_mes: Math.round(valor * 100) / 100 };
    })
    .filter((r) => Number.isFinite(r.infotipo_salarial_id) && r.infotipo_salarial_id > 0);
}

export function totalContrato(c: Pick<ContratoTrabalho, 'remuneracoes' | 'dias_contrato_mes'>): string {
  return somar(normalizarRemuneracoes(c.remuneracoes, c.dias_contrato_mes ?? 22).map((r) => r.valor_mes));
}

/** O contrato está vigente na data? (data de fim vazia ou 9999-12-31 = sem fim) */
export function contratoVigente(c: Pick<ContratoTrabalho, 'data_inicio' | 'data_fim' | 'estado'>, hoje: string): boolean {
  if (c.estado && c.estado !== 'ACTIVO') return false;
  if (c.data_inicio && c.data_inicio.slice(0, 10) > hoje) return false;
  return !c.data_fim || c.data_fim.slice(0, 10) >= hoje;
}

export function semFim(data: string | null | undefined): boolean {
  return !data || data.startsWith('9999-12-31');
}

// ───────────── Coordenadas bancárias ─────────────

/** IBAN em grupos de 4 («AO06 0040 …») para leitura; aceita espaços na entrada. */
export function formatarIban(iban: string | null | undefined): string {
  if (!iban) return '—';
  return iban.replace(/\s+/g, '').toUpperCase().replace(/(.{4})/g, '$1 ').trim();
}

/** Verificação local (ISO 13616, mod 97) para avisar antes de enviar; o servidor valida e converte o NIB de 21 dígitos. */
export function ibanValido(iban: string): boolean {
  const s = iban.replace(/\s+/g, '').toUpperCase();
  if (/^\d{21}$/.test(s)) return true;   // NIB: o servidor converte para AO06
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(s)) return false;
  if (s.startsWith('AO') && s.length !== 25) return false;
  const rearranjado = s.slice(4) + s.slice(0, 4);
  let resto = 0;
  for (const ch of rearranjado) {
    const v = /[A-Z]/.test(ch) ? String(ch.charCodeAt(0) - 55) : ch;
    for (const d of v) resto = (resto * 10 + Number(d)) % 97;
  }
  return resto === 1;
}

// ───────────── Portal ─────────────

export function etapaPendente(p: Pick<PedidoPortal, 'etapas'>): EtapaPedido | null {
  return (p.etapas ?? []).find((e) => e.estado === 'PENDENTE') ?? null;
}

export function pedidoPendente(p: Pick<PedidoPortal, 'estado'>): boolean {
  return p.estado.startsWith('PENDENTE');
}

/** Acções do RH num pedido do portal (o servidor volta a validar etapa, segregação e auto-aprovação). */
export function accoesPedidoRh(p: Pick<PedidoPortal, 'estado' | 'tipo' | 'etapas'>, pode: Pode): { decidir: boolean; emitir: boolean } {
  const etapa = etapaPendente(p);
  const naEtapaRh = pedidoPendente(p) && etapa?.nivel === 'RH' && pode('rh_portal_aprovar');
  return { decidir: naEtapaRh && p.tipo !== 'DOCUMENTO', emitir: naEtapaRh && p.tipo === 'DOCUMENTO' };
}

// ───────────── Assiduidade ─────────────

/** Horas decimais → «7h30». */
export function formatarHoras(h: string | number | null | undefined): string {
  if (h === null || h === undefined || h === '') return '—';
  const n = Number(h);
  if (!Number.isFinite(n)) return String(h);
  const sinal = n < 0 ? '−' : '';
  const total = Math.round(Math.abs(n) * 60);
  const horas = Math.floor(total / 60);
  const min = total % 60;
  return `${sinal}${horas}h${min ? String(min).padStart(2, '0') : ''}`;
}

/** Horas entre entrada e saída (HH:MM), atravessando a meia-noite se a saída for menor. */
export function horasEntre(entrada: string | null | undefined, saida: string | null | undefined): number | null {
  if (!entrada || !saida) return null;
  const m = (t: string) => {
    const [h, mi] = t.split(':').map(Number);
    return h * 60 + (mi || 0);
  };
  let d = m(saida) - m(entrada);
  if (d < 0) d += 24 * 60;
  return Math.round((d / 60) * 100) / 100;
}

// ───────────── Exportação ─────────────

/** CSV com «;» (Excel pt) e aspas quando necessário. */
export function gerarCsv(cabecalho: string[], linhas: (string | number | null | undefined)[][]): string {
  const celula = (v: string | number | null | undefined) => {
    const s = v === null || v === undefined ? '' : String(v);
    return /[;"\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  return [cabecalho, ...linhas].map((l) => l.map(celula).join(';')).join('\r\n');
}

/** Valor em Kz para CSV (vírgula decimal, sem separador de milhares). */
export function kzCsv(valor: string | number | null | undefined): string {
  return deCentimos(paraCentimos(valor)).replace('.', ',');
}

// ───────────── Avaliação ─────────────

/** Itens activos que se aplicam ao colaborador: os comuns e os específicos dele, pela ordem (como ServicoAvaliacao::itensDe). */
export function itensAplicaveis(itens: ItemAvaliacao[], colaboradorId: number, tipo: ItemAvaliacao['tipo']): ItemAvaliacao[] {
  return itens
    .filter((i) => i.ativo && i.tipo === tipo && (i.ambito === 'COMUM' || i.colaborador_id === colaboradorId))
    .sort((a, b) => (a.ordem ?? 0) - (b.ordem ?? 0) || a.id - b.id);
}

/** Classificação pela nota de 1 a 5 (ADR-041): Excelente ≥ 4,5 · Muito Bom ≥ 3,5 · Bom ≥ 2,5 · Suficiente ≥ 1,5. */
export function classificar(nota: number | string | null | undefined): string | null {
  if (nota === null || nota === undefined || nota === '') return null;
  const n = Number(nota);
  if (!Number.isFinite(n)) return null;
  if (n >= 4.5) return 'Excelente';
  if (n >= 3.5) return 'Muito Bom';
  if (n >= 2.5) return 'Bom';
  if (n >= 1.5) return 'Suficiente';
  return 'Insuficiente';
}

/** Soma dos pesos 360º (tem de dar 100 e a chefia tem de ter peso). */
export function validarPesos360(pesos: Partial<Record<string, number | null | undefined>>): string | null {
  const total = Object.values(pesos).reduce<number>((s, v) => s + Number(v ?? 0), 0);
  if (Math.round(total * 100) !== 10000) return `Os pesos somam ${total} (têm de somar 100).`;
  if (!Number(pesos.CHEFIA ?? 0)) return 'A chefia tem de ter peso.';
  return null;
}
