import { lazy, type ComponentType, type LazyExoticComponent } from 'react';

/** Ecrãs do módulo Configurações (ids do catálogo de permissões). */
export const ecras: Record<string, LazyExoticComponent<ComponentType>> = {
  config_geral: lazy(() => import('./ConfigGeral')),
  config_empresas: lazy(() => import('./Empresas')),
  config_licenca: lazy(() => import('./Licenca')),
  plataforma_painel: lazy(() => import('./PlataformaPainel')),
  plataforma_clientes: lazy(() => import('./PlataformaClientes')),
  plataforma_suporte: lazy(() => import('./PlataformaSuporte')),
  plataforma_administradores: lazy(() => import('./PlataformaAdministradores')),
  plataforma_auditoria: lazy(() => import('./PlataformaAuditoria')),
  plataforma_sessoes: lazy(() => import('./PlataformaSessoes')),
  plataforma_definicoes: lazy(() => import('./PlataformaDefinicoes')),
  minha_conta: lazy(() => import('./MinhaConta')),
  plataforma_planos: lazy(() => import('./PlataformaPlanos')),
  plataforma_codigos: lazy(() => import('./PlataformaCodigos')),
  plataforma_saas: lazy(() => import('./PlataformaSaasGestao')),
  config_plano: lazy(() => import('./PlanoContas')),
  config_moedas: lazy(() => import('./Moedas')),
  config_utilizadores: lazy(() => import('./Utilizadores')),
  config_perfis: lazy(() => import('./Perfis')),
  config_logs: lazy(() => import('./Logs')),
  config_manutencao: lazy(() => import('./Manutencao')),
  config_migracao: lazy(() => import('./Migracao')),
  notificacoes_centro: lazy(() => import('./NotificacoesCentro')),
};
