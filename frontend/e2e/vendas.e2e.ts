import { expect, test, type Page } from '@playwright/test';
import { UTILIZADOR, entrarComo, escolherOpcao, esperarSucesso, numeroPt, semNotificacaoErro, vigiarErros, type Api } from './apoio';

/**
 * Vendas › Facturação: FT com 2 linhas (artigo com stock + serviço), detalhe e contabilização; FR paga no acto;
 * nota de crédito parcial sobre a FT. Valores dos dados fictícios: E2E-A01 1 000 Kz, E2E-A02 2 500 Kz, E2E-S01 5 000 Kz, IVA 14 %.
 */
test.describe.configure({ mode: 'serial' });

let ftId = 0;
let ftNumero = '';

const idDoEndereco = (page: Page) => Number(new URL(page.url()).pathname.split('/').pop());
const totalDoDetalhe = async (page: Page, rotulo: string) =>
  numeroPt(await page.locator('.ant-descriptions-bordered .ant-descriptions-row').filter({ hasText: rotulo }).locator('.ant-descriptions-item-content').innerText());

async function novoDocumento(page: Page) {
  await page.goto('/m/vendas/vendas_faturacao');
  await page.getByRole('button', { name: 'Novo documento' }).click();
  await expect(page.getByText('Novo documento de venda')).toBeVisible();
}

async function linha(page: Page, indice: number, produto: string, quantidade: number) {
  await escolherOpcao(page, page.locator(`#linhas_${indice}_produto_id`), produto, produto);
  await page.locator(`#linhas_${indice}_quantidade`).fill(String(quantidade));
}

test('emitir uma factura (FT) com 2 linhas, ver o detalhe e contabilizar', async ({ page }) => {
  const erros = vigiarErros(page);
  const api: Api = await entrarComo(page, UTILIZADOR.admin);
  await novoDocumento(page);

  await escolherOpcao(page, page.locator('#cliente_id'), 'Cliente Demo Alfa', 'Alfa');
  await linha(page, 0, 'E2E-A01', 2);
  await page.getByRole('button', { name: 'Acrescentar linha' }).click();
  await linha(page, 1, 'E2E-S01', 1);
  // estimativa no ecrã: 2 × 1 000 + 5 000 = 7 000 + IVA 980 = 7 980
  await expect(page.locator('.ant-statistic').filter({ hasText: 'Total (estimativa)' })).toContainText('7');
  expect(numeroPt(await page.locator('.ant-statistic').filter({ hasText: 'Total (estimativa)' }).locator('.ant-statistic-content').innerText())).toBe(7980);

  await page.getByRole('button', { name: /^Emitir factura$/ }).click();
  await esperarSucesso(page);
  await expect(page).toHaveURL(/\/m\/vendas\/vendas_faturacao\/\d+$/);
  ftId = idDoEndereco(page);
  ftNumero = (await page.locator('h1, h2, h3, h4').filter({ hasText: /^FT / }).first().innerText()).trim();
  expect(ftNumero).toMatch(/^FT \S+\/\d+$/);

  // detalhe: cliente, 2 linhas, totais calculados pelo servidor, por contabilizar, AGT preparado
  await expect(page.getByText('Cliente Demo Alfa, Lda')).toBeVisible();
  await expect(page.locator('.ant-table-tbody tr.ant-table-row')).toHaveCount(2);
  expect(await totalDoDetalhe(page, 'Líquido')).toBe(7000);
  expect(await totalDoDetalhe(page, 'IVA')).toBe(980);
  expect(await totalDoDetalhe(page, 'Total')).toBe(7980);
  expect(await totalDoDetalhe(page, 'Pendente')).toBe(7980);
  await expect(page.getByText('Por contabilizar')).toBeVisible();

  await page.getByRole('button', { name: 'Contabilizar' }).click();
  await esperarSucesso(page);
  await expect(page.getByText(/^Contabilizado \(/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Descontabilizar' })).toBeVisible();

  // confirmação pela API: estado, totais e lançamento gerado
  const doc = await api.get<{ estado: string; total_bruto: string; contabilizado: boolean; numero_lan_contabilizacao: string; numero_documento: string }>(`/vendas/documentos/${ftId}`);
  expect(doc).toMatchObject({ estado: 'PENDENTE', total_bruto: '7980.00', contabilizado: true, numero_documento: ftNumero });
  expect(doc.numero_lan_contabilizacao).toBeTruthy();
  await semNotificacaoErro(page);
  expect(erros).toEqual([]);
  await api.fechar();
});

test('emitir uma factura-recibo (FR) paga em numerário', async ({ page }) => {
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.admin);
  await novoDocumento(page);

  await escolherOpcao(page, page.locator('#tipo_documento'), /^FR — /);
  await escolherOpcao(page, page.locator('#cliente_id'), 'Cliente Demo Beta', 'Beta');
  await page.locator('#conta_disponibilidade').fill('451');
  await page.locator('.ant-select-dropdown:not(.ant-select-dropdown-hidden) .ant-select-item-option').filter({ hasText: '451 — Caixa principal' }).click();
  await linha(page, 0, 'E2E-A02', 1);
  await page.getByRole('button', { name: /^Emitir factura-recibo$/ }).click();
  await esperarSucesso(page);
  await expect(page).toHaveURL(/\/m\/vendas\/vendas_faturacao\/\d+$/);
  const id = idDoEndereco(page);

  await expect(page.locator('h1, h2, h3, h4').filter({ hasText: /^FR / }).first()).toBeVisible();
  await expect(page.locator('.ant-tag').filter({ hasText: 'PAGO' })).toBeVisible();
  expect(await totalDoDetalhe(page, 'Total')).toBe(2850);
  const doc = await api.get<{ estado: string; valor_pendente: string; total_bruto: string }>(`/vendas/documentos/${id}`);
  expect(doc).toMatchObject({ estado: 'PAGO', valor_pendente: '0.00', total_bruto: '2850.00' });
  expect(erros).toEqual([]);
  await api.fechar();
});

test('nota de crédito (NC) parcial sobre a FT abate o pendente', async ({ page }) => {
  expect(ftId, 'depende da FT do primeiro teste').toBeGreaterThan(0);
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.admin);
  await novoDocumento(page);

  await escolherOpcao(page, page.locator('#tipo_documento'), /^NC — /);
  await escolherOpcao(page, page.locator('#cliente_id'), 'Cliente Demo Alfa', 'Alfa');
  await escolherOpcao(page, page.locator('#venda_origem_id'), ftNumero);
  await page.locator('#motivo_nota_credito').fill('Devolução parcial de uma caixa (teste E2E)');
  await linha(page, 0, 'E2E-A01', 1);
  await page.getByRole('button', { name: /^Emitir nota de crédito$/ }).click();
  await esperarSucesso(page);
  await expect(page).toHaveURL(/\/m\/vendas\/vendas_faturacao\/\d+$/);

  await expect(page.locator('h1, h2, h3, h4').filter({ hasText: /^NC / }).first()).toBeVisible();
  await expect(page.getByText('Devolução parcial de uma caixa (teste E2E)')).toBeVisible();
  expect(await totalDoDetalhe(page, 'Total')).toBe(1140);

  // a FT fica parcialmente regularizada: 7 980 − 1 140 = 6 840
  const ft = await api.get<{ estado: string; valor_pendente: string }>(`/vendas/documentos/${ftId}`);
  expect(ft).toMatchObject({ estado: 'PARCIAL', valor_pendente: '6840.00' });

  // e a lista mostra os três documentos
  await page.goto('/m/vendas/vendas_faturacao');
  for (const tipo of ['FT', 'FR', 'NC']) await expect(page.locator('.ant-table-tbody .ant-tag').filter({ hasText: new RegExp(`^${tipo}$`) }).first()).toBeVisible();
  expect(erros).toEqual([]);
  await api.fechar();
});
