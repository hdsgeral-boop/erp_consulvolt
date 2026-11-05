import { readFileSync } from 'node:fs';
import { COR_AVISO, COR_PERIGO, COR_PRIMARIA, COR_PRIMARIA_CLARA, COR_PRIMARIA_ESCURA, COR_SUCESSO, FUNDO, LIMITE, LIMITE_SECUNDARIO, MENU_FUNDO, MENU_REALCE, TEXTO, TEXTO_SECUNDARIO, TEXTO_TERCIARIO } from './tema';

const css = readFileSync('src/estilos/designSystem.css', 'utf8');
const token = (nome: string) => new RegExp(`${nome}:\s*([^;]+);`).exec(css)?.[1].trim().toLowerCase();

describe('design system: os tokens CSS espelham o tema do Ant Design (uma só identidade)', () => {
  it.each([
    ['--erp-cor-primaria', COR_PRIMARIA], ['--erp-cor-primaria-escura', COR_PRIMARIA_ESCURA], ['--erp-cor-primaria-clara', COR_PRIMARIA_CLARA],
    ['--erp-cor-sucesso', COR_SUCESSO], ['--erp-cor-aviso', COR_AVISO], ['--erp-cor-perigo', COR_PERIGO],
    ['--erp-cor-texto', TEXTO], ['--erp-cor-texto-secundario', TEXTO_SECUNDARIO], ['--erp-cor-texto-terciario', TEXTO_TERCIARIO],
    ['--erp-cor-limite', LIMITE], ['--erp-cor-limite-suave', LIMITE_SECUNDARIO], ['--erp-cor-fundo', FUNDO],
    ['--erp-cor-menu', MENU_FUNDO], ['--erp-cor-realce-menu', MENU_REALCE],
  ])('%s = %s', (nome, valor) => {
    expect(token(nome)).toBe(valor.toLowerCase());
  });

  it('as transições ficam entre 150 e 220 ms e há bloco de redução de movimento', () => {
    const ms = [...css.matchAll(/--erp-dur[a-z-]*:\s*(\d+)ms/g)].map((m) => Number(m[1]));
    expect(ms.length).toBeGreaterThan(0);
    ms.forEach((v) => { expect(v).toBeGreaterThanOrEqual(150); expect(v).toBeLessThanOrEqual(220); });
    expect(css).toContain('prefers-reduced-motion: reduce');
  });
});
