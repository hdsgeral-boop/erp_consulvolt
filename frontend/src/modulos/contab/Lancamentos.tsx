import { Route, Routes } from 'react-router-dom';
import { ListaLancamentos } from './lancamentos/ListaLancamentos';
import { NovoLancamento } from './lancamentos/NovoLancamento';
import { DetalheLancamento } from './lancamentos/DetalheLancamento';

/** Contabilidade › Lançamentos (ecrã lancamentos): listagem, lançamento manual equilibrado, detalhe e estorno. */
export default function Lancamentos() {
  return (
    <Routes>
      <Route index element={<ListaLancamentos />} />
      <Route path="novo" element={<NovoLancamento />} />
      <Route path=":id" element={<DetalheLancamento />} />
    </Routes>
  );
}
