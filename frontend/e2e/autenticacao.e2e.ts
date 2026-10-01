import { expect, test } from '@playwright/test';
import { EMPRESA_DEMO, EMPRESA_VAZIA, UTILIZADOR, entrarPeloFormulario, escolherEmpresa, semNotificacaoErro, vigiarErros } from './apoio';

test.describe('Autenticação, empresa activa e menu por permissões', () => {
  test('credenciais inválidas são recusadas com mensagem e sem sessão', async ({ page }) => {
    const erros = vigiarErros(page);
    await entrarPeloFormulario(page, UTILIZADOR.admin, 'palavra-errada');
    await expect(page.locator('.ant-alert-error')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
    expect(await page.evaluate(() => window.localStorage.getItem('erp.token'))).toBeNull();
    // o 401/422 do login é esperado; não pode haver excepções nem 5xx
    expect(erros.filter((e) => !e.startsWith('consola: Failed to load resource'))).toEqual([]);
  });

  test('campos obrigatórios validados no formulário', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('button', { name: 'Entrar' }).click();
    await expect(page.getByText('Indique o utilizador.')).toBeVisible();
    await expect(page.getByText('Indique a palavra-passe.')).toBeVisible();
  });

  test('administrador entra, escolhe a empresa, troca de empresa e termina a sessão', async ({ page }) => {
    const erros = vigiarErros(page);
    await entrarPeloFormulario(page, UTILIZADOR.admin);
    await expect(page.getByText('Bem-vindo, Administrador E2E')).toBeVisible();
    await expect(page.locator('.ant-list-item')).toHaveCount(2);
    await expect(page.getByText(EMPRESA_VAZIA)).toBeVisible();
    await escolherEmpresa(page, EMPRESA_DEMO);

    const menu = page.locator('.ant-layout-sider');
    for (const modulo of ['Vendas e Facturação', 'Recursos Humanos e Salários', 'Contabilidade', 'Configurações', 'POS, Lavandaria e Hotelaria']) {
      await expect(menu.getByText(modulo, { exact: true })).toBeVisible();
    }
    await expect(page.getByRole('combobox', { name: 'Empresa activa' })).toBeVisible();
    await expect(page.locator('.ant-select-selection-item').filter({ hasText: EMPRESA_DEMO })).toBeVisible();

    // trocar de empresa pelo selector do cabeçalho
    await page.getByRole('combobox', { name: 'Empresa activa' }).click({ force: true });
    await page.locator('.ant-select-item-option').filter({ hasText: EMPRESA_VAZIA }).click();
    await expect(page.locator('.ant-select-selection-item').filter({ hasText: EMPRESA_VAZIA })).toBeVisible();
    expect(await page.evaluate(() => window.localStorage.getItem('erp.empresa'))).not.toBeNull();

    await page.getByText('Administrador E2E').hover();
    await page.getByText('Terminar sessão').click();
    await expect(page.getByRole('button', { name: 'Entrar' })).toBeVisible();
    expect(await page.evaluate(() => window.localStorage.getItem('erp.token'))).toBeNull();
    await semNotificacaoErro(page);
    expect(erros).toEqual([]);
  });

  test('utilizador só de vendas vê apenas Vendas (sem RH nem Configurações) e o servidor recusa o resto', async ({ page }) => {
    const erros = vigiarErros(page);
    await entrarPeloFormulario(page, UTILIZADOR.vendas);
    // só tem uma empresa: escolhe-a
    await expect(page.locator('.ant-list-item')).toHaveCount(1);
    await escolherEmpresa(page, EMPRESA_DEMO);

    const menu = page.locator('.ant-layout-sider');
    await expect(menu.getByText('Vendas e Facturação', { exact: true })).toBeVisible();
    for (const proibido of ['Recursos Humanos e Salários', 'Configurações', 'Contabilidade', 'Compras e Aprovisionamento', 'Tesouraria']) {
      await expect(menu.getByText(proibido, { exact: true })).toHaveCount(0);
    }
    await menu.getByText('Vendas e Facturação', { exact: true }).click();
    await expect(menu.getByText('Facturação', { exact: true })).toBeVisible();

    // acesso directo pelo endereço a um ecrã de RH: «Sem acesso»
    await page.goto('/m/rh/colaboradores');
    await expect(page.getByText('Sem acesso')).toBeVisible();
    await page.goto('/m/config/config_utilizadores');
    await expect(page.getByText('Sem acesso')).toBeVisible();

    // e a API também recusa (403), independentemente do menu
    const token = await page.evaluate(() => window.localStorage.getItem('erp.token'));
    const empresa = await page.evaluate(() => window.localStorage.getItem('erp.empresa'));
    const r = await page.request.get('/api/rh/colaboradores', { headers: { Authorization: `Bearer ${token}`, 'X-Empresa-Id': String(empresa), Accept: 'application/json' } });
    expect(r.status()).toBe(403);
    expect(erros).toEqual([]);
  });
});
