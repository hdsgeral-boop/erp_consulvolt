import { useQuery } from '@tanstack/react-query';
import { useLocation, useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';

const BASE = '/m/vendas';
const FATURACAO = `${BASE}/vendas_faturacao`;

/** Separadores do agregador «Gestão de Vendas», pela ordem, rótulos e ícones do legado (renderVendas, js/ui_sales.js:94-100). */
export const SEPARADORES_VENDAS = [
  { chave: 'dashboard', rotulo: 'Dashboard', icone: 'chart-line', destino: `${BASE}/vendas_relatorios`, permissao: 'vendas_relatorios_view' },
  { chave: 'clientes', rotulo: 'Clientes', icone: 'users', destino: `${BASE}/vendas_clientes`, permissao: 'vendas_clientes_view' },
  { chave: 'categorias', rotulo: 'Categorias', icone: 'tags', destino: `${BASE}/vendas_produtos?separador=categorias`, permissao: 'vendas_produtos_view' },
  { chave: 'produtos', rotulo: 'Produtos/Serviços', icone: 'box', destino: `${BASE}/vendas_produtos`, permissao: 'vendas_produtos_view' },
  { chave: 'faturacao', rotulo: 'Faturação', icone: 'file-invoice-dollar', destino: FATURACAO, permissao: 'vendas_faturacao_view' },
  { chave: 'saft', rotulo: 'Exportar SAFT-AO', icone: 'file-code', destino: `${BASE}/vendas_relatorios/saft`, permissao: ['vendas_relatorios_view', 'vendas_fe_config'] },
  // Fase A (vendas_series.js): séries FT/FR/NC com número inicial — chaves novas vendas_series_view / vendas_series_gerir no catálogo
  { chave: 'series', rotulo: 'Séries', icone: 'hashtag', destino: `${BASE}/vendas_series`, permissao: ['vendas_series_view', 'vendas_series_gerir'] },
  { chave: 'fe', rotulo: 'Facturação electrónica', icone: 'stamp', destino: `${FATURACAO}/agt`, permissao: ['vendas_faturacao_view', 'vendas_fe_config'] },
] as const;

/** Subseparadores da Faturação (renderFaturaçãoTab, js/ui_sales.js:648-653) — mais Recibos de clientes, no fim. */
export const SUBSEPARADORES_FATURACAO = [
  { chave: 'orcamentos', rotulo: 'Orçamentos', icone: 'file-alt', destino: `${FATURACAO}/orcamentos` },
  { chave: 'encomendas', rotulo: 'Encomendas', icone: 'shopping-cart', destino: `${FATURACAO}/encomendas` },
  { chave: 'faturas', rotulo: 'Faturas / NC', icone: 'file-invoice-dollar', destino: FATURACAO },
  { chave: 'guias', rotulo: 'Guias', icone: 'truck', destino: `${FATURACAO}/guias` },
  { chave: 'recibos', rotulo: 'Recibos', icone: 'receipt', destino: `${FATURACAO}/recibos` },
] as const;

export type SeparadorVendas = (typeof SEPARADORES_VENDAS)[number]['chave'];

/** Separador activo do agregador a partir do caminho e da query (?separador=categorias). */
export function separadorVendas(caminho: string, pesquisa = ''): SeparadorVendas | null {
  if (caminho.startsWith(`${BASE}/vendas_relatorios/saft`)) return 'saft';
  if (caminho.startsWith(`${BASE}/vendas_relatorios`)) return 'dashboard';
  if (caminho.startsWith(`${FATURACAO}/agt`)) return 'fe';
  if (caminho.startsWith(`${BASE}/vendas_series`) || caminho.startsWith(`${FATURACAO}/series`)) return 'series';
  if (caminho.startsWith(FATURACAO)) return 'faturacao';
  if (caminho.startsWith(`${BASE}/vendas_produtos`)) return new URLSearchParams(pesquisa).get('separador') === 'categorias' ? 'categorias' : 'produtos';
  if (caminho.startsWith(`${BASE}/vendas_clientes`)) return 'clientes';
  return null;
}

/** Subseparador activo da Faturação (nulo no formulário, no detalhe e na facturação electrónica). */
export function subseparadorFaturacao(caminho: string): (typeof SUBSEPARADORES_FATURACAO)[number]['chave'] | null {
  if (!caminho.startsWith(FATURACAO)) return null;
  const resto = caminho.slice(FATURACAO.length);
  if (resto === '' || resto === '/') return 'faturas';
  const m = /^\/(orcamentos|encomendas|guias|recibos)(\/|$)/.exec(resto);
  return (m?.[1] as 'orcamentos' | 'encomendas' | 'guias' | 'recibos' | undefined) ?? null;
}

export interface IndicadoresVendas {
  pendentes: number;
  a_receber: string;
  mensal: string;
}

/**
 * Cabeçalho comum de todos os ecrãs de Vendas, como o agregador do legado: «🛒 Gestão de Vendas», «Administre clientes,
 * produtos e todo o ciclo de facturação.», à direita «● Pendentes: N», «A receber (saldo)» e «📈 Mensal: X Kz», e os
 * separadores-botão. Na Faturação mostra os subseparadores (com o n.º de documentos de cada um).
 */
export function BarraVendas() {
  const navegar = useNavigate();
  const { pathname, search } = useLocation();
  const { pode } = useSessao();
  const activo = separadorVendas(pathname, search);
  const naFaturacao = activo === 'faturacao';
  const sub = subseparadorFaturacao(pathname);

  const indicadores = useQuery({
    queryKey: ['vendas', 'cabecalho'],
    queryFn: () => obter<IndicadoresVendas>('/vendas/cabecalho'),
    enabled: pode('vendas_relatorios_view', 'vendas_faturacao_view', 'vendas_clientes_view', 'vendas_produtos_view'),
    staleTime: 60_000,
    retry: false,
  });
  const i = indicadores.data;

  return (
    <CabecalhoModulo
      titulo="Gestão de Vendas"
      icone="shopping-cart"
      subtitulo="Administre clientes, produtos e todo o ciclo de faturação."
      activo={activo ?? undefined}
      indicadores={i ? [
        { chave: 'pendentes', rotulo: 'Pendentes', valor: i.pendentes, icone: 'clock', cor: '#f59e0b', dica: 'Facturas e facturas-recibo por receber' },
        { chave: 'receber', rotulo: 'A receber (saldo)', valor: `${formatarKz(i.a_receber)} Kz`, icone: 'hand-holding-usd', cor: '#f59e0b', dica: 'Saldo por receber das facturas pendentes (já descontados recibos parciais e notas de crédito)' },
        { chave: 'mensal', rotulo: 'Mensal', valor: `${formatarKz(i.mensal)} Kz`, icone: 'chart-bar', cor: '#10b981', dica: 'Facturado no mês corrente (facturas e facturas-recibo, com IVA)' },
      ] : undefined}
      separadores={SEPARADORES_VENDAS.map((s) => ({ ...s, permissao: [...(Array.isArray(s.permissao) ? s.permissao : [s.permissao])] }))}
      // sub-separadores na mesma linha, à direita, sem contadores — como o legado (renderFaturaçãoTab)
      separadoresDireita={
        naFaturacao &&
        sub &&
        SUBSEPARADORES_FATURACAO.map((s) => {
          const eActivo = sub === s.chave;
          return (
            <a
              key={s.chave}
              href={s.destino}
              className={eActivo ? 'erp-separador-botao activo' : 'erp-separador-botao'}
              aria-current={eActivo ? 'page' : undefined}
              onClick={(ev) => {
                if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button !== 0) return;
                ev.preventDefault();
                navegar(s.destino);
              }}
            >
              <span className="erp-separador-icone"><IconeFa nome={s.icone} /></span>
              <span>{s.rotulo}</span>
            </a>
          );
        })
      }
    />
  );
}
