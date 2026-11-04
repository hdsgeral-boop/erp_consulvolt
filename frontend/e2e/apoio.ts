import { expect, request as pedidoApi, type APIRequestContext, type Locator, type Page } from '@playwright/test';

/** Dados fictícios criados por Database\Seeders\E2E\E2ESeeder (erp:e2e:preparar). */
export const PALAVRA_PASSE = 'E2e#Teste2026';
export const EMPRESA_DEMO = 'Demo E2E Comércio, Lda';
export const EMPRESA_VAZIA = 'Demo E2E Serviços, Lda';
export const UTILIZADOR = {
  admin: 'e2e.admin',
  vendas: 'e2e.vendas',
  pos: 'e2e.pos',
  rh: 'e2e.rh',
  aprovador: 'e2e.aprovador',
} as const;

const URL_BASE = process.env.E2E_URL ?? 'http://127.0.0.1:8081';

export const hojeApi = (): string => {
  const d = new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Luanda' }));
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** Cliente da API autenticado como um utilizador e com a empresa de demonstração activa (confirmações e preparação). */
export interface Api {
  empresaId: number;
  token: string;
  get: <T = unknown>(url: string, params?: Record<string, string | number>) => Promise<T>;
  post: <T = unknown>(url: string, dados?: unknown) => Promise<{ estado: number; corpo: { sucesso: boolean; mensagem: string; codigo?: string; dados: T } }>;
  fechar: () => Promise<void>;
}

export async function api(utilizador: string, empresa = EMPRESA_DEMO): Promise<Api> {
  const ctx: APIRequestContext = await pedidoApi.newContext({ baseURL: URL_BASE, extraHTTPHeaders: { Accept: 'application/json' } });
  const r = await ctx.post('/api/autenticacao/entrar', { data: { nome_utilizador: utilizador, palavra_passe: PALAVRA_PASSE } });
  expect(r.ok(), `login API de ${utilizador}`).toBeTruthy();
  const dados = (await r.json()).dados as { empresas: { id: number; nome: string }[] };
  // O token já não vem no corpo (ADR-082): só no cookie HttpOnly. Estes clientes de API usam-no como Bearer (sem CSRF, sem rotação).
  const cookies = (await ctx.storageState()).cookies;
  const bruto = cookies.find((c) => c.name.endsWith('erp_token'))?.value ?? '';
  const token = decodeURIComponent(bruto);
  const empresaId = dados.empresas.find((e) => e.nome === empresa)?.id;
  if (!empresaId) throw new Error(`${utilizador} não tem acesso a ${empresa}`);
  const cab = { Authorization: `Bearer ${token}`, 'X-Empresa-Id': String(empresaId) };
  return {
    empresaId,
    token,
    get: async (url, params) => {
      const g = await ctx.get(`/api${url}`, { headers: cab, params });
      const corpo = await g.json();
      if (!g.ok()) throw new Error(`GET ${url} → ${g.status()}: ${corpo?.mensagem}`);
      return corpo.dados;
    },
    post: async (url, dados) => {
      const p = await ctx.post(`/api${url}`, { headers: cab, data: dados ?? {} });
      return { estado: p.status(), corpo: await p.json() };
    },
    fechar: () => ctx.dispose(),
  };
}

/** Erros de consola, excepções da página e respostas 5xx da API — para afirmar no fim de cada ecrã. */
export function vigiarErros(page: Page): string[] {
  const erros: string[] = [];
  page.on('console', (m) => {
    if (m.type() === 'error') erros.push(`consola: ${m.text()}`);
  });
  page.on('pageerror', (e) => erros.push(`excepção: ${e.message}`));
  page.on('response', (r) => {
    if (r.url().includes('/api/') && r.status() >= 500) erros.push(`HTTP ${r.status()} ${r.request().method()} ${r.url()}`);
  });
  return erros;
}

/** Entrada pelo ecrã de login (o fluxo completo, como um utilizador). */
export async function entrarPeloFormulario(page: Page, utilizador: string, palavraPasse = PALAVRA_PASSE): Promise<void> {
  await page.goto('/');
  await page.getByLabel('Utilizador').fill(utilizador);
  await page.getByLabel('Palavra-passe').fill(palavraPasse);
  await page.getByRole('button', { name: 'Entrar' }).click();
}

/** Escolhe a empresa no ecrã de escolha (a seguir ao login). */
export async function escolherEmpresa(page: Page, empresa = EMPRESA_DEMO): Promise<void> {
  const item = page.locator('.ant-list-item').filter({ hasText: empresa });
  await item.getByRole('button', { name: 'Entrar' }).click();
  await expect(page.locator('.ant-layout-sider')).toBeVisible();
}

/**
 * Atalho para os fluxos de negócio: o login pela API do próprio navegador (page.request partilha os cookies com a página:
 * token e dispositivo HttpOnly) e a empresa activa definida na SESSÃO do servidor (PUT /autenticacao/empresa-activa) —
 * nada é escrito no armazenamento do navegador (ADR-082). O login pelo formulário tem o seu próprio teste.
 */
export async function entrarComo(page: Page, utilizador: string, empresa = EMPRESA_DEMO): Promise<Api> {
  const a = await api(utilizador, empresa);
  const r = await page.request.post('/api/autenticacao/entrar', { data: { nome_utilizador: utilizador, palavra_passe: PALAVRA_PASSE }, headers: { Accept: 'application/json' } });
  expect(r.ok(), `login no navegador de ${utilizador}`).toBeTruthy();
  const csrf = (await page.context().cookies()).find((c) => c.name.endsWith('XSRF-TOKEN'))?.value ?? '';
  const e = await page.request.put('/api/autenticacao/empresa-activa', { data: { empresa_id: a.empresaId }, headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf } });
  expect(e.ok(), 'empresa activa da sessão').toBeTruthy();
  await page.goto('/');
  // a barra superior existe em todas as larguras (em telemóvel o menu lateral passa a gaveta)
  await expect(page.locator('.ant-layout-header')).toBeVisible();
  return a;
}

export async function abrirEcra(page: Page, modulo: string, ecra: string, resto = ''): Promise<void> {
  await page.goto(`/m/${modulo}/${ecra}${resto}`);
}

/** Abre um Select do Ant Design (pelo campo) e escolhe a opção com o texto indicado (pesquisa se o select o permitir). */
export async function escolherOpcao(page: Page, campo: Locator, texto: string | RegExp, pesquisa?: string): Promise<void> {
  await campo.click({ force: true });   // o texto do valor escolhido cobre o input do Select
  if (pesquisa) await campo.fill(pesquisa);
  const opcao = page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: texto }).first();
  await expect(opcao).toBeVisible();
  await opcao.click();
}

/** Mensagem de sucesso (message.success) visível. */
export async function esperarSucesso(page: Page, texto?: string | RegExp): Promise<void> {
  const m = page.locator('.ant-message-notice .ant-message-success');
  await expect(texto ? m.filter({ hasText: texto }).first() : m.first()).toBeVisible();
}

/** Nenhuma notificação de erro do Ant Design (notificarErro) aberta. */
export async function semNotificacaoErro(page: Page): Promise<void> {
  await expect(page.locator('.ant-notification-notice-error')).toHaveCount(0);
}

/** Converte "1 234,56" / "1.234,56" / "1234.56" (formatação pt) em número. */
export function numeroPt(texto: string): number {
  const limpo = texto.replace(/[^\d,.-]/g, '');
  if (limpo.includes(',')) return Number(limpo.replace(/\./g, '').replace(',', '.'));
  return Number(limpo);
}
