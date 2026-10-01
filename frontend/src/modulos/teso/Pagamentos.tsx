import { Route, Routes } from 'react-router-dom';
import { DetalheDocumento } from './pagamentos/DetalheDocumento';
import { FormularioDocumento } from './pagamentos/FormularioDocumento';
import { ListaDocumentos } from './pagamentos/ListaDocumentos';

/** Tesouraria › Pagamentos e recebimentos (ecrã teso_gestao_pagamentos). */
export default function Pagamentos() {
  return (
    <Routes>
      <Route index element={<ListaDocumentos />} />
      <Route path="novo" element={<FormularioDocumento />} />
      <Route path=":id" element={<DetalheDocumento />} />
      <Route path=":id/editar" element={<FormularioDocumento />} />
    </Routes>
  );
}
