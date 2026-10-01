import { expect, test, type Page } from '@playwright/test';
import { UTILIZADOR, api, entrarComo, escolherOpcao, esperarSucesso, semNotificacaoErro, vigiarErros } from './apoio';

/**
 * Compras, ciclo completo com os escalões do seeder (até 1 000 000 Kz: um só nível de aprovação):
 * pedido (administrador) → aprovação (e2e.aprovador, outro utilizador: ninguém aprova os próprios pedidos) →
 * proposta do fornecedor → proposta de adjudicação → adjudicação (gera a encomenda) → recepção → validação da entrada
 * em stock → factura do fornecedor → contabilização. 10 × Artigo Demo B a 1 300 Kz = 13 000 + IVA 1 820 = 14 820 Kz.
 */
test.describe.configure({ mode: 'serial' });

let pedidoId = 0;
let encomendaId = 0;

const idDoEndereco = (page: Page) => Number(new URL(page.url()).pathname.split('/').pop());

test('o comprador cria o pedido interno, que fica a aguardar aprovação', async ({ page }) => {
  const erros = vigiarErros(page);
  await entrarComo(page, UTILIZADOR.admin);
  await page.goto('/m/compras/compras_pedidos');
  await page.getByRole('button', { name: 'Novo pedido' }).click();
  await expect(page.getByText('Novo pedido interno')).toBeVisible();
  await page.locator('#descricao').fill('Reposição de stock do Artigo Demo B (teste E2E)');
  await escolherOpcao(page, page.locator('#linhas_0_produto_id'), 'E2E-A02', 'E2E-A02');
  await page.locator('#linhas_0_quantidade').fill('10');
  await page.locator('#linhas_0_preco_unitario').fill('1400');
  await page.getByRole('button', { name: 'Criar e enviar para aprovação' }).click();
  await esperarSucesso(page);
  await expect(page).toHaveURL(/\/m\/compras\/compras_pedidos\/\d+$/);
  pedidoId = idDoEndereco(page);
  await expect(page.getByRole('heading', { name: /^Pedido PC / })).toBeVisible();
  await expect(page.locator('.ant-tag').filter({ hasText: /^Pendente$/ }).first()).toBeVisible();
  expect(erros).toEqual([]);
});

test('o aprovador (outro utilizador) aprova o pedido', async ({ page }) => {
  const erros = vigiarErros(page);
  await entrarComo(page, UTILIZADOR.aprovador);
  await page.goto(`/m/compras/compras_pedidos/${pedidoId}`);
  await page.getByRole('button', { name: 'Decidir' }).click();
  const modal = page.locator('.ant-modal').filter({ hasText: 'Decisão' });
  await expect(modal.getByText('Aprovar')).toBeVisible();
  await modal.getByRole('button', { name: 'Confirmar decisão' }).click();
  await esperarSucesso(page);
  await expect(page.locator('.ant-tag').filter({ hasText: /^Aprovado$/ }).first()).toBeVisible();
  expect(erros).toEqual([]);
});

test('proposta do fornecedor, proposta de adjudicação e adjudicação geram a encomenda', async ({ page }) => {
  const erros = vigiarErros(page);
  const a = await entrarComo(page, UTILIZADOR.admin);
  await page.goto(`/m/compras/compras_pedidos/${pedidoId}`);
  await page.getByRole('button', { name: 'Registar proposta' }).click();
  await expect(page.getByText('Registar proposta de fornecedor')).toBeVisible();
  await expect(page.locator('#linhas_0_preco_unitario')).toBeVisible();
  await escolherOpcao(page, page.locator('#fornecedor_id'), 'Fornecedor Demo Ómega', 'Ómega');
  await page.locator('#referencia').fill('PROP-E2E-001');
  await page.locator('#linhas_0_preco_unitario').fill('1300');
  await page.getByRole('button', { name: 'Registar proposta' }).click();
  await esperarSucesso(page);
  await expect(page.getByRole('heading', { name: /^Proposta PP / })).toBeVisible();

  await page.getByRole('button', { name: 'Propor adjudicação' }).click();
  await esperarSucesso(page);
  await page.getByRole('button', { name: 'Adjudicar' }).click();
  await page.locator('.ant-modal').getByRole('button', { name: 'Adjudicar e gerar encomenda' }).click();
  await expect(page).toHaveURL(/\/m\/compras\/compras_encomendas\/\d+$/);
  encomendaId = idDoEndereco(page);
  await expect(page.getByRole('heading', { name: /^Encomenda EC / })).toBeVisible();

  const enc = await a.get<{ montante_total: string; total_com_imposto: string; estado: string }>(`/compras/encomendas/${encomendaId}`);
  expect(enc).toMatchObject({ montante_total: '13000.00', total_com_imposto: '14820.00' });
  const pedido = await a.get<{ estado: string }>(`/compras/pedidos/${pedidoId}`);
  expect(pedido.estado).toBe('ADJUDICADO');
  expect(erros).toEqual([]);
  await a.fechar();
});

test('recepção da mercadoria e validação da entrada em stock', async ({ page }) => {
  const erros = vigiarErros(page);
  const a = await entrarComo(page, UTILIZADOR.admin);
  await page.goto(`/m/compras/compras_encomendas/${encomendaId}`);
  await page.getByRole('button', { name: 'Registar recepção' }).click();
  const modal = page.locator('.ant-modal').filter({ hasText: 'Registar recepção — encomenda' });
  await modal.locator('#numero_entrega').fill('GR-FORN-E2E-1');
  await modal.getByRole('button', { name: 'Registar recepção' }).click();
  await esperarSucesso(page);

  const linhaRececao = page.locator('.ant-card').filter({ hasText: 'Recepções' }).locator('.ant-table-tbody tr.ant-table-row').first();
  await expect(linhaRececao).toContainText('GR-FORN-E2E-1');
  await linhaRececao.click();
  await expect(page).toHaveURL(/\/m\/compras\/compras_rececoes\/\d+$/);
  const rececaoId = idDoEndereco(page);
  await page.getByRole('button', { name: 'Validar entrada em stock' }).click();
  await page.locator('.ant-modal').getByRole('button', { name: 'Validar' }).click();
  // a mensagem da recepção ainda pode estar visível: espera-se pelo estado novo
  await expect(page.locator('.ant-tag').filter({ hasText: /^Validado$/ }).first()).toBeVisible();

  const r = await a.get<{ estado: string; valor_total_kz: string }>(`/compras/rececoes/${rececaoId}`);
  expect(r).toMatchObject({ estado: 'VALIDADO', valor_total_kz: '13000.00' });
  await semNotificacaoErro(page);
  expect(erros).toEqual([]);
  await a.fechar();
});

test('factura do fornecedor sobre a encomenda e contabilização', async ({ page }) => {
  const erros = vigiarErros(page);
  const a = await entrarComo(page, UTILIZADOR.admin);
  await page.goto(`/m/compras/compras_encomendas/${encomendaId}`);
  await page.getByRole('button', { name: 'Registar factura' }).click();
  const modal = page.locator('.ant-modal').filter({ hasText: 'Registar factura — encomenda' });
  await modal.locator('#numero_fatura').fill('FT-FORN-E2E-1');
  await modal.getByRole('button', { name: 'Registar factura' }).click();
  await esperarSucesso(page);
  // depois de registada, abre-se o detalhe da factura
  await expect(page).toHaveURL(/\/m\/compras\/compras_faturacao\/\d+$/);
  await expect(page.getByRole('heading', { name: 'Factura FT-FORN-E2E-1' })).toBeVisible();
  const faturaId = idDoEndereco(page);
  await page.getByRole('button', { name: 'Contabilizar' }).click();
  await esperarSucesso(page);
  await expect(page.getByRole('button', { name: 'Descontabilizar' })).toBeVisible();

  const f = await a.get<{ montante_total: string; contabilizado: boolean; numero_lan_contabilizacao: string }>(`/compras/faturas/${faturaId}`);
  expect(f).toMatchObject({ montante_total: '14820.00', contabilizado: true });
  expect(f.numero_lan_contabilizacao).toBeTruthy();
  const enc = await a.get<{ estado: string }>(`/compras/encomendas/${encomendaId}`);
  expect(enc.estado).toBe('RECEBIDO');
  expect(erros).toEqual([]);
  await a.fechar();
});

test('o aprovador não vê outros módulos de compras além dos pedidos', async () => {
  const a = await api(UTILIZADOR.aprovador);
  const r = await a.get<{ menu: { id: string; ecras: { id: string }[] }[] }>('/sistema/menu');
  expect(r.menu.map((m) => m.id)).toEqual(['compras']);
  expect(r.menu[0].ecras.map((e) => e.id)).toEqual(['compras_pedidos']);
  await a.fechar();
});
