import { Route, Routes } from 'react-router-dom';
import { ListaPedidos } from './ListaPedidos';
import { NovoPedido } from './NovoPedido';
import { DetalhePedido } from './DetalhePedido';

/** Compras › Pedidos internos (ecrã compras_pedidos): pedidos, deliberação por valor e escalões. */
export default function Pedidos() {
  return (
    <Routes>
      <Route index element={<ListaPedidos />} />
      <Route path="novo" element={<NovoPedido />} />
      <Route path=":id" element={<DetalhePedido />} />
    </Routes>
  );
}
