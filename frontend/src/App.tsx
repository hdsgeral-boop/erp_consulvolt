import { Spin } from 'antd';
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { normalizarCaminhoLegado } from '@/utilitarios/caminhos';
import { useSessao } from '@/sessao/SessaoContexto';
import { LayoutPrincipal } from '@/layout/LayoutPrincipal';
import { Entrar } from '@/paginas/Entrar';
import { RedefinirSenha } from '@/paginas/RedefinirSenha';
import { EscolherEmpresa } from '@/paginas/EscolherEmpresa';
import { Inicio } from '@/paginas/Inicio';
import { Ecra } from '@/paginas/Ecra';
import { DadosPessoais } from '@/paginas/DadosPessoais';
import { PortalAutonomo } from '@/portal/PortalAutonomo';

/** `/m/{modulo}/{ecra}/…` (endereço antigo) → `/{modulo}/{ecra}/…`, mantendo a pesquisa (`?…`) e o `#`. */
export function RedirecionarEnderecoAntigo() {
  const { pathname, search, hash } = useLocation();
  return <Navigate replace to={`${normalizarCaminhoLegado(pathname)}${search}${hash}`} />;
}

/**
 * Encaminhamento: sem sessão → entrar; sem empresa activa → escolher; depois o layout com o menu do utilizador.
 * /portal: portal autónomo do colaborador (sem o menu do ERP). O login e a escolha de empresa não mudam o endereço,
 * por isso quem entra por /portal volta a /portal; com sessão já aberta não pede de novo as credenciais.
 */
export function App() {
  const { estado, empresa } = useSessao();
  if (estado === 'a_carregar') return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  if (estado === 'anonimo') return window.location.pathname === '/redefinir-senha' ? <RedefinirSenha /> : <Entrar />;
  if (!empresa) return <EscolherEmpresa />;
  return (
    <BrowserRouter>
      <Routes>
        <Route path="portal/*" element={<PortalAutonomo />} />
        {/* Endereços antigos com o prefixo /m/ (favoritos do navegador, ligações já enviadas): vão para o endereço novo. */}
        <Route path="m/:modulo/:ecra/*" element={<RedirecionarEnderecoAntigo />} />
        <Route element={<LayoutPrincipal />}>
          <Route index element={<Inicio />} />
          <Route path=":modulo/:ecra/*" element={<Ecra />} />
          <Route path="dados-pessoais" element={<DadosPessoais />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
