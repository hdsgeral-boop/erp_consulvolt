#!/usr/bin/env node
/**
 * Auditoria de responsividade (guia: docs/FRONTEND_RESPONSIVIDADE.md; Implementar.txt secção 26).
 * Percorre o login, o portal, a consola /admin e /suporte e TODOS os ecrãs do catálogo de permissões (rota /m/{módulo}/{ecrã})
 * em 12 larguras e, nas ≤ 1024 px (telemóveis e tablets), também em paisagem. Entra com o utilizador FICTÍCIO do ambiente E2E.
 * Falha se, num ecrã/largura/orientação:
 *  - a página tiver barra horizontal (documentElement.scrollWidth > innerWidth);
 *  - o contentor do conteúdo (.erp-conteudo) tiver excesso horizontal;
 *  - houver valores (.erp-valor, .ant-statistic-content, células numéricas) com texto cortado;
 *  - houver botões/ligações/campos visíveis fora do viewport (sem contentor com deslocação) ou cortados por um contentor `overflow:hidden`;
 *  - houver texto cortado (overflow escondido sem reticências) em títulos, rótulos, botões e cabeçalhos de tabela.
 * Uso (raiz do repo): node ferramentas/qualidade/responsividade.mjs [--larguras=360,768] [--orientacao=retrato|paisagem|ambas]
 *   [--urls=/,/portal] [--modulo=rh] [--capturas=pasta] [--max-capturas=N] [--saida=ficheiro.json] [--paralelo=4]
 * Variáveis: E2E_URL (omissão http://127.0.0.1:8081), E2E_PALAVRA_PASSE (omissão a do seeder E2E, fictícia).
 */
import { createRequire } from 'node:module';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

const requireFe = createRequire(path.resolve('frontend/package.json'));
const { chromium } = requireFe('@playwright/test');

const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8081';
const SENHA = process.env.E2E_PALAVRA_PASSE ?? 'E2e#Teste2026';
// as 12 larguras do Implementar.txt (secção 26) e uma altura de referência em retrato
const VIEWPORTS = {
  320: 568, 360: 740, 375: 812, 390: 844, 430: 932, 768: 1024, 820: 1180, 1024: 1366, 1280: 720, 1366: 768, 1440: 900, 1920: 1080,
};
const LARGURAS = (args.larguras ?? Object.keys(VIEWPORTS).join(',')).split(',').map(Number);
const ORIENTACAO = args.orientacao ?? 'ambas';
const PARALELO = Number(args.paralelo ?? 4);
const CAPTURAS = args.capturas;
const MAX_CAPTURAS = Number(args['max-capturas'] ?? 40);

const catalogo = JSON.parse(readFileSync(path.resolve('backend/resources/permissoes/catalogo.json'), 'utf8'));
const ECRAS_CATALOGO = catalogo.modulos
  .filter((m) => !args.modulo || m.id === args.modulo)
  .flatMap((m) => m.ecras.map((e) => `/m/${m.id}/${e.id}`));
const OUTROS = ['/', '/inicio', '/dados-pessoais'];
const SEM_SESSAO = ['/portal', '/admin/login', '/suporte/login', '/termos', '/privacidade', '/recuperar-password'];
const COM_SESSAO = args.urls ? args.urls.split(',') : [...OUTROS, ...ECRAS_CATALOGO];

if (CAPTURAS) mkdirSync(CAPTURAS, { recursive: true });

/** Corre no browser: devolve as medições de uma página já carregada. */
function medir() {
  const J = innerWidth;
  const visivel = (el) => el.checkVisibility?.({ checkOpacity: true, checkVisibilityCSS: true }) ?? true;
  // separadores: o Tabs desloca-se por transform e oferece o menu «…» (ant-tabs-nav-operations); «⋯» do menu só aparece ao passar
  const ignorar = (el) => el.closest('[aria-hidden="true"], .ant-select-dropdown-hidden, .ant-drawer:not(.ant-drawer-open), .erp-saltar, .ant-tabs-nav-operations-hidden, .ant-tabs-nav-wrap, .erp-menu-pontos, .ant-tabs-dropdown-hidden, .ant-dropdown-hidden, .ant-popover-hidden, .ant-tooltip-hidden, .ant-notification, .ant-message, .ant-float-btn, [inert]');
  const rotulo = (el) => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''}[${(el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 24)}]`;

  const c = document.querySelector('.erp-conteudo');
  const cortados = [];
  document.querySelectorAll('.erp-valor, .ant-statistic-content, .erp-resumo-valores__valor, td.ant-table-cell-align-right').forEach((el) => {
    if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow !== 'visible') cortados.push((el.textContent || '').trim().slice(0, 30));
  });

  // botões, ligações e campos visíveis fora do viewport / cortados por um contentor
  const fora = [];
  document.querySelectorAll('button, a[href], input:not([type=hidden]), select, textarea, [role=button], [role=tab], [role=menuitem]').forEach((el) => {
    if (ignorar(el) || !visivel(el)) return;
    const r = el.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return;
    let cortadoPor = null;
    let desloca = false;
    for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
      const cs = getComputedStyle(p);
      const ox = cs.overflowX;
      if (ox === 'visible') continue;
      const pr = p.getBoundingClientRect();
      const sai = r.right > pr.right + 1 || r.left < pr.left - 1;
      if (!sai) continue;
      if (ox === 'auto' || ox === 'scroll') { desloca = true; break; }
      cortadoPor = p; break;
    }
    if (desloca) return;
    if (cortadoPor) { fora.push(`cortado por ${rotulo(cortadoPor)}: ${rotulo(el)}`); return; }
    if (r.right > J + 1 || r.left < -1) fora.push(`fora do ecrã (${Math.round(r.left)}..${Math.round(r.right)}): ${rotulo(el)}`);
  });

  // texto cortado sem reticências (as reticências com tooltip/title são aceites)
  const texto = [];
  document.querySelectorAll('h1, h2, h3, h4, label, button, .ant-btn, .ant-tag, .ant-table-thead th, .ant-card-head-title, .ant-menu-title-content, .ant-breadcrumb li, .ant-tabs-tab-btn, .ant-form-item-label > label').forEach((el) => {
    if (ignorar(el) || !visivel(el)) return;
    if (el.scrollWidth <= el.clientWidth + 1 || !(el.textContent || '').trim()) return;
    const cs = getComputedStyle(el);
    if (cs.overflowX === 'visible' || cs.overflowX === 'auto' || cs.overflowX === 'scroll') return;
    if (cs.textOverflow === 'ellipsis') return;
    texto.push(rotulo(el));
  });
  return { janela: J, doc: document.documentElement.scrollWidth, conteudo: c ? c.scrollWidth - c.clientWidth : 0, cortados, fora: [...new Set(fora)].slice(0, 6), texto: [...new Set(texto)].slice(0, 6), semConteudo: !c };
}

async function entrar(ctx, page) {
  const r = await page.request.post('/api/autenticacao/entrar', { data: { nome_utilizador: 'e2e.admin', palavra_passe: SENHA }, headers: { Accept: 'application/json' } });
  if (!r.ok()) throw new Error('Login E2E falhou (' + r.status() + ')');
  const dados = (await r.json()).dados;
  const empresa = dados.empresas?.[0]?.id;
  const csrf = (await ctx.cookies()).find((c) => c.name.endsWith('XSRF-TOKEN'))?.value ?? '';
  if (empresa) await page.request.put('/api/autenticacao/empresa-activa', { data: { empresa_id: empresa }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrf) } });
}

const browser = await chromium.launch();
const falhas = [];
const matriz = { ecras: new Set(), larguras: new Set(), execucoes: 0, ok: 0 };
let capturadas = 0;

/** Uma «tarefa» = uma largura × orientação: abre contexto, entra e percorre todos os ecrãs. */
async function tarefa(w, orient) {
  const alto = VIEWPORTS[w];
  const viewport = orient === 'paisagem' ? { width: alto, height: w } : { width: w, height: alto };
  const etiqueta = `${viewport.width}x${viewport.height}${orient === 'paisagem' ? 'L' : 'P'}`;
  for (const [autenticado, lista] of [[false, SEM_SESSAO], [true, COM_SESSAO]]) {
    if (args.urls && !autenticado) continue;
    const ctx = await browser.newContext({ viewport, locale: 'pt-PT', baseURL: BASE, hasTouch: w <= 1024, isMobile: w <= 600 });
    const page = await ctx.newPage();
    if (autenticado) await entrar(ctx, page);
    for (const url of lista) {
      await page.goto(url, { waitUntil: 'load' }).catch(() => {});
      await page.waitForLoadState('networkidle', { timeout: 2500 }).catch(() => {});
      await page.waitForTimeout(200);
      // o tour de boas-vindas (utilizador novo) é um modal que bloqueia o scroll e falseia as medições
      await page.getByRole('button', { name: 'Agora não' }).click({ timeout: 500 }).catch(() => {});
      const m = await page.evaluate(medir).catch((e) => ({ erro: String(e) }));
      const erros = [];
      if (m.erro) erros.push('medição falhou: ' + m.erro.slice(0, 80));
      else {
        if (m.doc > m.janela + 1) erros.push(`página com scroll horizontal (${m.doc} > ${m.janela})`);
        // as Row com gutter [16,16] saem 8 px do conteúdo (só preenchimento das colunas); só se avisa acima disso
        if (m.conteudo > 16) erros.push(`conteúdo excede em ${m.conteudo}px`);
        if (m.cortados.length) erros.push(`valores cortados: ${m.cortados.join(' | ')}`);
        if (m.fora.length) erros.push(`botões/campos inacessíveis: ${m.fora.join(' | ')}`);
        if (m.texto.length) erros.push(`texto cortado: ${m.texto.join(' | ')}`);
      }
      matriz.ecras.add(url); matriz.larguras.add(etiqueta); matriz.execucoes++;
      if (!erros.length) matriz.ok++;
      const representativo = ['/', '/m/geral/dashboard', '/m/vendas/vendas_faturacao', '/m/rh/colaboradores', '/m/config/config_licenca', '/portal', '/admin/login'].includes(url);
      if (CAPTURAS && (erros.length || (representativo && [320, 768, 1366].includes(w))) && capturadas < MAX_CAPTURAS) {
        capturadas++;
        await page.screenshot({ path: path.join(CAPTURAS, `${etiqueta}_${url.replace(/[^a-z0-9]+/gi, '_')}${erros.length ? '_FALHA' : ''}.png`) }).catch(() => {});
      }
      if (erros.length) falhas.push({ largura: etiqueta, url, erros });
      if (erros.length || args.verboso) console.log(`${erros.length ? 'FALHA' : 'ok   '} ${etiqueta} ${url}${erros.length ? ' -> ' + erros.join('; ') : ''}`);
    }
    await ctx.close();
  }
  console.log(`-- concluído ${etiqueta}`);
}

const tarefas = [];
for (const w of LARGURAS) {
  if (ORIENTACAO !== 'paisagem') tarefas.push([w, 'retrato']);
  if (ORIENTACAO !== 'retrato' && w <= 1024) tarefas.push([w, 'paisagem']);
}
const fila = [...tarefas];
await Promise.all(Array.from({ length: PARALELO }, async () => { for (let t; (t = fila.shift()); ) await tarefa(...t); }));
await browser.close();

const resumo = {
  ecras: matriz.ecras.size, combinacoesLarguraOrientacao: matriz.larguras.size, execucoes: matriz.execucoes, semFalhas: matriz.ok, comFalhas: falhas.length, falhas,
};
if (args.saida) writeFileSync(args.saida, JSON.stringify(resumo, null, 1));
console.log(`\n${resumo.ecras} ecrãs × ${resumo.combinacoesLarguraOrientacao} larguras/orientações = ${resumo.execucoes} execuções; ${resumo.comFalhas} com falhas.`);
if (falhas.length) process.exit(1);
console.log('Sem scroll horizontal, valores/texto cortados nem botões inacessíveis.');
