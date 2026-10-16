import { Spin } from 'antd';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { LayoutPrincipal } from '@/layout/LayoutPrincipal';
import { Entrar } from '@/paginas/Entrar';
import { EscolherEmpresa } from '@/paginas/EscolherEmpresa';
import { Inicio } from '@/paginas/Inicio';
import { Ecra } from '@/paginas/Ecra';
import { DadosPessoais } from '@/paginas/DadosPessoais';
import { PortalAutonomo } from '@/portal/PortalAutonomo';

/**
 * Encaminhamento: sem sessão → entrar; sem empresa activa → escolher; depois o layout com o menu do utilizador.
 * /portal: portal autónomo do colaborador (sem o menu do ERP). O login e a escolha de empresa não mudam o endereço,
 * por isso quem entra por /portal volta a /portal; com sessão já aberta não pede de novo as credenciais.
 */
export function App() {
  const { estado, empresa } = useSessao();
  if (estado === 'a_carregar') return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  if (estado === 'anonimo') return <Entrar />;
  if (!empresa) return <EscolherEmpresa />;
  return (
    <BrowserRouter>
      <Routes>
        <Route path="portal/*" element={<PortalAutonomo />} />
        <Route element={<LayoutPrincipal />}>
          <Route index element={<Inicio />} />
          <Route path="m/:modulo/:ecra/*" element={<Ecra />} />
          <Route path="dados-pessoais" element={<DadosPessoais />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
