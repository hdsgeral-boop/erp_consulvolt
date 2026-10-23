import { message, Spin } from 'antd';
import { useEffect } from 'react';
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
import { TermosUtilizacao } from '@/paginas/publico/legal/TermosUtilizacao';
import { PoliticaPrivacidade } from '@/paginas/publico/legal/PoliticaPrivacidade';
import { ConformidadeFiscal } from '@/paginas/publico/legal/ConformidadeFiscal';
import { LayoutPlataforma } from '@/plataforma/LayoutPlataforma';
import { PlataformaDashboardExecutivo } from '@/plataforma/PlataformaDashboardExecutivo';
import { PlataformaLogin } from '@/plataforma/PlataformaLogin';
import PlataformaClientes from '@/modulos/config/PlataformaClientes';
import PlataformaPlanos from '@/modulos/config/PlataformaPlanos';
import PlataformaSaasGestao from '@/modulos/config/PlataformaSaasGestao';
import PlataformaSuporte from '@/modulos/config/PlataformaSuporte';
import PlataformaAuditoria from '@/modulos/config/PlataformaAuditoria';
import PlataformaDefinicoes from '@/modulos/config/PlataformaDefinicoes';
import PlataformaSessoes from '@/modulos/config/PlataformaSessoes';
import PlataformaAdministradores from '@/modulos/config/PlataformaAdministradores';
import PlataformaAgentesSuporte from '@/modulos/config/PlataformaAgentesSuporte';
import { LayoutSuporte } from '@/suporte/LayoutSuporte';
import { SuporteLogin } from '@/suporte/SuporteLogin';
import SuportePainel from '@/suporte/SuportePainel';

/**
 * Encaminhamento:
 * - Sem sessão → Landing Page (/), Registar (/registar), Login ERP (/entrar), Central de Backoffice (/admin/login —
 *   gate único para Super Admin e Agente de Suporte, ADR-076: outros utilizadores são rejeitados e reencaminhados
 *   para /entrar), Login Suporte directo (/suporte/login)
 * - Com sessão Super Admin → Consola da Plataforma (/admin) — também pode usar /suporte
 * - Com sessão Agente de Suporte → Backoffice de Suporte (/suporte), isolado de /admin (ADR-076)
 * - Com sessão Utilizador de Empresa → Selecção ou ERP (/app, /m/...)
 * - /portal: Portal autónomo do colaborador
 */
export function App() {
  const { estado, empresa, utilizador } = useSessao();
  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';
  const agenteSuporte = utilizador?.papel === 'AGENTE_SUPORTE';

  if (estado === 'a_carregar') {
    return <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;
  }

  return (
    <BrowserRouter>
      <Routes>
        <Route
          path="/"
          element={estado === 'anonimo' ? <LandingPage /> : <Destino empresa={!!empresa} agenteSuporte={agenteSuporte} />}
        />
        <Route
          path="registar"
          element={estado === 'anonimo' ? <RegistarEmpresa /> : <Destino empresa={!!empresa} agenteSuporte={agenteSuporte} />}
        />
        <Route
          path="entrar"
          element={estado === 'anonimo' ? <Entrar /> : <Destino empresa={!!empresa} agenteSuporte={agenteSuporte} />}
        />
        <Route
          path="recuperar-password"
          element={estado === 'anonimo' ? <RecuperarPassword /> : <Destino empresa={!!empresa} agenteSuporte={agenteSuporte} />}
        />
        <Route
          path="portal/*"
          element={estado === 'anonimo' ? <Entrar /> : <PortalAutonomo />}
        />

        {/* Páginas legais/institucionais: sempre públicas, independentemente da sessão */}
        <Route path="termos" element={<TermosUtilizacao />} />
        <Route path="privacidade" element={<PoliticaPrivacidade />} />
        <Route path="conformidade-fiscal" element={<ConformidadeFiscal />} />

        {/* Portal de Administração da Plataforma SaaS */}
        <Route
          path="admin/login"
          element={superAdmin ? <Navigate to="/admin" replace /> : agenteSuporte ? <Navigate to="/suporte" replace /> : <PlataformaLogin />}
        />
        <Route
          path="admin"
          element={
            estado === 'anonimo' ? (
              <Navigate to="/admin/login" replace />
            ) : superAdmin ? (
              <LayoutPlataforma />
            ) : (
              <AcessoNegadoPlataforma agenteSuporte={agenteSuporte} />
            )
          }
        >
          <Route index element={<PlataformaDashboardExecutivo />} />
          <Route path="clientes" element={<PlataformaClientes />} />
          <Route path="planos" element={<PlataformaPlanos />} />
          <Route path="saas" element={<PlataformaSaasGestao />} />
          <Route path="suporte" element={<PlataformaSuporte />} />
          <Route path="auditoria" element={<PlataformaAuditoria />} />
          <Route path="sessoes" element={<PlataformaSessoes />} />
          <Route path="administradores" element={<PlataformaAdministradores />} />
          <Route path="agentes-suporte" element={<PlataformaAgentesSuporte />} />
          <Route path="definicoes" element={<PlataformaDefinicoes />} />
        </Route>

        {/* Backoffice de suporte, isolado da plataforma (ADR-076): agente de suporte ou super-administrador */}
        <Route
          path="suporte/login"
          element={superAdmin || agenteSuporte ? <Navigate to="/suporte" replace /> : <SuporteLogin />}
        />
        <Route
          path="suporte"
          element={
            estado === 'anonimo' ? (
              <Navigate to="/suporte/login" replace />
            ) : superAdmin || agenteSuporte ? (
              <LayoutSuporte />
            ) : (
              <Navigate to="/app" replace />
            )
          }
        >
          <Route index element={<SuportePainel />} />
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

// Super Administrador: fora do login explícito (Entrar.tsx, que já o manda para /admin), cai na escolha/ERP como
// qualquer utilizador — trocar de empresa (LayoutPrincipal) ou recarregar a página nunca força a consola da
// plataforma; chega lá pelo link «Consola da Plataforma» no menu do utilizador quando quiser.
function Destino({ empresa, agenteSuporte }: { empresa: boolean; agenteSuporte: boolean }) {
  return <Navigate to={agenteSuporte ? '/suporte' : empresa ? '/inicio' : '/app'} replace />;
}

/**
 * Guarda de acesso à Plataforma SaaS (ADR-075): só entra quem tem sessão de Super Administrador do sistema.
 * Quem tiver sessão com outro nível é avisado e reencaminhado para a sua própria área, nunca entra na consola.
 */
function AcessoNegadoPlataforma({ agenteSuporte }: { agenteSuporte: boolean }) {
  useEffect(() => {
    message.warning('Acesso não permitido. A Plataforma SaaS é reservada a Super Administradores do sistema.');
  }, []);
  return <Navigate to={agenteSuporte ? '/suporte' : '/app'} replace />;
}
