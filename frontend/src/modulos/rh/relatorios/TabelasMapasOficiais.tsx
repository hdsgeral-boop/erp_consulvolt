import { TabelaComModos } from '@/componentes/vistas';
import type { ColunaLegado } from '@/componentes/vistas/colunasTabela';
import { formatarKz } from '@/utilitarios/formatacao';
import { type LinhaInss, type LinhaIrtOficial, rotuloPercentagem } from './estruturaMapas';

const kz = (v: string | null | undefined) => formatarKz(v ?? 0, true);
const textoOrdem = (k: string) => (a: Record<string, unknown>, b: Record<string, unknown>) => String(a[k] ?? '').localeCompare(String(b[k] ?? ''), 'pt');

interface Contexto {
  empresa?: string;
  nif?: string | null;
  periodo?: string;
  /** Percentagens do INSS da configuração da empresa. */
  taxas?: { trabalhador: number; patronal: number };
}

/** Colunas do mapa de INSS: COLABORADOR, Nº INSS, SAL. BASE, SUBS. ADIC., TOTAL BASE, TRAB. (x%), EMPRESA (y%), TOTAL ((x+y)%). */
export function colunasInss(taxas?: Contexto['taxas']): ColunaLegado<LinhaInss>[] {
  const soma = taxas ? Math.round((taxas.trabalhador + taxas.patronal) * 100) / 100 : undefined;
  const dinheiro = (titulo: string, k: keyof LinhaInss, extra: Partial<ColunaLegado<LinhaInss>> = {}): ColunaLegado<LinhaInss> => ({
    title: titulo, dataIndex: k, key: k, align: 'right', render: (v: string) => kz(v), total: true, formatoTotal: (n: number) => kz(String(n)), sorter: (a, b) => Number(a[k]) - Number(b[k]), ...extra,
  });
  return [
    { title: 'Colaborador', dataIndex: 'colaborador', key: 'colaborador', sorter: textoOrdem('colaborador') as never, defaultSortOrder: 'ascend' },
    { title: 'Nº INSS', dataIndex: 'numero_inss', key: 'numero_inss', sorter: textoOrdem('numero_inss') as never },
    dinheiro('Sal. Base', 'sal_base'),
    dinheiro('Subs. Adic.', 'subs_adic'),
    dinheiro('Total Base', 'total_base', { render: (v: string) => <strong>{kz(v)}</strong> }),
    dinheiro(`Trab.${rotuloPercentagem(taxas?.trabalhador)}`, 'trab', { render: (v: string) => <span style={{ color: '#dc2626' }}>{kz(v)}</span> }),
    dinheiro(`Empresa${rotuloPercentagem(taxas?.patronal)}`, 'empresa', { render: (v: string) => <span style={{ color: '#2563eb' }}>{kz(v)}</span> }),
    dinheiro(`Total${rotuloPercentagem(soma)}`, 'total', { render: (v: string) => <strong>{kz(v)}</strong> }),
  ];
}

/** Colunas do Mapa Fiscal de IRT (Modelo Oficial — Angola); o IRT vem do cálculo do servidor (escalões da tabela de IRT da empresa). */
export function colunasIrtOficial(): ColunaLegado<LinhaIrtOficial>[] {
  const dinheiro = (titulo: string, k: keyof LinhaIrtOficial, extra: Partial<ColunaLegado<LinhaIrtOficial>> = {}): ColunaLegado<LinhaIrtOficial> => ({
    title: titulo, dataIndex: k, key: k, align: 'right', render: (v: string | null) => (v === null ? '—' : kz(v)), total: true, formatoTotal: (n: number) => kz(String(n)),
    sorter: (a, b) => Number(a[k] ?? 0) - Number(b[k] ?? 0), ...extra,
  });
  const texto = (titulo: string, k: keyof LinhaIrtOficial, extra: Partial<ColunaLegado<LinhaIrtOficial>> = {}): ColunaLegado<LinhaIrtOficial> => ({
    title: titulo, dataIndex: k, key: k, sorter: textoOrdem(k) as never, ...extra,
  });
  return [
    { title: 'Ord.', dataIndex: 'ord', key: 'ord', align: 'right', sorter: (a, b) => a.ord - b.ord, defaultSortOrder: 'ascend' },
    texto('NIF', 'nif'), texto('Nº SS', 'numero_ss'), texto('Nome Completo', 'nome'), texto('Província', 'provincia'), texto('Município', 'municipio'), texto('Período', 'periodo', { align: 'center' }),
    dinheiro('H. Extras', 'horas_extras'), dinheiro('S. Alim.', 'subs_alimentacao'), dinheiro('S. Transp.', 'subs_transporte'), dinheiro('S. Férias', 'subs_ferias'),
    dinheiro('Sal. Base', 'sal_base'), dinheiro('Subsídio de Comunicação', 'subs_comunicacao'), dinheiro('Outros Subsídios', 'outros'),
    dinheiro('Total Bruto', 'total_bruto', { render: (v: string) => <strong>{kz(v)}</strong> }),
    dinheiro('INSS Trab.', 'inss_trabalhador'), dinheiro('Isenções', 'isencoes'), dinheiro('Matéria Colectável', 'materia_colectavel'),
    dinheiro('Parcela Fixa', 'parcela_fixa', { total: undefined }),
    { title: 'Taxa', dataIndex: 'taxa', key: 'taxa', align: 'right', render: (v: number | null) => (v === null ? '—' : `${String(v).replace('.', ',')}%`) },
    dinheiro('Excesso', 'excesso', { total: undefined }), dinheiro('Imposto Devido', 'imposto_devido'),
    dinheiro('IRT Retido', 'irt_retido', { render: (v: string) => <strong>{kz(v)}</strong> }),
  ];
}

/** Mapa de INSS na estrutura do modelo: tabela com filtros, «Colunas», linha TOTAL e Excel · PDF · Imprimir com as mesmas colunas e totais. */
export function TabelaInss({ linhas, contexto }: { linhas: LinhaInss[]; contexto: Contexto }) {
  return (
    <TabelaComModos<LinhaInss> idVista="mapa-inss" rowKey="id" size="small" dataSource={linhas} columns={colunasInss(contexto.taxas)} pagination={false} paginacaoLegado={false} modos={false}
      impressao={{ titulo: 'Mapa de INSS (Segurança Social)', subtitulo: [contexto.empresa, contexto.nif ? `NIF ${contexto.nif}` : null].filter(Boolean).join(' · ') || undefined, periodo: contexto.periodo,
        orientacao: 'paisagem', nomeFicheiro: `mapa-inss-${(contexto.periodo ?? '').replace('/', '-')}` }} />
  );
}

/** Mapa Fiscal de IRT (Modelo Oficial — Angola) na estrutura do modelo. */
export function TabelaIrtOficial({ linhas, contexto }: { linhas: LinhaIrtOficial[]; contexto: Contexto }) {
  return (
    <TabelaComModos<LinhaIrtOficial> idVista="mapa-irt" rowKey="id" size="small" dataSource={linhas} columns={colunasIrtOficial()} pagination={false} paginacaoLegado={false} modos={false}
      scroll={{ x: 'max-content' }}
      impressao={{ titulo: 'Mapa Fiscal de IRT (Modelo Oficial - Angola)', subtitulo: [contexto.empresa, contexto.nif ? `NIF ${contexto.nif}` : null].filter(Boolean).join(' · ') || undefined,
        periodo: contexto.periodo, orientacao: 'paisagem', nomeFicheiro: `mapa-irt-${(contexto.periodo ?? '').replace('/', '-')}` }} />
  );
}
