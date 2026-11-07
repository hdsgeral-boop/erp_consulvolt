import type { Afectacao, EstadoReserva, VagaMapa } from './apiParque';

/** Porte do veículo/lugar: o veículo cabe se a sua ordem não for maior do que a do lugar (a mesma regra do servidor). */
export const PORTES: Readonly<Record<string, { rotulo: string; ordem: number }>> = {
  MOTA: { rotulo: 'Mota', ordem: 1 }, LIGEIRO: { rotulo: 'Ligeiro', ordem: 2 }, SUV: { rotulo: 'SUV / 4×4', ordem: 3 }, PESADO: { rotulo: 'Pesado / carrinha', ordem: 4 },
};

export const porteCabe = (vaga: string | null | undefined, veiculo: string | null | undefined): boolean => (PORTES[veiculo ?? '']?.ordem ?? 0) <= (PORTES[vaga ?? '']?.ordem ?? 9);

/** Maiúsculas, sem separadores; com 8 caracteres fica `LD-12-34-AB`. Matrículas estrangeiras ficam sem hífenes. */
export function normalizarMatricula(v: string | null | undefined): string {
  const s = (v ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '');
  return s.length === 8 ? `${s.slice(0, 2)}-${s.slice(2, 4)}-${s.slice(4, 6)}-${s.slice(6)}` : s;
}

/** Designação da vaga gerada: `G-101` (piso −1, 1.º lugar), `G-01` ao R/C. */
export const designacaoVaga = (prefixo: string, piso: number, k: number): string => `${prefixo}-${piso === 0 ? String(k).padStart(2, '0') : Math.abs(piso) * 100 + k}`;

/** Cor do lugar (tokens do legado), sempre acompanhada do texto do estado: laranja em obra/indisponível, roxo uso comum, azul ocupada, verde livre. */
export function aparenciaDaVaga(v: Pick<VagaMapa, 'estado' | 'arrendavel' | 'afectacao' | 'ocupada'>, afectacoes: { valor: string; rotulo: string }[] = []): { rotulo: string; cor: string; fundo: string } {
  if (v.estado === 'EM_OBRA') return { rotulo: 'Em obra', cor: '#b45309', fundo: '#fff7ed' };
  if (v.estado === 'INDISPONIVEL') return { rotulo: 'Indisponível', cor: '#b45309', fundo: '#fff7ed' };
  if (!v.arrendavel) return { rotulo: afectacoes.find((a) => a.valor === v.afectacao)?.rotulo ?? (v.afectacao === 'CARGA_DESCARGA' ? 'Cargas e descargas' : 'Visitas'), cor: '#6d28d9', fundo: '#f5f3ff' };
  if (v.ocupada) return { rotulo: 'Ocupada', cor: '#1d4ed8', fundo: '#eff6ff' };
  return { rotulo: 'Livre', cor: '#15803d', fundo: '#f0fdf4' };
}

export const COR_RESERVA: Readonly<Record<EstadoReserva, string>> = {
  PEDIDA: 'gold', APROVADA: 'blue', AGUARDA_PAGAMENTO: 'orange', CONFIRMADA: 'green', REALIZADA: 'default', FALTOU: 'red', CANCELADA: 'default', RECUSADA: 'red',
};

export const ROTULO_RESERVA: Readonly<Record<EstadoReserva, string>> = {
  PEDIDA: 'Pedida', APROVADA: 'Aprovada', AGUARDA_PAGAMENTO: 'A aguardar pagamento', CONFIRMADA: 'Confirmada', REALIZADA: 'Realizada', FALTOU: 'Não compareceu', CANCELADA: 'Cancelada', RECUSADA: 'Recusada',
};

export const ESTADOS_VIVOS: readonly EstadoReserva[] = ['PEDIDA', 'APROVADA', 'AGUARDA_PAGAMENTO', 'CONFIRMADA'];

export const minutos = (h: string | null | undefined): number | null => {
  const m = /^(\d{1,2}):(\d{2})$/.exec((h ?? '').trim());
  if (!m) return null;
  const hh = Number(m[1]);
  const mm = Number(m[2]);
  return hh > 24 || mm > 59 || (hh === 24 && mm > 0) ? null : hh * 60 + mm;
};

/** Duração em horas com uma casa decimal, arredondada aos 6 minutos (150 min = 2,5); null se inválida. */
export function horasEntre(inicio: string | null | undefined, fim: string | null | undefined): number | null {
  const a = minutos(inicio);
  const b = minutos(fim);
  if (a === null || b === null || b <= a) return null;
  return Math.floor((b - a) / 6 + 0.5) / 10;
}

const UNIDADES: Record<string, { rot: string; horas: number }> = { HORA: { rot: 'hora', horas: 1 }, PERIODO: { rot: 'período de 4 horas', horas: 4 }, DIA: { rot: 'dia', horas: 24 }, FIXO: { rot: 'valor fixo', horas: 0 } };

/** Pré-visualização do preço (a mesma fórmula do servidor, em cêntimos): preço × max(1, ⌈horas ÷ horas da unidade⌉); o fixo cobra uma vez. O servidor recalcula ao gravar. */
export function valorReserva(regime: string, preco: number, unidade: string, caucao: number, horas: number): { valor: number; caucao: number; unidades: number; detalhe: string } {
  if (regime !== 'RESERVA_PAGA') return { valor: 0, caucao: 0, unidades: 0, detalhe: 'Sem custo' };
  const u = UNIDADES[unidade] ?? UNIDADES.HORA;
  const unidades = u.horas > 0 ? Math.max(1, Math.ceil(Math.round((horas / u.horas) * 1e6) / 1e6)) : 1;
  const valor = Math.round(Math.round(preco * 100) * unidades) / 100;

  return { valor, caucao: Math.round(caucao * 100) / 100, unidades, detalhe: u.horas > 0 ? `${unidades} × ${u.rot} (${horas} h)` : 'valor fixo' };
}

/** Escala do cancelamento: com `cancelDias` de antecedência (ou mais) devolve tudo; depois, `cancelPct` % do recebido, ao cêntimo (meio para cima). */
export function reembolso(recebido: number, faltam: number, cancelDias: number, cancelPct: number): { pct: number; devolve: number; retem: number } {
  const pct = faltam >= Math.max(0, cancelDias) ? 100 : Math.min(100, Math.max(0, cancelPct));
  const devolve = Math.floor(Math.round(recebido * 100) * pct / 100 + 0.5 + 1e-9) / 100;

  return { pct, devolve, retem: Math.round((recebido - devolve) * 100) / 100 };
}

/** Dias de calendário de `a` até `b` (AAAA-MM-DD), pode ser negativo. */
export const diasEntre = (a: string, b: string): number => Math.round((Date.parse(`${b}T12:00:00Z`) - Date.parse(`${a}T12:00:00Z`)) / 86_400_000);

/** Abreviaturas de 3 letras dos dias de funcionamento (todos os dias → «todos os dias»). */
export function resumoDias(dias: number[], nomes: string[]): string {
  const ord = [...new Set(dias)].sort((a, b) => a - b);
  return ord.length === 7 ? 'todos os dias' : ord.map((d) => (nomes[d] ?? '').slice(0, 3)).join(', ');
}

/** Acções que o estado da reserva permite (o servidor tem a última palavra): botões da gaveta. */
export function accoesDaReserva(estado: EstadoReserva, regimePago: boolean, valor: number, facturada: boolean, interna: boolean, temCaucao: boolean): {
  aprovar: boolean; recusar: boolean; realizada: boolean; faltou: boolean; cancelar: boolean; editar: boolean; apagar: boolean; facturar: boolean; receber: boolean;
} {
  const viva = (ESTADOS_VIVOS as readonly string[]).includes(estado);

  return {
    aprovar: estado === 'PEDIDA', recusar: estado === 'PEDIDA', realizada: estado === 'APROVADA' || estado === 'CONFIRMADA', faltou: estado === 'APROVADA' || estado === 'CONFIRMADA', cancelar: viva,
    editar: viva && !facturada && !temCaucao, apagar: viva && !facturada && !temCaucao,
    facturar: viva && estado !== 'PEDIDA' && regimePago && valor > 0 && !facturada && !interna, receber: viva && estado !== 'PEDIDA' && regimePago,
  };
}

export const rotuloAfectacao = (a: Afectacao | string | null | undefined, lista: { valor: string; rotulo: string }[] = []): string =>
  lista.find((x) => x.valor === a)?.rotulo ?? ({ PERMANENTE: 'Afecta a uma fracção', AUTONOMA: 'Autónoma', VISITA: 'Visitas', CARGA_DESCARGA: 'Cargas e descargas' } as Record<string, string>)[a ?? ''] ?? '—';
