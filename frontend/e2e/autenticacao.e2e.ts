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

    // menu com a estrutura do legado: itens gerais no topo, «Menu Geral» (módulos) e «Sistema» (configurações)
    const menu = page.locator('.ant-layout-sider');
    for (const item of ['Início', 'Dashboard Global', 'Relatórios de Gestão', 'Fluxo de Processos', 'Menu Geral', 'Sistema']) {
      await expect(menu.getByText(item, { exact: true })).toBeVisible();
    }
    await menu.getByText('Menu Geral', { exact: true }).click();
    for (const modulo of ['Logística', 'Vendas', 'Compras', 'Salários e RH', 'Tesouraria', 'Contabilidade']) {
      await expect(menu.getByText(modulo, { exact: true })).toBeVisible();
    }
    const nomeEmpresa = page.locator('.erp-cabecalho-empresa-nome');
    await expect(nomeEmpresa).toHaveText(EMPRESA_DEMO);

    // trocar de empresa directamente pela seta «Empresa activa» ao lado de «Trocar empresa»
    await page.getByRole('button', { name: 'Empresa activa' }).click();
    await page.locator('.ant-dropdown-menu-item').filter({ hasText: EMPRESA_VAZIA }).click();
    await expect(nomeEmpresa).toHaveText(EMPRESA_VAZIA);
    expect(await page.evaluate(() => window.localStorage.getItem('erp.empresa'))).not.toBeNull();

    // «Trocar empresa» volta à escolha de empresa (como no legado)
    await page.getByRole('button', { name: 'Trocar empresa' }).click();
    await expect(page.locator('.ant-list-item')).toHaveCount(2);
    await escolherEmpresa(page, EMPRESA_DEMO);
    await expect(nomeEmpresa).toHaveText(EMPRESA_DEMO);

    // o botão ▾ junto ao nome mostra as opções; «Gerir Dados Pessoais» é a primeira
    await page.getByRole('button', { name: 'Opções do utilizador' }).click();
    const opcoes = page.locator('.ant-dropdown:not(.ant-dropdown-hidden) .ant-dropdown-menu-item');
    await expect(opcoes.first()).toHaveText('Gerir Dados Pessoais');
    await opcoes.filter({ hasText: 'Terminar sessão' }).click();
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
    await expect(menu.getByText('Sistema', { exact: true })).toHaveCount(0);
    await menu.getByText('Menu Geral', { exact: true }).click();
    await expect(menu.getByText('Vendas', { exact: true })).toBeVisible();
    for (const proibido of ['Salários e RH', 'Contabilidade', 'Compras', 'Tesouraria', 'Logística']) {
      await expect(menu.getByText(proibido, { exact: true })).toHaveCount(0);
    }
    await menu.getByText('Vendas', { exact: true }).click();
    await expect(menu.getByText('Faturação', { exact: true })).toBeVisible();

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
