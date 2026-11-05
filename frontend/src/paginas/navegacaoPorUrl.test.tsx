import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { MemoryRouter, Route, Routes } from 'react-router';
import type { ModuloMenu } from '@/api/tipos';
import { decidirEcra } from './guardaEcra';
import { Ecra } from './Ecra';

/**
 * ADR-083 — navegação pela URL: um ecrã só monta (e só então pede dados) se o MENU DO SERVIDOR o trouxer. Qualquer outro
 * endereço (ecrã de outro perfil, módulo trocado, `?sep=`, ecrã inventado) dá «Sem acesso» sem montar o componente.
 * O servidor volta a recusar a API de qualquer forma (OwaspControloAcessoTest + EntradasMaliciosasListagensTest).
 */
vi.setConfig({ testTimeout: 60_000 });
const montagens = vi.fn();
let menu: ModuloMenu[] = [];
vi.mock('@/sessao/SessaoContexto', () => ({ useSessao: () => ({ menu, pode: () => false, utilizador: { papel: 'UTILIZADOR' } }) }));
vi.mock('@/modulos/registo', async () => {
  const { lazy } = await import('react');
  const ecra = lazy(async () => ({ default: () => { montagens(); return <p>ECRA MONTADO</p>; } }));
  return { ECRAS: new Proxy({}, { get: () => ecra }) };
});

type Catalogo = { modulos: { id: string; ecras: { id: string }[] }[] };
const catalogo = JSON.parse(readFileSync(resolve(__dirname, '../../../backend/resources/permissoes/catalogo.json'), 'utf-8')) as Catalogo;
const todosEcras = catalogo.modulos.flatMap((m) => m.ecras.map((e) => ({ modulo: m.id, ecra: e.id })));

function ir(url: string) {
  return render(
    <MemoryRouter initialEntries={[url]}>
      <Routes>
        <Route path="/inicio" element={<p>PAGINA INICIO</p>} />
        <Route path="/m/:modulo/:ecra/*" element={<Ecra />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('navegação por URL (ADR-083)', () => {
  it('o catálogo tem ecrãs suficientes para o varrimento ser relevante', () => {
    expect(todosEcras.length).toBeGreaterThan(100);
  });

  it('com um menu vazio NENHUM ecrã do catálogo é permitido (negar por omissão)', () => {
    const permitidos = todosEcras.filter((e) => decidirEcra([], e.modulo, e.ecra, () => false) === 'permitido');
    expect(permitidos).toEqual([]);
  });

  it('um perfil restrito só abre os ecrãs do seu menu, sob o módulo certo — todos os outros são negados', () => {
    for (const alvo of todosEcras.slice(0, 40)) {
      const restrito: ModuloMenu[] = [{ id: alvo.modulo, nome: alvo.modulo, ecras: [{ id: alvo.ecra, nome: alvo.ecra, vistas: [], pai: null }] }];
      for (const outro of todosEcras) {
        const esperado = outro.modulo === alvo.modulo && outro.ecra === alvo.ecra ? 'permitido' : 'negado';
        const real = decidirEcra(restrito, outro.modulo, outro.ecra, () => false);
        if (real !== esperado && real !== 'sem_rota') throw new Error(`${alvo.modulo}/${alvo.ecra} vs ${outro.modulo}/${outro.ecra}: ${real}`);
      }
    }
  });

  it('URL de um ecrã que o menu não traz: «Sem acesso», sem montar o componente (nenhum pedido de dados)', async () => {
    menu = [{ id: 'vendas', nome: 'Vendas', ecras: [{ id: 'vendas_clientes', nome: 'Clientes', vistas: [], pai: null }] }];
    montagens.mockClear();
    ir('/m/rh/colaboradores');
    expect(await screen.findByText('Sem acesso')).toBeInTheDocument();
    expect(montagens).not.toHaveBeenCalled();
  });

  it('?sep=, ../ e módulo trocado não contornam a guarda', async () => {
    menu = [{ id: 'vendas', nome: 'Vendas', ecras: [{ id: 'vendas_clientes', nome: 'Clientes', vistas: [], pai: null }] }];
    for (const url of ['/m/rh/colaboradores?sep=salarios', '/m/contab/vendas_clientes', '/m/vendas/%2e%2e%2frh', '/m/vendas/inexistente']) {
      montagens.mockClear();
      const { unmount } = ir(url);
      expect(await screen.findByText('Sem acesso')).toBeInTheDocument();
      expect(montagens).not.toHaveBeenCalled();
      unmount();
    }
  });

  it('o ecrã permitido monta normalmente', async () => {
    menu = [{ id: 'vendas', nome: 'Vendas', ecras: [{ id: 'vendas_clientes', nome: 'Clientes', vistas: [], pai: null }] }];
    montagens.mockClear();
    ir('/m/vendas/vendas_clientes?sep=x');
    expect(await screen.findByText('ECRA MONTADO')).toBeInTheDocument();
    expect(montagens).toHaveBeenCalledTimes(1);
  });
});
