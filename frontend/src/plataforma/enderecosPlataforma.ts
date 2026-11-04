/**
 * Consola da Plataforma SaaS (/admin): cada ecrã `plataforma_*` do catálogo de permissões tem aqui o seu endereço. O menu do
 * ERP do cliente já não os mostra (vivem só na consola); um URL antigo /m/config/plataforma_* redirecciona para o equivalente
 * (redireccaoEcra). O servidor continua a ser a autoridade: /api/plataforma/* exige ExigirPlataforma e a chave de cada ecrã.
 */
export const ENDERECO_ECRA_PLATAFORMA: Readonly<Record<string, string>> = {
  plataforma_painel: '/admin',
  plataforma_monitorizacao: '/admin/monitorizacao',
  plataforma_clientes: '/admin/clientes',
  plataforma_planos: '/admin/planos',
  plataforma_saas: '/admin/saas',
  plataforma_cupoes: '/admin/cupoes',
  plataforma_ia: '/admin/ia',
  plataforma_suporte: '/admin/suporte',
  plataforma_auditoria: '/admin/auditoria',
  plataforma_sessoes: '/admin/sessoes',
  plataforma_administradores: '/admin/administradores',
  plataforma_operadores_plataforma: '/admin/operadores',
  plataforma_definicoes: '/admin/definicoes',
  plataforma_codigos: '/admin/codigos',
};

/** Papéis de utilizador da plataforma que podem abrir a consola (o menu dela mostra só o que o servidor lhes permite). */
export const PAPEIS_CONSOLA_PLATAFORMA: readonly string[] = ['SUPER_ADMINISTRADOR', 'OPERADOR_PLATAFORMA'];
