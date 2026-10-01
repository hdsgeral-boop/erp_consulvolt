import { Spin } from 'antd';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { LayoutPrincipal } from '@/layout/LayoutPrincipal';
import { Entrar } from '@/paginas/Entrar';
import { EscolherEmpresa } from '@/paginas/EscolherEmpresa';
import { Inicio } from '@/paginas/Inicio';
import { Ecra } from '@/paginas/Ecra';

/** Encaminhamento: sem sessão → entrar; sem empresa activa → escolher; depois o layout com o menu do utilizador. */
export function App() {
  const { estado, empresa } = useSessao();
  if (estado === 'a_carregar') return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  if (estado === 'anonimo') return <Entrar />;
  if (!empresa) return <EscolherEmpresa />;
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<LayoutPrincipal />}>
          <Route index element={<Inicio />} />
          <Route path="m/:modulo/:ecra/*" element={<Ecra />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
