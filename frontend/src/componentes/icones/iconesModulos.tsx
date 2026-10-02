import {
  ApartmentOutlined,
  AppstoreOutlined,
  AuditOutlined,
  BarChartOutlined,
  BankOutlined,
  CalculatorOutlined,
  CalendarOutlined,
  ContactsOutlined,
  DashboardOutlined,
  DatabaseOutlined,
  FieldTimeOutlined,
  FileProtectOutlined,
  FileTextOutlined,
  FundOutlined,
  IdcardOutlined,
  InboxOutlined,
  PrinterOutlined,
  ProjectOutlined,
  SafetyCertificateOutlined,
  SettingOutlined,
  ShopOutlined,
  ShoppingCartOutlined,
  ShoppingOutlined,
  SolutionOutlined,
  SwapOutlined,
  TeamOutlined,
  ToolOutlined,
  UserOutlined,
  WalletOutlined,
} from '@ant-design/icons';
import type { ComponentType, CSSProperties, ReactNode } from 'react';

/**
 * Ícones SVG (de @ant-design/icons) dos módulos e ecrãs do ERP.
 *
 * Fonte dos ids: backend/resources/permissoes/catalogo.json → modulos[].id (o mesmo que GET /api/sistema/menu devolve).
 * Ao acrescentar um módulo ao catálogo, acrescente aqui o ícone — o teste iconesModulos.test.ts falha se faltar algum.
 */
export type ComponenteIcone = ComponentType<{ className?: string; style?: CSSProperties }>;

/** Módulo do catálogo → ícone. */
export const ICONES_MODULOS: Readonly<Record<string, ComponenteIcone>> = {
  geral: DashboardOutlined, // Geral / Gestão: painel, relatórios de gestão, BI
  rh: TeamOutlined, // Recursos Humanos e Salários
  contab: CalculatorOutlined, // Contabilidade
  teso: WalletOutlined, // Tesouraria
  vendas: ShoppingOutlined, // Vendas e Facturação
  pos: ShopOutlined, // POS, Lavandaria e Hotelaria (frente de loja)
  compras: ShoppingCartOutlined, // Compras e Aprovisionamento
  stock: InboxOutlined, // Armazém e Inventário
  activos: ToolOutlined, // Activos (Imobilizado): equipamentos, manutenção, amortizações
  projectos: ProjectOutlined, // Projectos
  crm: ContactsOutlined, // CRM
  acrescimos: FieldTimeOutlined, // Acréscimos e Diferimentos (especialização de exercícios)
  orcamento: FundOutlined, // Gestão Orçamental
  estrutura: ApartmentOutlined, // Estrutura Orgânica
  config: SettingOutlined, // Configurações
};

/** Sinónimos que possam aparecer noutros pontos (rotas antigas, textos) → id do catálogo. */
const SINONIMOS: Readonly<Record<string, string>> = {
  gestao: 'geral',
  inicio: 'geral',
  armazem: 'stock',
  inventario: 'stock',
  contabilidade: 'contab',
  tesouraria: 'teso',
  facturacao: 'vendas',
  faturacao: 'vendas',
  aprovisionamento: 'compras',
  imobilizado: 'activos',
  configuracoes: 'config',
  salarios: 'rh',
};

/** Ícone por omissão de um módulo desconhecido. */
export const IconeModuloPorOmissao: ComponenteIcone = AppstoreOutlined;

/** Componente do ícone de um módulo (com sinónimos e ícone por omissão). */
export function componenteIconeModulo(moduloId: string): ComponenteIcone {
  const id = moduloId.trim().toLowerCase();
  return ICONES_MODULOS[id] ?? ICONES_MODULOS[SINONIMOS[id] ?? ''] ?? IconeModuloPorOmissao;
}

/** Elemento pronto a usar (menus, cartões, atalhos): `iconeModulo('vendas')`. */
export function iconeModulo(moduloId: string, props?: { className?: string; style?: CSSProperties }): ReactNode {
  const Icone = componenteIconeModulo(moduloId);
  return <Icone {...props} />;
}

/**
 * Ícones de ecrãs frequentes, por regra sobre o id do ecrã (a primeira que corresponder ganha).
 * Servem atalhos, cartões e cabeçalhos; o menu lateral usa apenas os ícones dos módulos.
 */
const REGRAS_ECRAS: ReadonlyArray<readonly [RegExp, ComponenteIcone]> = [
  [/dashboard|painel|_bi$/, DashboardOutlined],
  [/relatorio|_rel_|mapa|balancete|balanco|extrato|_dr$|previs/, BarChartOutlined],
  [/impress|print/, PrinterOutlined],
  [/perfis|permiss/, SafetyCertificateOutlined],
  [/logs|auditoria|conferencia/, AuditOutlined],
  [/utilizadores|colaboradores/, UserOutlined],
  [/clientes|contas$|crm_contas/, ContactsOutlined],
  [/fornecedores/, ShopOutlined],
  [/contratos/, FileProtectOutlined],
  [/agenda|ferias|assiduidade|calendario|gantt/, CalendarOutlined],
  [/funcoes|infotipos|portal/, IdcardOutlined],
  [/avaliacao|produtividade/, SolutionOutlined],
  [/bancario|banco|meios_pagamento|pagamentos|caixa/, BankOutlined],
  [/integracao|migracao|movimentos|guias/, SwapOutlined],
  [/manutencao/, ToolOutlined],
  [/armazens|stock|inventario/, DatabaseOutlined],
  [/config|tabelas|moedas|plano/, SettingOutlined],
  [/faturacao|facturacao|lancamentos|registos|propostas/, FileTextOutlined],
];

/** Ícone por omissão de um ecrã. */
export const IconeEcraPorOmissao: ComponenteIcone = FileTextOutlined;

/** Componente do ícone de um ecrã pelo seu id (regras acima; senão `porOmissao`, por defeito FileTextOutlined). */
export function componenteIconeEcra(ecraId: string, porOmissao: ComponenteIcone = IconeEcraPorOmissao): ComponenteIcone {
  const id = ecraId.trim().toLowerCase();
  return REGRAS_ECRAS.find(([regra]) => regra.test(id))?.[1] ?? porOmissao;
}

/** Elemento do ícone de um ecrã; com `moduloId`, o fallback é o ícone do módulo em vez do genérico. */
export function iconeEcra(ecraId: string, moduloId?: string, props?: { className?: string; style?: CSSProperties }): ReactNode {
  const Icone = componenteIconeEcra(ecraId, moduloId ? componenteIconeModulo(moduloId) : IconeEcraPorOmissao);
  return <Icone {...props} />;
}
