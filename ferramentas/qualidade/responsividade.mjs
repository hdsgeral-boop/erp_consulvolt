#!/usr/bin/env node
/**
 * Auditoria de responsividade (guia: docs/FRONTEND_RESPONSIVIDADE.md).
 * Percorre os ecrãs principais em várias larguras, entra com o utilizador FICTÍCIO do ambiente E2E e falha se:
 *  - a página tiver barra horizontal (documentElement.scrollWidth > innerWidth);
 *  - o contentor do conteúdo (.erp-conteudo) tiver excesso horizontal;
 *  - houver valores (.erp-valor, .ant-statistic-content, células numéricas) com texto cortado (scrollWidth > clientWidth).
 * Uso (a partir da raiz do repo): node ferramentas/qualidade/responsividade.mjs [--capturas=pasta] [--larguras=360,768] [--urls=/,/portal]
 * Variáveis: E2E_URL (omissão http://127.0.0.1:8081), E2E_PALAVRA_PASSE (omissão a do seeder E2E, fictícia).
 */
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
import path from 'node:path';

const requireFe = createRequire(path.resolve('frontend/package.json'));
const { chromium } = requireFe('@playwright/test');

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8081';
const SENHA = process.env.E2E_PALAVRA_PASSE ?? 'E2e#Teste2026';
const LARGURAS = (args.larguras ?? '360,414,768,1024,1366,1920,2560').split(',').map(Number);
const ALTURA = { 360: 740, 414: 800, 768: 1024, 1024: 768, 1366: 768, 1920: 1080, 2560: 1440 };
const CAPTURAS = args.capturas;
const TODOS = [
  '/', '/m/geral/dashboard', '/m/config/config_licenca', '/m/vendas/vendas_faturacao', '/m/vendas/vendas_clientes',
  '/m/stock/armazem_stock', '/m/rh/colaboradores', '/m/contab/lancamentos', '/m/teso/teso_gestao_pagamentos',
  '/m/compras/compras_pedidos', '/m/crm/crm_pipeline', '/m/config/config_geral', '/m/suporte/suporte_tickets_geral',
  '/admin/login', '/portal',
];

const ECRAS = args.urls ? args.urls.split(',') : TODOS;
if (CAPTURAS) mkdirSync(CAPTURAS, { recursive: true });
const browser = await chromium.launch();
const falhas = [];

for (const w of LARGURAS) {
  const ctx = await browser.newContext({ viewport: { width: w, height: ALTURA[w] ?? 800 }, locale: 'pt-PT', baseURL: BASE });
  const page = await ctx.newPage();
  const r = await page.request.post('/api/autenticacao/entrar', { data: { nome_utilizador: 'e2e.admin', palavra_passe: SENHA }, headers: { Accept: 'application/json' } });
  if (!r.ok()) { console.error('Login E2E falhou (' + r.status() + ')'); process.exit(2); }
  const dados = (await r.json()).dados;
  const empresa = dados.empresas?.[0]?.id;
  const csrf = (await ctx.cookies()).find((c) => c.name.endsWith('XSRF-TOKEN'))?.value ?? '';
  if (empresa) await page.request.put('/api/autenticacao/empresa-activa', { data: { empresa_id: empresa }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf) } });

  for (const url of ECRAS) {
    await page.goto(url, { waitUntil: 'load' }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 6000 }).catch(() => {});
    await page.waitForTimeout(500);
    // o tour de boas-vindas (utilizador novo) é um modal que bloqueia o scroll e falseia as medições
    await page.getByRole('button', { name: 'Agora não' }).click({ timeout: 800 }).catch(() => {});
    await page.waitForTimeout(250);
    const m = await page.evaluate(() => {
      const c = document.querySelector('.erp-conteudo');
      const cortados = [];
      document.querySelectorAll('.erp-valor, .ant-statistic-content, .erp-resumo-valores__valor, td.ant-table-cell-align-right').forEach((el) => {
        if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow !== 'visible') cortados.push((el.textContent || '').trim().slice(0, 30));
      });
      return { janela: innerWidth, doc: document.documentElement.scrollWidth, conteudo: c ? c.scrollWidth - c.clientWidth : 0, cortados };
    });
    const erros = [];
    if (m.doc > m.janela + 1) erros.push(`página com scroll horizontal (${m.doc} > ${m.janela})`);
    // as Row com gutter [16,16] saem 8 px do conteúdo (só preenchimento das colunas); só se avisa acima disso
    if (m.conteudo > 16) erros.push(`conteúdo excede em ${m.conteudo}px`);
    if (m.cortados.length) erros.push(`valores cortados: ${m.cortados.join(' | ')}`);
    if (CAPTURAS) await page.screenshot({ path: path.join(CAPTURAS, `${w}_${url.replace(/[^a-z0-9]+/gi, '_')}.png`), fullPage: false });
    if (erros.length) falhas.push(`${w}px ${url}: ${erros.join('; ')}`);
    console.log(`${erros.length ? 'FALHA' : 'ok   '} ${w}px ${url}${erros.length ? ' -> ' + erros.join('; ') : ''}`);
  }
  await ctx.close();
}
await browser.close();
if (falhas.length) { console.error(`\n${falhas.length} falha(s) de responsividade.`); process.exit(1); }
console.log('\nSem scroll horizontal nem valores cortados.');
