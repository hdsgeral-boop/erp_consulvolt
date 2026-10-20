import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Configurações (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  config_geral: lazy(() => import('./ConfigGeral')),
  config_empresas: lazy(() => import('./Empresas')),
  config_licenca: lazy(() => import('@/plataforma/Licenca')),
  plataforma_painel: lazy(() => import('@/plataforma/PlataformaPainel')),
  plataforma_clientes: lazy(() => import('@/plataforma/PlataformaClientes')),
  plataforma_suporte: lazy(() => import('@/plataforma/PlataformaSuporte')),
  plataforma_administradores: lazy(() => import('@/plataforma/PlataformaAdministradores')),
  plataforma_auditoria: lazy(() => import('@/plataforma/PlataformaAuditoria')),
  plataforma_sessoes: lazy(() => import('@/plataforma/PlataformaSessoes')),
  plataforma_definicoes: lazy(() => import('@/plataforma/PlataformaDefinicoes')),
  minha_conta: lazy(() => import('@/plataforma/MinhaConta')),
  plataforma_planos: lazy(() => import('@/plataforma/PlataformaPlanos')),
  plataforma_codigos: lazy(() => import('@/plataforma/PlataformaCodigos')),
  plataforma_saas: lazy(() => import('@/plataforma/PlataformaSaasGestao')),
  config_plano: lazy(() => import('./PlanoContas')),
  config_moedas: lazy(() => import('./Moedas')),
  config_utilizadores: lazy(() => import('./Utilizadores')),
  config_perfis: lazy(() => import('./Perfis')),
  config_logs: lazy(() => import('./Logs')),
  config_manutencao: lazy(() => import('./Manutencao')),
  config_migracao: lazy(() => import('./Migracao')),
  notificacoes_centro: lazy(() => import('@/plataforma/NotificacoesCentro')),
};
