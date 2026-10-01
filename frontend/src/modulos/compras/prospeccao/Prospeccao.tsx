import { Route, Routes } from 'react-router-dom';
import { ListaPropostas } from './ListaPropostas';
import { NovaProposta } from './NovaProposta';
import { DetalheProposta } from './DetalheProposta';
import { QuadroComparativo } from './QuadroComparativo';

/** Compras › Prospecção e adjudicação (ecrã compras_prospeccao): propostas, quadro de avaliação e adjudicação. */
export default function Prospeccao() {
  return (
    <Routes>
      <Route index element={<ListaPropostas />} />
      <Route path="novo" element={<NovaProposta />} />
      <Route path="comparacao/:pedidoId" element={<QuadroComparativo />} />
      <Route path=":id" element={<DetalheProposta />} />
    </Routes>
  );
}
