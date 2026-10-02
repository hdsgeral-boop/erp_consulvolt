import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { EMPRESA_DEMO, UTILIZADOR, entrarComo } from './apoio';

/**
 * Responsividade e identidade (pedido do utilizador, 2026-10-01: «100% responsivo», nome e logótipo da empresa na
 * barra, PDF com logótipo e nome). Abre TODOS os ecrãs do menu num telemóvel (375×812) e confirma que nem a página
 * nem o contentor do conteúdo ganham barra de deslocação horizontal (tabelas e conteúdos largos deslocam-se dentro
 * do próprio contentor). Confirma ainda a marca da empresa na barra e o cabeçalho do documento de impressão/PDF.
 */
interface EcraCatalogo {
  id: string;
  nome: string;
}
const catalogo = JSON.parse(
  readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../backend/resources/permissoes/catalogo.json'), 'utf8'),
) as { modulos: { id: string; nome: string; ecras: EcraCatalogo[] }[] };
const ECRAS = catalogo.modulos.flatMap((m) => m.ecras.map((e) => ({ modulo: m.id, ...e })));

const TELEMOVEL = { width: 375, height: 812 };
let pagina: Page;

test.beforeAll(async ({ browser }) => {
  pagina = await browser.newPage({ viewport: TELEMOVEL });
  await entrarComo(pagina, UTILIZADOR.admin);
});

test.afterAll(async () => {
  await pagina?.close();
});

test('telemóvel: barra com o logótipo e o nome da empresa; menu numa gaveta', async () => {
  await pagina.goto('/');
  const barra = pagina.locator('.ant-layout-header');
  await expect(barra.getByText(EMPRESA_DEMO).first()).toBeVisible();
  await expect(barra.locator('img[src^="data:image/"]').first()).toBeVisible();
  await pagina.getByRole('button', { name: 'Abrir menu' }).click();
  await expect(pagina.locator('.erp-gaveta-menu')).toBeVisible();
  await pagina.keyboard.press('Escape');
});

for (const e of ECRAS) {
  test(`375 px sem deslocação horizontal — ${e.modulo}/${e.id} (${e.nome})`, async () => {
    await pagina.goto(`/m/${e.modulo}/${e.id}`);
    await pagina.waitForLoadState('networkidle');
    await pagina.waitForTimeout(300);
    const m = await pagina.evaluate(() => {
      const c = document.querySelector('.erp-conteudo') as HTMLElement | null;
      return {
        janela: window.innerWidth,
        documento: document.documentElement.scrollWidth,
        conteudo: c ? c.scrollWidth - c.clientWidth : 0,
      };
    });
    expect(m.documento, `largura do documento em /m/${e.modulo}/${e.id}`).toBeLessThanOrEqual(m.janela + 1);
    expect(m.conteudo, `excesso do conteúdo em /m/${e.modulo}/${e.id}`).toBeLessThanOrEqual(1);
  });
}

test('PDF de uma listagem: documento com o logótipo e o nome da empresa, sem barras de deslocação', async ({ browser }) => {
  const p = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  await entrarComo(p, UTILIZADOR.admin);
  // print() da iframe não abre diálogo em modo headless; guarda-se o documento gerado para o inspeccionar
  await p.addInitScript(() => {
    window.print = () => undefined;
  });
  await p.goto('/m/config/config_empresas');
  await p.waitForLoadState('networkidle');
  await p.getByRole('button', { name: /(^| )PDF$/ }).first().click();
  const doc = p.frameLocator('iframe').last();
  await expect(doc.getByText(EMPRESA_DEMO).first()).toBeVisible({ timeout: 15_000 });
  await expect(doc.locator('img[src^="data:image/"]').first()).toBeAttached();
  const comScroll = await p.evaluate(() => {
    const f = [...document.querySelectorAll('iframe')].pop();
    const d = f?.contentDocument;
    if (!d) return -1;
    return [...d.querySelectorAll('*')].filter((el) => {
      const s = d.defaultView!.getComputedStyle(el);
      return ['auto', 'scroll'].includes(s.overflowX) || ['auto', 'scroll'].includes(s.overflowY);
    }).length;
  });
  expect(comScroll).toBe(0);
  await p.close();
});
