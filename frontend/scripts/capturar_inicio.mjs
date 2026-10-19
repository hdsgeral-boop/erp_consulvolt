import { chromium } from '@playwright/test';
import path from 'path';
import fs from 'fs';

async function capturar() {
  console.log('A iniciar Chrome local...');
  const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true,
  });

  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 2
  });

  const page = await context.newPage();

  console.log('Navegando para http://localhost:8080/entrar ...');
  await page.goto('http://localhost:8080/entrar');
  await page.waitForLoadState('networkidle');

  console.log('Preenchendo credenciais admin...');
  await page.locator('input[id*="nome_utilizador"]').fill('admin');
  await page.locator('input[id*="palavra_passe"]').fill('admin');
  await page.locator('button[type="submit"]').click();

  await page.waitForTimeout(2000);
  console.log('Página após login:', page.url());

  // Navegar explicitamente para http://localhost:8080/app
  console.log('Navegando para http://localhost:8080/app ...');
  await page.goto('http://localhost:8080/app');
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(2000);

  console.log('Procurando empresa Consulvolt (ID 6, não holding)...');
  // Filtra o item da lista que tem CONSULVOLT - COMERCIO e clica no seu botão Entrar
  const rowConsulvolt = page.locator('.ant-list-item').filter({ hasText: 'CONSULVOLT - COMERCIO' });
  if (await rowConsulvolt.count() > 0) {
    console.log('Linha encontrada! Clicando no botão Entrar...');
    await rowConsulvolt.first().getByRole('button', { name: 'Entrar' }).click();
  } else {
    console.log('Tentando pesquisa no campo de texto...');
    await page.getByPlaceholder('Pesquisar empresa por nome ou NIF').fill('CONSULVOLT');
    await page.waitForTimeout(1000);
    const itemPesquisado = page.locator('.ant-list-item').filter({ hasText: 'CONSULVOLT - COMERCIO' });
    await itemPesquisado.first().getByRole('button', { name: 'Entrar' }).click();
  }

  console.log('Aguardando transição para o ecrã Início...');
  await page.waitForTimeout(4000);
  console.log('URL após seleção:', page.url());

  // Força navegação para /inicio se ainda não estiver
  if (!page.url().includes('inicio')) {
    await page.goto('http://localhost:8080/inicio');
    await page.waitForTimeout(3000);
  }

  // Espera carregar o ecrã de início ou os módulos
  await page.waitForSelector('.erp-inicio, .ant-layout-content, #root', { timeout: 20000 });
  await page.waitForTimeout(4000);

  const pastaDestino = 'c:/xampp/htdocs/ERP_CONSULVOLT_MEU/erp_laravel/frontend/public/imagens';
  if (!fs.existsSync(pastaDestino)) {
    fs.mkdirSync(pastaDestino, { recursive: true });
  }

  const ficheiroScreenshot = path.join(pastaDestino, 'ecra-inicio-erp.png');
  await page.screenshot({ path: ficheiroScreenshot, fullPage: false });
  console.log('Sucesso! Captura salva em:', ficheiroScreenshot);

  await browser.close();
}

capturar().catch((err) => {
  console.error('Erro na captura:', err);
  process.exit(1);
});
