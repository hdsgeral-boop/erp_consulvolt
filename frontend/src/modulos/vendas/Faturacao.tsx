import { Route, Routes } from 'react-router-dom';
import { ListaDocumentos } from './ListaDocumentos';
import { EmitirDocumento } from './EmitirDocumento';
import { DetalheDocumento } from './DetalheDocumento';
import { Recibos } from './recibos/Recibos';
import { FaturacaoEletronica } from './agt/FaturacaoEletronica';
import SeriesNumeracao from './series/SeriesNumeracao';

const BASE = '/m/vendas/vendas_faturacao';

export type SeparadorFaturacao = 'orcamentos' | 'encomendas' | 'documentos' | 'guias' | 'recibos' | 'agt' | 'series';

/** Separador activo a partir do caminho («documentos» = Facturas / N. crédito, o separador por omissão, como no legado). */
export function separadorFaturacao(caminho: string): SeparadorFaturacao {
  const resto = caminho.startsWith(BASE) ? caminho.slice(BASE.length) : caminho;
  const m = /^\/(recibos|agt|series|orcamentos|encomendas|guias)(\/|$)/.exec(resto);
  return (m?.[1] as SeparadorFaturacao | undefined) ?? 'documentos';
}

/**
 * Vendas › Faturação (ecrã vendas_faturacao). Os subseparadores do legado (Orçamentos, Encomendas, Faturas / NC, Guias —
 * mais Recibos) estão no cabeçalho «Gestão de Vendas» (BarraVendas), com o n.º de documentos de cada um; a Facturação
 * electrónica é um separador principal do agregador, como no legado.
 */
export default function Faturacao() {
  return (
    <Routes>
      <Route index element={<ListaDocumentos grupo="faturas" />} />
      <Route path="orcamentos" element={<ListaDocumentos grupo="orcamentos" />} />
      <Route path="encomendas" element={<ListaDocumentos grupo="encomendas" />} />
      <Route path="guias" element={<ListaDocumentos grupo="guias" />} />
      <Route path="novo" element={<EmitirDocumento />} />
      <Route path="recibos/*" element={<Recibos />} />
      <Route path="agt" element={<FaturacaoEletronica />} />
      {/* Fase A: Vendas › Séries de numeração (separador próprio no agregador; ecrã vendas_series quando existir no catálogo) */}
      <Route path="series" element={<SeriesNumeracao />} />
      <Route path=":id" element={<DetalheDocumento />} />
    </Routes>
  );
}
