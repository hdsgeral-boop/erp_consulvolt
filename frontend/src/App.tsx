import { Spin } from 'antd';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { LayoutPrincipal } from '@/layout/LayoutPrincipal';
import { Entrar } from '@/paginas/Entrar';
import { RecuperarPassword } from '@/paginas/RecuperarPassword';
import { EscolherEmpresa } from '@/paginas/EscolherEmpresa';
import { Inicio } from '@/paginas/Inicio';
import { Ecra } from '@/paginas/Ecra';
import { DadosPessoais } from '@/paginas/DadosPessoais';
import { PortalAutonomo } from '@/portal/PortalAutonomo';
import { LandingPage } from '@/paginas/publico/LandingPage';
import { RegistarEmpresa } from '@/paginas/publico/RegistarEmpresa';
import { LayoutPlataforma } from '@/plataforma/LayoutPlataforma';
import { PlataformaDashboardExecutivo } from '@/plataforma/PlataformaDashboardExecutivo';
import { PlataformaLogin } from '@/plataforma/PlataformaLogin';
import PlataformaClientes from '@/modulos/config/PlataformaClientes';
import PlataformaPlanos from '@/modulos/config/PlataformaPlanos';
import PlataformaSaasGestao from '@/modulos/config/PlataformaSaasGestao';
import PlataformaSuporte from '@/modulos/config/PlataformaSuporte';
import PlataformaAuditoria from '@/modulos/config/PlataformaAuditoria';
import PlataformaDefinicoes from '@/modulos/config/PlataformaDefinicoes';

/**
 * Encaminhamento:
 * - Sem sessão → Landing Page (/), Registar (/registar), Login ERP (/entrar), Login Plataforma (/admin/login)
 * - Com sessão Super Admin → Consola da Plataforma (/admin)
 * - Com sessão Utilizador de Empresa → Selecção ou ERP (/app, /m/...)
 * - /portal: Portal autónomo do colaborador
 */
export function App() {
  const { estado, empresa, utilizador } = useSessao();
  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';

  if (estado === 'a_carregar') {
    return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  }

  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/"
          element={estado === 'anonimo' ? <LandingPage /> : <Destino empresa={!!empresa} superAdmin={superAdmin} />}
        />
        <Route
          path="registar"
          element={estado === 'anonimo' ? <RegistarEmpresa /> : <Destino empresa={!!empresa} superAdmin={superAdmin} />}
        />
        <Route
          path="entrar"
          element={estado === 'anonimo' ? <Entrar /> : <Destino empresa={!!empresa} superAdmin={superAdmin} />}
        />
        <Route
          path="recuperar-password"
          element={estado === 'anonimo' ? <RecuperarPassword /> : <Destino empresa={!!empresa} superAdmin={superAdmin} />}
        />
        <Route
          path="portal/*"
          element={estado === 'anonimo' ? <Entrar /> : <PortalAutonomo />}
        />

        {/* Portal de Administração da Plataforma SaaS */}
        <Route
          path="admin/login"
          element={superAdmin ? <Navigate to="/admin" replace /> : <PlataformaLogin />}
        />
        <Route
          path="admin"
          element={
            estado === 'anonimo' ? (
              <Navigate to="/admin/login" replace />
            ) : superAdmin ? (
              <LayoutPlataforma />
            ) : (
              <Navigate to="/app" replace />
            )
          }
        >
          <Route index element={<PlataformaDashboardExecutivo />} />
          <Route path="clientes" element={<PlataformaClientes />} />
          <Route path="planos" element={<PlataformaPlanos />} />
          <Route path="saas" element={<PlataformaSaasGestao />} />
          <Route path="suporte" element={<PlataformaSuporte />} />
          <Route path="auditoria" element={<PlataformaAuditoria />} />
          <Route path="definicoes" element={<PlataformaDefinicoes />} />
        </Route>

        {/* Área de Aplicação do Cliente ERP */}
        <Route
          path="app"
          element={
            estado === 'anonimo' ? (
              <Navigate to="/entrar" replace />
            ) : !empresa ? (
              <EscolherEmpresa />
            ) : (
              <Navigate to="/inicio" replace />
            )
          }
        />
        <Route
          element={
            estado === 'anonimo' ? (
              <Navigate to="/entrar" replace />
            ) : !empresa ? (
              <EscolherEmpresa />
            ) : (
              <LayoutPrincipal />
            )
          }
        >
          <Route path="m/:modulo/:ecra/*" element={<Ecra />} />
          <Route path="dados-pessoais" element={<DadosPessoais />} />
          <Route path="inicio" element={<Inicio />} />
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

function Destino({ empresa, superAdmin }: { empresa: boolean; superAdmin: boolean }) {
  return <Navigate to={superAdmin ? '/admin' : empresa ? '/inicio' : '/app'} replace />;
}
