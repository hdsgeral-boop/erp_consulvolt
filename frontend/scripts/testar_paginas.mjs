import { chromium } from '@playwright/test';

async function main() {
  const browser = await chromium.launch({
    executablePath: 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    headless: true
  });
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

  console.log('1. Acedendo à Landing Page e capturando secções...');
  await page.goto('http://localhost:8080/', { waitUntil: 'networkidle' });

  // Screenshot dos Planos e Preços
  const elPrecos = await page.$('#precos');
  if (elPrecos) {
    await elPrecos.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'public/imagens/teste-planos.png' });
  }

  // Screenshot dos Testemunhos
  const elTestemunhos = await page.$('.cv-testimonials-section');
  if (elTestemunhos) {
    await elTestemunhos.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await page.screenshot({ path: 'public/imagens/teste-testemunhos.png' });
  }

  await browser.close();
  console.log('Capturas adicionais concluídas com sucesso!');
}

main().catch(err => {
  console.error('Erro:', err);
  process.exit(1);
});
