import { expect, test } from '@playwright/test';
import { PALAVRA_PASSE, UTILIZADOR, api, entrarComo, escolherOpcao, esperarSucesso, vigiarErros } from './apoio';

/**
 * Configurações: criar um perfil com tarefas incompatíveis (fechar o cálculo dos salários + validar o processamento)
 * — o editor e o servidor avisam a segregação de funções e só grava com confirmação explícita — e criar um utilizador
 * com esse perfil, que consegue entrar e vê apenas o módulo de RH.
 */
test.describe.configure({ mode: 'serial' });

const PERFIL = 'Salários com validação (E2E)';
const NOVO = 'e2e.novo';
const SENHA_NOVA = 'Nova#Senha2026';

test('criar perfil com conflito de segregação: aviso no editor, confirmação no servidor e gravação', async ({ page }) => {
  const erros = vigiarErros(page);
  const a = await entrarComo(page, UTILIZADOR.admin);
  await page.goto('/m/config/config_perfis');
  await page.getByRole('button', { name: 'Novo perfil' }).click();
  const gaveta = page.locator('.ant-drawer-content').filter({ hasText: 'Novo perfil' });
  await expect(gaveta.getByText('Recursos Humanos e Salários')).toBeVisible();
  await gaveta.getByRole('textbox').first().fill(PERFIL);

  await gaveta.locator('.ant-collapse-header').filter({ hasText: 'Recursos Humanos e Salários' }).click();
  await gaveta.getByRole('checkbox', { name: /^Importar do mês anterior\/contratos\/produtividade e fechar o cálculo/ }).check();
  await gaveta.getByRole('checkbox', { name: /^Validar processamento/ }).check();
  // o resumo do editor já mostra o conflito (regra do catálogo)
  await expect(gaveta.getByText('Fecha o cálculo dos salários e valida-o.').first()).toBeVisible();

  await gaveta.getByRole('button', { name: 'Gravar' }).click();
  const confirmacao = page.locator('.ant-modal-confirm').filter({ hasText: 'Segregação de funções' });
  await expect(confirmacao).toBeVisible();
  await expect(confirmacao.getByText('Fecha o cálculo dos salários e valida-o.')).toBeVisible();
  await confirmacao.getByRole('button', { name: 'Gravar mesmo assim' }).click();
  await esperarSucesso(page);
  // aviso final: gravado com conflitos
  const aviso = page.locator('.ant-modal-confirm').filter({ hasText: 'Gravado com conflitos de segregação' });
  if (await aviso.isVisible().catch(() => false)) await aviso.getByRole('button', { name: /OK/i }).click();

  const perfis = await a.get<{ nome: string; id: number }[]>('/sistema/perfis');
  expect(perfis.map((p) => p.nome)).toContain(PERFIL);
  await expect(page.locator('.ant-table-tbody').getByText(PERFIL)).toBeVisible();
  expect(erros.filter((e) => !/status of 4(09|22)/.test(e) && !/HTTP 4(09|22)/.test(e))).toEqual([]);
  await a.fechar();
});

test('criar utilizador com o novo perfil, que entra e vê só o módulo de RH', async ({ page }) => {
  const erros = vigiarErros(page);
  await entrarComo(page, UTILIZADOR.admin);
  await page.goto('/m/config/config_utilizadores');
  await page.getByRole('button', { name: 'Novo utilizador' }).click();
  const modal = page.locator('.ant-modal').filter({ hasText: 'Novo utilizador' });
  await modal.locator('#nome_utilizador').fill(NOVO);
  await modal.locator('#nome_completo').fill('Utilizador Novo E2E');
  await modal.locator('#email').fill('novo-e2e@exemplo.invalid');
  await modal.locator('#palavra_passe').fill(SENHA_NOVA);
  await escolherOpcao(page, modal.locator('#perfil_utilizador_id'), PERFIL, 'Salários');
  await modal.getByRole('button', { name: 'Gravar' }).click();
  // o servidor pode pedir confirmação por o perfil ter conflitos de segregação
  const confirmacao = page.locator('.ant-modal-confirm');
  if (await confirmacao.isVisible({ timeout: 2000 }).catch(() => false)) await confirmacao.getByRole('button').last().click();
  await esperarSucesso(page);
  await expect(page.locator('.ant-table-tbody').getByText(NOVO, { exact: true })).toBeVisible();

  // o novo utilizador entra (API) e o menu só tem RH
  expect(SENHA_NOVA).not.toBe(PALAVRA_PASSE);
  const r = await page.request.post('/api/autenticacao/entrar', { data: { nome_utilizador: NOVO, palavra_passe: SENHA_NOVA } });
  expect(r.ok()).toBeTruthy();
  const { token, empresas } = (await r.json()).dados as { token: string; empresas: { id: number }[] };
  expect(empresas).toHaveLength(1);
  const menu = await page.request.get('/api/sistema/menu', { headers: { Authorization: `Bearer ${token}`, 'X-Empresa-Id': String(empresas[0].id), Accept: 'application/json' } });
  const modulos = ((await menu.json()).dados.menu as { id: string }[]).map((m) => m.id);
  expect(modulos).toEqual(['rh']);
  expect(erros.filter((e) => !/status of 4(09|22)/.test(e) && !/HTTP 4(09|22)/.test(e))).toEqual([]);
});

test('o utilizador sem permissão de configurações não lista utilizadores (403)', async () => {
  const v = await api(UTILIZADOR.vendas);
  await expect(v.get('/sistema/utilizadores')).rejects.toThrow(/403/);
  await v.fechar();
});
