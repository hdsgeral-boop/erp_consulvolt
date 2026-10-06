import { Segmented } from 'antd';
import { CloudUploadOutlined, FileDoneOutlined, FileTextOutlined, ShoppingCartOutlined, TruckOutlined, WalletOutlined } from '@ant-design/icons';
import { Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { ListaDocumentos } from './ListaDocumentos';
import { EmitirDocumento } from './EmitirDocumento';
import { DetalheDocumento } from './DetalheDocumento';
import { Recibos } from './recibos/Recibos';
import { FaturacaoEletronica } from './agt/FaturacaoEletronica';

const BASE = '/m/vendas/vendas_faturacao';

export type SeparadorFaturacao = 'orcamentos' | 'encomendas' | 'documentos' | 'guias' | 'recibos' | 'agt';

/** Separador activo a partir do caminho («documentos» = Facturas / N. crédito, o separador por omissão, como no legado). */
export function separadorFaturacao(caminho: string): SeparadorFaturacao {
  const resto = caminho.startsWith(BASE) ? caminho.slice(BASE.length) : caminho;
  const m = /^\/(recibos|agt|orcamentos|encomendas|guias)(\/|$)/.exec(resto);
  return (m?.[1] as SeparadorFaturacao | undefined) ?? 'documentos';
}

/**
 * Vendas › Facturação (ecrã vendas_faturacao), com os separadores do legado (renderFaturaçãoTab, js/ui_sales.js:648):
 * Orçamentos/Pró-formas, Encomendas, Facturas/N. crédito (por omissão), Guias — mais Recibos de clientes e
 * Facturação electrónica AGT.
 */
export default function Faturacao() {
  const navegar = useNavigate();
  const { pathname } = useLocation();
  const { pode } = useSessao();
  const actual = separadorFaturacao(pathname);

  return (
    <>
      <Segmented<SeparadorFaturacao>
        style={{ marginBottom: 16, maxWidth: '100%', overflowX: 'auto' }}
        value={actual}
        onChange={(v) => navegar(v === 'documentos' ? BASE : `${BASE}/${v}`)}
        options={[
          { value: 'orcamentos', label: 'Orç. / Pró-formas', icon: <FileTextOutlined /> },
          { value: 'encomendas', label: 'Encomendas', icon: <ShoppingCartOutlined /> },
          { value: 'documentos', label: 'Facturas / N. crédito', icon: <FileDoneOutlined /> },
          { value: 'guias', label: 'Guias', icon: <TruckOutlined /> },
          { value: 'recibos', label: 'Recibos', icon: <WalletOutlined /> },
          ...(pode('vendas_faturacao_view', 'vendas_fe_config') ? [{ value: 'agt' as const, label: 'Facturação electrónica', icon: <CloudUploadOutlined /> }] : []),
        ]}
      />
      <Routes>
        <Route index element={<ListaDocumentos grupo="faturas" />} />
        <Route path="orcamentos" element={<ListaDocumentos grupo="orcamentos" />} />
        <Route path="encomendas" element={<ListaDocumentos grupo="encomendas" />} />
        <Route path="guias" element={<ListaDocumentos grupo="guias" />} />
        <Route path="novo" element={<EmitirDocumento />} />
        <Route path="recibos/*" element={<Recibos />} />
        <Route path="agt" element={<FaturacaoEletronica />} />
        <Route path=":id" element={<DetalheDocumento />} />
      </Routes>
    </>
  );
}
