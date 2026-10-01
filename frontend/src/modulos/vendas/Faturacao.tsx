import { Segmented } from 'antd';
import { Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { ListaDocumentos } from './ListaDocumentos';
import { EmitirDocumento } from './EmitirDocumento';
import { DetalheDocumento } from './DetalheDocumento';
import { Recibos } from './recibos/Recibos';
import { FaturacaoEletronica } from './agt/FaturacaoEletronica';

const BASE = '/m/vendas/vendas_faturacao';

/** Separador activo a partir do caminho (documentos, recibos ou facturação electrónica). */
export function separadorFaturacao(caminho: string): 'documentos' | 'recibos' | 'agt' {
  const resto = caminho.startsWith(BASE) ? caminho.slice(BASE.length) : caminho;
  if (/^\/recibos(\/|$)/.test(resto)) return 'recibos';
  if (/^\/agt(\/|$)/.test(resto)) return 'agt';
  return 'documentos';
}

/**
 * Vendas › Facturação (ecrã vendas_faturacao): documentos comerciais (listagem, emissão, detalhe), recibos de clientes
 * e facturação electrónica AGT (estado do envio, configuração, séries, contas de vendas).
 */
export default function Faturacao() {
  const navegar = useNavigate();
  const { pathname } = useLocation();
  const { pode } = useSessao();
  const actual = separadorFaturacao(pathname);

  return (
    <>
      <Segmented
        style={{ marginBottom: 16 }}
        value={actual}
        onChange={(v) => navegar(v === 'documentos' ? BASE : `${BASE}/${v}`)}
        options={[
          { value: 'documentos', label: 'Documentos' },
          { value: 'recibos', label: 'Recibos' },
          ...(pode('vendas_faturacao_view', 'vendas_fe_config') ? [{ value: 'agt', label: 'Facturação electrónica' }] : []),
        ]}
      />
      <Routes>
        <Route index element={<ListaDocumentos />} />
        <Route path="novo" element={<EmitirDocumento />} />
        <Route path="recibos/*" element={<Recibos />} />
        <Route path="agt" element={<FaturacaoEletronica />} />
        <Route path=":id" element={<DetalheDocumento />} />
      </Routes>
    </>
  );
}
