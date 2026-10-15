/**
 * Repartição por unidades de negócio (Fase C, ADR-071) — tipos e regras puras partilhadas pelos componentes.
 * Legado novo: js/modules/configuracoes/reparticao.js (ReparticaoUN) e alocacao_un.js (AlocacaoUN).
 *
 * O CRITÉRIO guarda só a forma de repartir; o OBJECTO guarda as unidades e, quando a forma o pede, o peso de cada uma:
 *     unidades_alocadas = { criterio_id, unidades: [{ unidade_negocio_id, peso }] }
 * Os cálculos com dinheiro (percentagens e parcelas) são feitos no servidor, em BCMath (POST /reparticao/simular).
 */

export type FormaReparticao = 'PERCENTAGEM' | 'PARTES_IGUAIS' | 'COLABORADORES' | 'VOLUME_NEGOCIOS' | 'AREA' | 'PERSONALIZADO';

export interface Forma {
  codigo: FormaReparticao;
  nome: string;
  rotulo_peso: string;
  automatico: boolean;
  sem_peso: boolean;
  ajuda: string;
  exemplo: string;
}

export interface CriterioReparticao {
  id: number;
  codigo: string;
  nome: string;
  criterio: FormaReparticao;
  indicador: string | null;
  periodo_de: string | null;
  periodo_ate: string | null;
  notas: string | null;
  ativo: boolean | null;
}

export interface UnidadeAlocada {
  unidade_negocio_id?: number | null;
  peso?: number | string | null;
}

export interface Alocacao {
  criterio_id?: number | null;
  unidades: UnidadeAlocada[];
}

/** Linha devolvida pelo servidor (pesos ou repartição de um valor). */
export interface LinhaReparticao {
  unidade_negocio_id: number;
  nome: string;
  indutor: string;
  percentagem: string;
  valor?: string;
}

export interface ResultadoSimulacao {
  alocacao: Alocacao;
  linhas: LinhaReparticao[];
  total: string | null;
}

/** As formas (espelho de ServicoReparticao::CRITERIOS) — usadas enquanto /reparticao/formas não responde e nos testes. */
export const FORMAS: Record<FormaReparticao, Omit<Forma, 'codigo'>> = {
  PERCENTAGEM: { nome: 'Percentagem fixa', rotulo_peso: 'Percentagem (%)', automatico: false, sem_peso: false,
    ajuda: 'Os pesos são escritos à mão. Use quando a repartição resulta de um acordo ou de uma decisão de gestão.', exemplo: 'Renda da sede repartida 60% / 40% entre duas unidades.' },
  PARTES_IGUAIS: { nome: 'Partes iguais', rotulo_peso: '—', automatico: true, sem_peso: true,
    ajuda: 'Divide por igual pelas unidades escolhidas. É o critério a usar quando não há indutor que se justifique.', exemplo: '3 unidades → 33,33% cada.' },
  COLABORADORES: { nome: 'Número de colaboradores', rotulo_peso: 'Colaboradores', automatico: true, sem_peso: false,
    ajuda: 'Peso pelo número de colaboradores afectos a cada unidade, lido das fichas. Indicado para custos de pessoal e de estrutura.', exemplo: 'Serviços de RH repartidos pelo peso de cada unidade no total do pessoal.' },
  VOLUME_NEGOCIOS: { nome: 'Volume de negócios', rotulo_peso: 'Vendas do período', automatico: true, sem_peso: false,
    ajuda: 'Peso pelas vendas de cada unidade no período indicado. Indicado para custos comerciais e administrativos.', exemplo: 'Publicidade institucional repartida pelo peso de cada unidade na facturação.' },
  AREA: { nome: 'Área ocupada (m²)', rotulo_peso: 'Área (m²)', automatico: false, sem_peso: false,
    ajuda: 'Peso pela área que cada unidade ocupa. É o critério clássico para renda, limpeza, energia e segurança.', exemplo: 'Renda de 1 000 m² repartida por 600 m² e 400 m².' },
  PERSONALIZADO: { nome: 'Indicador personalizado', rotulo_peso: 'Valor do indicador', automatico: false, sem_peso: false,
    ajuda: 'Qualquer outro indutor de custo: horas-máquina, nº de documentos, viaturas, pontos de venda. Escreva o nome do indicador e o valor de cada unidade.', exemplo: 'Manutenção repartida por horas-máquina de cada unidade.' },
};

const numero = (v: unknown): number => {
  if (v === null || v === undefined || v === '') return NaN;
  const n = typeof v === 'number' ? v : Number(String(v).replace(',', '.'));
  return Number.isFinite(n) ? n : NaN;
};

/** Sem critério ou sem unidades: o objecto usa a unidade do campo normal (ReparticaoUN.vazia). */
export function alocacaoVazia(a: Alocacao | null | undefined): boolean {
  return !a || !a.criterio_id || !(a.unidades ?? []).some((u) => u && u.unidade_negocio_id);
}

/**
 * Validação no cliente, igual à do servidor (ReparticaoUN.validarAlocacao): ≥ 2 unidades, sem repetidas, sem pesos
 * negativos e, nas formas manuais, pelo menos um peso > 0. Devolve a mensagem ou null.
 */
export function validarAlocacao(a: Alocacao | null | undefined, forma: FormaReparticao | null | undefined): string | null {
  if (!a || !a.criterio_id) return null;
  const f = FORMAS[forma ?? 'PERCENTAGEM'] ?? FORMAS.PERCENTAGEM;
  const us = (a.unidades ?? []).filter((u) => u && u.unidade_negocio_id);
  if (us.length < 2) return 'Aloque pelo menos duas unidades de negócio.';
  const ids = us.map((u) => String(u.unidade_negocio_id));
  if (ids.some((v, i) => ids.indexOf(v) !== i)) return 'A mesma unidade está alocada mais do que uma vez.';
  if (us.some((u) => numero(u.peso) < 0)) return 'Os valores não podem ser negativos.';
  if (!f.automatico && !us.some((u) => numero(u.peso) > 0)) return `Indique ${f.rotulo_peso.toLowerCase()} em pelo menos uma unidade.`;
  return null;
}

/** Corpo a enviar ao servidor: sem critério → null (sem repartição); linhas vazias retiradas; nos automáticos sem peso. */
export function alocacaoParaApi(a: Alocacao | null | undefined, forma?: FormaReparticao | null): Alocacao | null {
  if (alocacaoVazia(a)) return null;
  const automatico = forma ? FORMAS[forma]?.automatico : false;
  return {
    criterio_id: Number(a!.criterio_id),
    unidades: a!.unidades
      .filter((u) => u && u.unidade_negocio_id)
      .map((u) => ({ unidade_negocio_id: Number(u.unidade_negocio_id), peso: automatico || u.peso === '' || u.peso == null ? null : numero(u.peso) })),
  };
}

/** Alocação gravada (o legado usa `business_unit_id`) → valor do componente. */
export function alocacaoDeApi(v: unknown): Alocacao | null {
  if (typeof v === 'string') {   // vendas.unidades_alocadas é texto JSON no esquema do legado
    try {
      v = JSON.parse(v);
    } catch {
      return null;
    }
  }
  if (!v || typeof v !== 'object') return null;
  const o = v as { criterio_id?: unknown; unidades?: unknown };
  const unidades = Array.isArray(o.unidades)
    ? o.unidades.map((u) => {
        const x = (u ?? {}) as { unidade_negocio_id?: unknown; business_unit_id?: unknown; peso?: unknown };
        const id = Number(x.unidade_negocio_id ?? x.business_unit_id);
        return { unidade_negocio_id: Number.isFinite(id) && id > 0 ? id : null, peso: x.peso == null || x.peso === '' ? null : numero(x.peso) };
      })
    : [];
  const criterio = Number(o.criterio_id);
  return { criterio_id: Number.isFinite(criterio) && criterio > 0 ? criterio : null, unidades };
}

/** Linha de lançamento/tesouraria que se pode repartir. */
export interface LinhaRepartivel {
  valor?: number | string | null;
  descricao?: string | null;
  unidade_negocio_id?: number | null;
}

/**
 * «Repartir linha» (ui_lancamentos.js:1495, ui_tesouraria.js:1113): a linha é substituída por uma por unidade, com a
 * parcela calculada no servidor e a descrição «xx,xx% <unidade>». O total não muda, por isso o lançamento continua
 * equilibrado. As parcelas a zero não geram linha.
 */
export function linhasRepartidas<T extends LinhaRepartivel>(linha: T, resultado: { linhas: LinhaReparticao[] }): T[] {
  const base = String(linha.descricao ?? '').trim();
  return resultado.linhas
    .filter((l) => numero(l.valor) !== 0 && !Number.isNaN(numero(l.valor)))
    .map((l) => ({
      ...linha,
      unidade_negocio_id: l.unidade_negocio_id,
      valor: numero(l.valor),
      descricao: `${base ? `${base} · ` : ''}${numero(l.percentagem).toFixed(2).replace('.', ',')}% ${l.nome}`.slice(0, 1000),
    }));
}

/** Soma em cêntimos (inteiros) — para confirmar que as partes batem com o valor da linha. */
export function somaCentimos(valores: (number | string | null | undefined)[]): number {
  return valores.reduce<number>((s, v) => s + Math.round((Number.isNaN(numero(v)) ? 0 : numero(v)) * 100), 0);
}
