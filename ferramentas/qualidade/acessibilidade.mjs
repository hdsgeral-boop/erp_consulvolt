#!/usr/bin/env node
/**
 * Auditoria de acessibilidade WCAG 2.2 AA com axe-core (docs/ACESSIBILIDADE.md; Implementar.txt secção 25).
 * Corre o axe nos ecrãs principais (login, portal, /admin/login, /suporte/login e ecrãs de cada módulo) em desktop e telemóvel,
 * com o utilizador FICTÍCIO do ambiente E2E. Falha se houver violações `critical` ou `serious`.
 * Uso (raiz do repo): node ferramentas/qualidade/acessibilidade.mjs [--larguras=1366,375] [--urls=/,/portal] [--saida=ficheiro.json] [--todos]
 *   (sem --todos percorre uma amostra de ~30 ecrãs; com --todos, todos os do catálogo)
 * axe-core vem de frontend/node_modules (devDependency; não entra no bundle). Variáveis: E2E_URL, E2E_PALAVRA_PASSE.
 */
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const requireFe = createRequire(path.resolve('frontend/package.json'));
const { chromium } = requireFe('@playwright/test');
const AXE = readFileSync(requireFe.resolve('axe-core/axe.min.js'), 'utf8');

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8081';
const SENHA = process.env.E2E_PALAVRA_PASSE ?? 'E2e#Teste2026';
const LARGURAS = (args.larguras ?? '1366,375').split(',').map(Number);
const catalogo = JSON.parse(readFileSync(path.resolve('backend/resources/permissoes/catalogo.json'), 'utf8'));
const TODOS = catalogo.modulos.flatMap((m) => m.ecras.map((e) => `/m/${m.id}/${e.id}`));
const AMOSTRA = [
  '/', '/inicio', '/dados-pessoais', '/m/geral/dashboard', '/m/vendas/vendas_faturacao', '/m/vendas/vendas_clientes', '/m/stock/armazem_stock',
  '/m/rh/colaboradores', '/m/contab/lancamentos', '/m/teso/teso_gestao_pagamentos', '/m/compras/compras_pedidos', '/m/crm/crm_pipeline',
  '/m/config/config_geral', '/m/config/config_licenca', '/m/config/config_empresas', '/m/suporte/suporte_tickets_geral',
  '/m/activos/activos', '/m/projectos/projectos_carteira', '/m/orcamento/orc_orcamentos', '/m/pos/pos',
];
const SEM_SESSAO = ['/portal', '/admin/login', '/suporte/login', '/termos', '/recuperar-password'];
const COM_SESSAO = args.urls ? args.urls.split(',') : args.todos ? ['/', '/inicio', '/dados-pessoais', ...TODOS] : AMOSTRA.filter((u) => !u.startsWith('/m/') || TODOS.includes(u));

const browser = await chromium.launch();
const achados = []; // {url, largura, id, impacto, ajuda, nos}
let ecras = 0;

async function entrar(ctx, page) {
  const r = await page.request.post('/api/autenticacao/entrar', { data: { nome_utilizador: 'e2e.admin', palavra_passe: SENHA }, headers: { Accept: 'application/json' } });
  if (!r.ok()) throw new Error('Login E2E falhou (' + r.status() + ')');
  const empresa = (await r.json()).dados.empresas?.[0]?.id;
  const csrf = (await ctx.cookies()).find((c) => c.name.endsWith('XSRF-TOKEN'))?.value ?? '';
  if (empresa) await page.request.put('/api/autenticacao/empresa-activa', { data: { empresa_id: empresa }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf) } });
}

for (const w of LARGURAS) {
  for (const [autenticado, lista] of [[false, SEM_SESSAO], [true, COM_SESSAO]]) {
    if (args.urls && !autenticado) continue;
    const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 812 : 800 }, locale: 'pt-PT', baseURL: BASE, hasTouch: w < 600 });
    const page = await ctx.newPage();
    if (autenticado) await entrar(ctx, page);
    for (const url of lista) {
      await page.goto(url, { waitUntil: 'load' }).catch(() => {});
      await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(400);
      await page.getByRole('button', { name: 'Agora não' }).click({ timeout: 500 }).catch(() => {});
      await page.addScriptTag({ content: AXE }).catch(() => {});
      const r = await page.evaluate(() => globalThis.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice'] }, resultTypes: ['violations'] })).catch((e) => ({ erro: String(e), violations: [] }));
      ecras++;
      if (r.erro) { console.log(`ERRO  ${w}px ${url}: ${r.erro.slice(0, 80)}`); continue; }
      for (const v of r.violations) achados.push({ url, largura: w, id: v.id, impacto: v.impact, ajuda: v.help, nos: v.nodes.length, exemplo: v.nodes[0]?.target?.join(' ').slice(0, 90), alvos: v.nodes.map((n) => (n.target?.join(' ') ?? '').slice(0, 80)), html: v.nodes[0]?.html?.slice(0, 110) });
      const graves = r.violations.filter((v) => ['critical', 'serious'].includes(v.impact));
      console.log(`${graves.length ? 'GRAVE' : 'ok   '} ${w}px ${url} (${r.violations.length} violações, ${graves.length} graves/críticas)`);
    }
    await ctx.close();
  }
}
await browser.close();

const porRegra = {};
for (const a of achados) {
  const k = `${a.impacto}:${a.id}`;
  porRegra[k] ??= { impacto: a.impacto, id: a.id, ajuda: a.ajuda, ocorrencias: 0, ecras: new Set(), exemplo: a.exemplo, html: a.html, alvos: {} };
  for (const t of a.alvos) porRegra[k].alvos[t] = (porRegra[k].alvos[t] ?? 0) + 1;
  porRegra[k].ocorrencias += a.nos; porRegra[k].ecras.add(a.url);
}
const resumo = Object.values(porRegra).map((r) => ({ ...r, ecras: [...r.ecras].length, urls: [...r.ecras].slice(0, 12), alvos: Object.entries(r.alvos).sort((a, b) => b[1] - a[1]).slice(0, 8) })).sort((a, b) => ['critical', 'serious', 'moderate', 'minor'].indexOf(a.impacto) - ['critical', 'serious', 'moderate', 'minor'].indexOf(b.impacto) || b.ocorrencias - a.ocorrencias);
const graves = resumo.filter((r) => ['critical', 'serious'].includes(r.impacto));
const contagem = (imp) => resumo.filter((r) => r.impacto === imp).reduce((s, r) => s + r.ocorrencias, 0);
const total = { critical: contagem('critical'), serious: contagem('serious'), moderate: contagem('moderate'), minor: contagem('minor') };
if (args.saida) writeFileSync(args.saida, JSON.stringify({ paginasAnalisadas: ecras, total, regras: resumo }, null, 1));
console.log(`\n${ecras} páginas analisadas. Ocorrências: crítico ${total.critical}, sério ${total.serious}, moderado ${total.moderate}, menor ${total.minor}.`);
for (const r of resumo) console.log(`  [${r.impacto}] ${r.id}: ${r.ocorrencias} nós em ${r.ecras} ecrã(s) — ${r.ajuda}\n      ${r.exemplo}`);
if (graves.length) process.exit(1);
