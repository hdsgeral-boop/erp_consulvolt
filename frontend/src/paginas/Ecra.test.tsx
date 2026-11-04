import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router';
import type { ModuloMenu } from '@/api/tipos';
import { Ecra } from './Ecra';

vi.setConfig({ testTimeout: 60_000 });
let papel = 'ADMINISTRADOR';
let menu: ModuloMenu[] = [];
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ menu, pode: () => false, utilizador: { papel } }) }));
vi.mock('@/modulos/registo', async () => {
  const { lazy } = await import('react');
  return { ECRAS: { ia_assistente: lazy(async () => ({ default: () => <p>ECRA ASSISTENTE IA</p> })) } };
});

function ir(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/inicio" element={<p>PAGINA INICIO</p>} />
        <Route path="/admin/clientes" element={<p>CONSOLA CLIENTES</p>} />
        <Route path="/m/:modulo/:ecra/*" element={<Ecra />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('Ecra — redirecções e guardas', () => {
  it('cliente normal: /m/config/plataforma_clientes não abre e volta ao Início', async () => {
    papel = 'ADMINISTRADOR';
    menu = [{ id: 'config', nome: 'Configurações', ecras: [{ id: 'plataforma_clientes', nome: 'x', vistas: [], pai: null }] }];   // mesmo que o menu o trouxesse
    ir('/m/config/plataforma_clientes');
    expect(await screen.findByText('PAGINA INICIO')).toBeInTheDocument();
  });

  it('utilizador da plataforma: /m/config/plataforma_clientes vai à consola /admin/clientes', async () => {
    papel = 'SUPER_ADMINISTRADOR';
    menu = [];
    ir('/m/config/plataforma_clientes');
    expect(await screen.findByText('CONSOLA CLIENTES')).toBeInTheDocument();
  });

  it('o URL antigo /m/contab/ia_assistente abre o ecrã novo em /m/config/ia_assistente a quem tem a permissão', async () => {
    papel = 'ADMINISTRADOR';
    menu = [{ id: 'config', nome: 'Configurações', ecras: [{ id: 'ia_assistente', nome: 'Assistente IA', vistas: [], pai: null }] }];
    ir('/m/contab/ia_assistente');
    expect(await screen.findByText('ECRA ASSISTENTE IA')).toBeInTheDocument();
  });

  it('sem a permissão, nem o URL antigo nem o novo abrem o Assistente IA (403: a guarda do destino manda)', async () => {
    papel = 'ADMINISTRADOR';
    menu = [{ id: 'config', nome: 'Configurações', ecras: [{ id: 'config_geral', nome: 'Geral', vistas: [], pai: null }] }];
    ir('/m/contab/ia_assistente');
    expect(await screen.findByText('Sem acesso')).toBeInTheDocument();
    expect(screen.queryByText('ECRA ASSISTENTE IA')).toBeNull();
  });
});
