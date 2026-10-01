import { Route, Routes } from 'react-router-dom';
import { ListaDocumentos } from './ListaDocumentos';
import { EmitirDocumento } from './EmitirDocumento';
import { DetalheDocumento } from './DetalheDocumento';

/** Vendas › Facturação (ecrã vendas_faturacao): listagem, emissão e detalhe dos documentos comerciais. */
export default function Faturacao() {
  return (
    <Routes>
      <Route index element={<ListaDocumentos />} />
      <Route path="novo" element={<EmitirDocumento />} />
      <Route path=":id" element={<DetalheDocumento />} />
    </Routes>
  );
}
