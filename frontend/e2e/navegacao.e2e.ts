import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { expect, test, type Page } from '@playwright/test';
import { UTILIZADOR, api, entrarComo } from './apoio';

/**
 * Abre TODOS os ecrãs do menu do administrador (/m/{modulo}/{ecra}) e confirma que nenhum mostra «Sem acesso»,
 * notificação de erro, erro na consola do navegador, excepção ou resposta 4xx/5xx da API ao carregar.
 * Os ecrãs vêm do catálogo de permissões (o mesmo que o GET /api/sistema/menu usa); o primeiro teste confirma
 * que o menu devolvido pela API ao administrador coincide com o catálogo.
 */
interface EcraCatalogo {
  id: string;
  nome: string;
}
const catalogo = JSON.parse(
  readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../backend/resources/permissoes/catalogo.json'), 'utf8'),
) as { modulos: { id: string; nome: string; ecras: EcraCatalogo[] }[] };
const ECRAS = catalogo.modulos.flatMap((m) => m.ecras.map((e) => ({ modulo: m.id, ...e })));

// sem modo série: uma falha não impede os ecrãs seguintes (o worker reinicia e o beforeAll volta a entrar)

let pagina: Page;
let problemas: string[] = [];

test.beforeAll(async ({ browser }) => {
  pagina = await browser.newPage();
  pagina.on('console', (m) => {
    if (m.type() === 'error') problemas.push(`consola: ${m.text()}`);
  });
  pagina.on('pageerror', (e) => problemas.push(`excepção: ${e.message}`));
  pagina.on('response', (r) => {
    if (r.url().includes('/api/') && r.status() >= 400) problemas.push(`HTTP ${r.status()} ${r.request().method()} ${new URL(r.url()).pathname}${new URL(r.url()).search}`);
  });
  await entrarComo(pagina, UTILIZADOR.admin);
});

test.afterAll(async () => {
  await pagina?.close();
});

test('o menu do administrador (GET /api/sistema/menu) tem todos os ecrãs do catálogo', async () => {
  const a = await api(UTILIZADOR.admin);
  const r = await a.get<{ menu: { id: string; ecras: { id: string }[] }[] }>('/sistema/menu');
  const doMenu = r.menu.flatMap((m) => m.ecras.map((e) => `${m.id}/${e.id}`)).sort();
  await a.fechar();
  expect(doMenu).toEqual(ECRAS.map((e) => `${e.modulo}/${e.id}`).sort());
});

for (const e of ECRAS) {
  test(`ecrã ${e.modulo}/${e.id} — ${e.nome}`, async () => {
    problemas = [];
    await pagina.goto(`/m/${e.modulo}/${e.id}`);
    await pagina.waitForLoadState('networkidle');
    await pagina.waitForTimeout(400);
    await pagina.waitForLoadState('networkidle');

    const semAcesso = await pagina.getByText('Sem acesso', { exact: true }).count();
    const notificacoes = await pagina.locator('.ant-notification-notice-error').allInnerTexts();
    const alertasErro = await pagina.locator('.ant-result-error, .ant-result-500').count();
    const motivos = [
      ...(semAcesso ? ['mostra «Sem acesso»'] : []),
      ...notificacoes.map((n) => `notificação de erro: ${n.replace(/\s+/g, ' ').trim()}`),
      ...(alertasErro ? ['mostra um resultado de erro'] : []),
      ...problemas,
    ];
    // fecha notificações para não passarem ao ecrã seguinte
    await pagina.evaluate(() => document.querySelectorAll('.ant-notification-notice-close').forEach((b) => (b as HTMLElement).click()));
    expect(motivos, `ecrã /m/${e.modulo}/${e.id}`).toEqual([]);
  });
}
