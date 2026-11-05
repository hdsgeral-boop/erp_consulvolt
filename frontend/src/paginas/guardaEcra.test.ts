import { describe, expect, it } from 'vitest';
import type { ModuloMenu } from '@/api/tipos';
import { ECRAS } from '@/modulos/registo';
import { decidirEcra } from './guardaEcra';

const menu: ModuloMenu[] = [
  { id: 'geral', nome: 'Geral', ecras: [{ id: 'dashboard', nome: 'Dashboard', vistas: [], pai: null }] },
  { id: 'rh', nome: 'RH', ecras: [{ id: 'colaboradores', nome: 'Colaboradores', vistas: [], pai: null }] },
];
const nao = () => false;

describe('guarda /m/:modulo/:ecra', () => {
  it('abre só o que está no menu do servidor, sob o módulo certo', () => {
    expect(decidirEcra(menu, 'geral', 'dashboard', nao)).toBe('permitido');
    expect(decidirEcra(menu, 'rh', 'colaboradores', nao)).toBe('permitido');
  });
  it('ecrã fora do menu, módulo trocado ou inventado é negado', () => {
    expect(decidirEcra(menu, 'rh', 'folha_salarial', nao)).toBe('negado');
    expect(decidirEcra(menu, 'geral', 'colaboradores', nao)).toBe('negado');
    expect(decidirEcra(menu, 'plataforma', 'plataforma_clientes', nao)).toBe('negado');
    expect(decidirEcra([], 'geral', 'dashboard', nao)).toBe('negado');
  });
  it('ia_chat nunca é um ecrã: vai ao Início, mesmo que o menu o trouxesse', () => {
    expect(decidirEcra(menu, 'geral', 'ia_chat', () => true)).toBe('sem_rota');
    const comIa: ModuloMenu[] = [{ id: 'geral', nome: 'Geral', ecras: [{ id: 'ia_chat', nome: 'x', vistas: [], pai: null }] }];
    expect(decidirEcra(comIa, 'geral', 'ia_chat', () => true)).toBe('sem_rota');
    expect(ECRAS).not.toHaveProperty('ia_chat');
  });
  it('centro de notificações abre com a permissão própria', () => {
    expect(decidirEcra(menu, 'config', 'notificacoes_centro', nao)).toBe('negado');
    expect(decidirEcra(menu, 'config', 'notificacoes_centro', (...c) => c.includes('notificacoes_centro_view'))).toBe('permitido');
  });
});

describe('guarda /m/:modulo/:ecra com a conta root (menu completo vindo do servidor)', () => {
  const menuRoot: ModuloMenu[] = [
    ...menu,
    { id: 'plataforma', nome: 'Plataforma', ecras: [{ id: 'plataforma_clientes', nome: 'Clientes', vistas: [], pai: null }] },
    { id: 'suporte', nome: 'Suporte', ecras: [{ id: 'suporte_tickets', nome: 'Tickets', vistas: [], pai: null }] },
  ];
  it('abre os ecrãs da plataforma e do suporte que o servidor lhe põe no menu', () => {
    expect(decidirEcra(menuRoot, 'plataforma', 'plataforma_clientes', nao)).toBe('permitido');
    expect(decidirEcra(menuRoot, 'suporte', 'suporte_tickets', nao)).toBe('permitido');
  });
  it('mesmo para a root, ia_chat não é ecrã e um módulo trocado continua negado', () => {
    expect(decidirEcra(menuRoot, 'geral', 'ia_chat', () => true)).toBe('sem_rota');
    expect(decidirEcra(menuRoot, 'rh', 'plataforma_clientes', nao)).toBe('negado');
  });
});
