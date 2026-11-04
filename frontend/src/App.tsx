import { message, Spin } from 'antd';
import { lazy, Suspense, useEffect, type ComponentType } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router';
import { useSessao } from '@/sessao/SessaoContexto';
import { LayoutPrincipal } from '@/layout/LayoutPrincipal';
import { Entrar } from '@/paginas/Entrar';
import { EscolherEmpresa } from '@/paginas/EscolherEmpresa';
import { Inicio } from '@/paginas/Inicio';
import { Ecra } from '@/paginas/Ecra';
import { DadosPessoais } from '@/paginas/DadosPessoais';
import { ehHostPortal } from '@/portal/rota';

/**
 * Ecrãs fora do caminho principal do ERP (login → escolha de empresa → layout → ecrãs dos módulos) são carregados
 * só quando a rota é visitada: cada um fica num fragmento próprio no build, em vez de engordar o JS inicial.
 * `preguicoso` adapta exportações com nome ao `React.lazy` (que só aceita `default`).
 */
const preguicoso = (carregar: () => Promise<ComponentType>) => lazy(() => carregar().then((C) => ({ default: C })));
const RecuperarPassword = preguicoso(() => import('@/paginas/RecuperarPassword').then((m) => m.RecuperarPassword));
const TrocaPasswordObrigatoria = preguicoso(() => import('@/paginas/TrocaPasswordObrigatoria').then((m) => m.TrocaPasswordObrigatoria));
const PortalAutonomo = preguicoso(() => import('@/portal/PortalAutonomo').then((m) => m.PortalAutonomo));
const LandingPage = preguicoso(() => import('@/paginas/publico/LandingPage').then((m) => m.LandingPage));
const RegistarEmpresa = preguicoso(() => import('@/paginas/publico/RegistarEmpresa').then((m) => m.RegistarEmpresa));
const TermosUtilizacao = preguicoso(() => import('@/paginas/publico/legal/TermosUtilizacao').then((m) => m.TermosUtilizacao));
const PoliticaPrivacidade = preguicoso(() => import('@/paginas/publico/legal/PoliticaPrivacidade').then((m) => m.PoliticaPrivacidade));
const ConformidadeFiscal = preguicoso(() => import('@/paginas/publico/legal/ConformidadeFiscal').then((m) => m.ConformidadeFiscal));
const LayoutPlataforma = preguicoso(() => import('@/plataforma/LayoutPlataforma').then((m) => m.LayoutPlataforma));
const PlataformaDashboardExecutivo = preguicoso(() => import('@/plataforma/PlataformaDashboardExecutivo').then((m) => m.PlataformaDashboardExecutivo));
const PlataformaLogin = preguicoso(() => import('@/plataforma/PlataformaLogin').then((m) => m.PlataformaLogin));
const PlataformaClientes = lazy(() => import('@/modulos/config/PlataformaClientes'));
const PlataformaPlanos = lazy(() => import('@/modulos/config/PlataformaPlanos'));
const PlataformaSaasGestao = lazy(() => import('@/modulos/config/PlataformaSaasGestao'));
const PlataformaSuporte = lazy(() => import('@/modulos/config/PlataformaSuporte'));
const PlataformaAuditoria = lazy(() => import('@/modulos/config/PlataformaAuditoria'));
const PlataformaDefinicoes = lazy(() => import('@/modulos/config/PlataformaDefinicoes'));
const PlataformaSessoes = lazy(() => import('@/modulos/config/PlataformaSessoes'));
const PlataformaAdministradores = lazy(() => import('@/modulos/config/PlataformaAdministradores'));
const PlataformaAgentesSuporte = lazy(() => import('@/modulos/config/PlataformaAgentesSuporte'));
const PlataformaIA = lazy(() => import('@/modulos/config/PlataformaIA'));
const LayoutSuporte = preguicoso(() => import('@/suporte/LayoutSuporte').then((m) => m.LayoutSuporte));
const DashboardSuporte = lazy(() => import('@/suporte/DashboardSuporte'));
const RespostasRapidasSuporte = lazy(() => import('@/suporte/RespostasRapidasSuporte'));
const SuporteLogin = preguicoso(() => import('@/suporte/SuporteLogin').then((m) => m.SuporteLogin));
const SuportePainel = lazy(() => import('@/suporte/SuportePainel'));

/** Indicador enquanto um fragmento preguiçoso é descarregado. */
const A_CARREGAR = <Spin size="large" style={{ display: 'block', marginTop: '40vh' }} />;

/**
 * Encaminhamento:
 * - Sem sessão → Landing Page (/), Registar (/registar), Login ERP (/entrar), Central de Backoffice (/admin/login —
 *   gate único para Super Admin e Agente de Suporte, ADR-076: outros utilizadores são rejeitados e reencaminhados
 *   para /entrar), Login Suporte directo (/suporte/login)
 * - Com sessão Super Admin → Consola da Plataforma (/admin) — também pode usar /suporte
 * - Com sessão Agente de Suporte → Backoffice de Suporte (/suporte), e só isso: /admin, /app, /inicio,
 *   /dados-pessoais, /m/... e /portal reencaminham sempre para /suporte (ADR-076 — nunca um botão nem um endereço
 *   directo tiram um agente do backoffice; a barreira real, por detrás, é SuporteController/ExigirSuporte no servidor)
 * - Com sessão Utilizador de Empresa → Selecção ou ERP (/app, /m/...)
 * - /portal: Portal autónomo do colaborador
 * - Em portal.<base> (subdomínio externo dedicado): só os caminhos acima do Portal ficam alcançáveis nesta SPA; tudo
 *   o resto redirecciona para /portal. A barreira real é do servidor (ExigirAreaPortal).
 */
export function App() {
  const { estado, empresa, utilizador } = useSessao();
  const superAdmin = utilizador?.papel === 'SUPER_ADMINISTRADOR';
  const agenteSuporte = utilizador?.papel === 'AGENTE_SUPORTE';

  if (estado === 'a_carregar') {
    return A_CARREGAR;
  }

  // Password gerada pelo sistema (criação, reposição ou recuperação): bloqueia TODO o sistema até trocar ou sair
  // (backend: ExigirTrocaPassword, falha fechado em qualquer rota — isto só evita o ecrã normal, não é a barreira real).
  if (estado === 'autenticado' && utilizador?.deve_alterar_password) {
    return <Suspense fallback={A_CARREGAR}><TrocaPasswordObrigatoria /></Suspense>;
  }

  // portal.<base>: endereço externo dedicado (ADR: isolamento por subdomínio). A barreira real é do servidor
  // (ExigirAreaPortal, que só deixa alcançar /portal/acesso e /rh/portal/*); isto só evita mostrar o resto da SPA.
  if (ehHostPortal()) {
    return (
      <BrowserRouter>
        <Suspense fallback={A_CARREGAR}>
          <Routes>
            <Route path="/" element={<Navigate to="/portal" replace />} />
            <Route path="portal/*" element={estado === 'anonimo' ? <Entrar /> : agenteSuporte ? <Navigate to="/suporte" replace /> : !empresa ? <EscolherEmpresa /> : <PortalAutonomo />} />
            <Route
              path="recuperar-password"
              element={estado === 'anonimo' ? <RecuperarPassword /> : <Navigate to="/portal" replace />}
            />
            <Route path="termos" element={<TermosUtilizacao />} />
            <Route path="privacidade" element={<PoliticaPrivacidade />} />
            <Route path="conformidade-fiscal" element={<ConformidadeFiscal />} />
            <Route path="*" element={<Navigate to="/portal" replace />} />
          </Routes>
        </Suspense>
      </BrowserRouter>
    );
  }

  return (
    <BrowserRouter>
      <Suspense fallback={A_CARREGAR}>
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
            element={estado === 'anonimo' ? <Entrar /> : agenteSuporte ? <Navigate to="/suporte" replace /> : !empresa ? <EscolherEmpresa /> : <PortalAutonomo />}
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
            <Route path="ia" element={<PlataformaIA />} />
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
            <Route path="dashboard" element={<DashboardSuporte />} />
            <Route path="respostas-rapidas" element={<RespostasRapidasSuporte />} />
          </Route>

          {/* Área de Aplicação do Cliente ERP — nunca para um agente de suporte (ADR-076: fica só no backoffice) */}
          <Route
            path="app"
            element={
              estado === 'anonimo' ? (
                <Navigate to="/entrar" replace />
              ) : agenteSuporte ? (
                <Navigate to="/suporte" replace />
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
              ) : agenteSuporte ? (
                <Navigate to="/suporte" replace />
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
      </Suspense>
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
