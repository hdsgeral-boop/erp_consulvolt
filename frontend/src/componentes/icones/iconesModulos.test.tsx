import { readFileSync } from 'node:fs';
import path from 'node:path';
import { render } from '@testing-library/react';
import {
  ICONES_MODULOS,
  IconeEcraPorOmissao,
  IconeModuloPorOmissao,
  componenteIconeEcra,
  componenteIconeModulo,
  iconeEcra,
  iconeModulo,
} from './iconesModulos';

const catalogo = JSON.parse(readFileSync(path.resolve(__dirname, '../../../../backend/resources/permissoes/catalogo.json'), 'utf8')) as {
  modulos: { id: string; ecras: { id: string }[] }[];
};

describe('ícones dos módulos', () => {
  it('todos os módulos do catálogo de permissões têm um ícone próprio', () => {
    const semIcone = catalogo.modulos.map((m) => m.id).filter((id) => !ICONES_MODULOS[id]);
    expect(catalogo.modulos.length).toBeGreaterThan(0);
    expect(semIcone).toEqual([]);
    catalogo.modulos.forEach((m) => expect(componenteIconeModulo(m.id)).not.toBe(IconeModuloPorOmissao));
  });

  it('não há ícones de módulos que já não existem no catálogo', () => {
    const ids = new Set(catalogo.modulos.map((m) => m.id));
    expect(Object.keys(ICONES_MODULOS).filter((id) => !ids.has(id))).toEqual([]);
  });

  it('módulos diferentes têm ícones diferentes', () => {
    const icones = Object.values(ICONES_MODULOS);
    expect(new Set(icones).size).toBe(icones.length);
  });

  it('aceita sinónimos e maiúsculas e usa o ícone por omissão para ids desconhecidos', () => {
    expect(componenteIconeModulo('armazem')).toBe(ICONES_MODULOS.stock);
    expect(componenteIconeModulo(' Contabilidade ')).toBe(ICONES_MODULOS.contab);
    expect(componenteIconeModulo('modulo_inexistente')).toBe(IconeModuloPorOmissao);
  });

  it('renderiza um SVG', () => {
    const { container } = render(<>{iconeModulo('vendas')}</>);
    expect(container.querySelector('svg')).not.toBeNull();
  });
});

describe('ícones dos ecrãs', () => {
  it('ecrãs frequentes têm ícone por regra e os restantes caem no ícone por omissão (ou no do módulo)', () => {
    expect(componenteIconeEcra('dashboard')).not.toBe(IconeEcraPorOmissao);
    expect(componenteIconeEcra('contab_mapa_balancete')).toBe(componenteIconeEcra('vendas_relatorios'));
    expect(componenteIconeEcra('config_geral')).not.toBe(IconeEcraPorOmissao);
    expect(componenteIconeEcra('xyz_desconhecido')).toBe(IconeEcraPorOmissao);
    expect(componenteIconeEcra('xyz_desconhecido', ICONES_MODULOS.crm)).toBe(ICONES_MODULOS.crm);
  });

  it('todos os ecrãs do catálogo produzem um ícone SVG', () => {
    for (const m of catalogo.modulos) {
      for (const e of m.ecras) {
        const { container, unmount } = render(<>{iconeEcra(e.id, m.id)}</>);
        expect(container.querySelector('svg'), `${m.id}/${e.id}`).not.toBeNull();
        unmount();
      }
    }
  });
});
