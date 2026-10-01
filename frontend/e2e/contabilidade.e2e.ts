import { expect, test } from '@playwright/test';
import { UTILIZADOR, entrarComo, escolherOpcao, esperarSucesso, hojeApi, numeroPt, semNotificacaoErro, vigiarErros } from './apoio';

/**
 * Contabilidade: lançamento manual equilibrado (realização de capital: D 431 Banco / C 511 Capital social, 250 000 Kz)
 * reflectido no balancete (ecrã e API), indicador de equilíbrio que impede gravar desequilibrado, e os mapas abrem e calculam.
 */
const VALOR = 250000;

test('lançamento manual equilibrado e o balancete a reflecti-lo', async ({ page }) => {
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.admin);
  const inicioAno = `${hojeApi().slice(0, 4)}-01-01`;
  type Bal = { linhas: { codigo_conta: string; debito: string; credito: string }[] };
  const balancete511 = async () =>
    (await api.get<Bal>('/contabilidade/relatorios/balancete', { data_inicio: inicioAno, data_fim: hojeApi(), filtro_contas: '511' })).linhas.find((l) => l.codigo_conta === '511');
  const antes = Number((await balancete511())?.credito ?? 0);

  await page.goto('/m/contab/lancamentos');
  await page.getByRole('button', { name: 'Novo lançamento' }).click();
  await expect(page.getByRole('heading', { name: 'Novo lançamento' })).toBeVisible();
  await escolherOpcao(page, page.locator('#diario_id'), /^OD — /, 'OD');
  await page.locator('#numero_documento').fill('ACTA-E2E-01');
  await page.locator('#descricao').fill('Realização de capital social (teste E2E)');
  await escolherOpcao(page, page.locator('#linhas_0_codigo_conta'), /^431 — /, '431');
  await page.locator('#linhas_0_valor').fill(String(VALOR));
  await escolherOpcao(page, page.locator('#linhas_1_codigo_conta'), /^511 — /, '511');
  // desequilibrado: não se grava
  await page.locator('#linhas_1_valor').fill(String(VALOR - 1));
  await expect(page.getByText('O lançamento só pode ser gravado com o total a débito igual ao total a crédito.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Gravar lançamento' })).toBeDisabled();
  await page.locator('#linhas_1_valor').fill(String(VALOR));
  await expect(page.getByText('O lançamento só pode ser gravado com o total a débito igual ao total a crédito.')).toHaveCount(0);
  await page.getByRole('button', { name: 'Gravar lançamento' }).click();
  await esperarSucesso(page);
  await expect(page).toHaveURL(/\/m\/contab\/lancamentos\/\d+$/);
  await expect(page.getByText('Realização de capital social (teste E2E)').first()).toBeVisible();

  // balancete no ecrã (filtrado à conta 511)
  await page.goto('/m/contab/contab_mapa_balancete');
  await page.locator('#filtro_contas').fill('511');
  await page.getByRole('button', { name: 'Calcular' }).click();
  const linha = page.locator('.ant-table-tbody tr.ant-table-row').filter({ has: page.getByText('511', { exact: true }) });
  await expect(linha).toHaveCount(1);
  const celulas = await linha.locator('td').allInnerTexts();
  // colunas: conta, descrição, saldo inicial, débito, crédito, saldo devedor, saldo credor
  expect(numeroPt(celulas[4])).toBe(antes + VALOR);
  expect(numeroPt(celulas[6])).toBe(antes + VALOR);

  // e pela API
  expect(Number((await balancete511())?.credito)).toBe(antes + VALOR);
  await semNotificacaoErro(page);
  expect(erros).toEqual([]);
  await api.fechar();
});

for (const [ecra, titulo] of [
  ['contab_mapa_balanco', 'Balanço'],
  ['contab_mapa_dr', 'Demonstração de resultados'],
  ['contab_mapa_extrato', 'Extracto'],
  ['contab_mapa_evolucao', 'Evolução'],
  ['contab_mapa_fluxo', 'Fluxos de caixa'],
] as const) {
  test(`mapa ${ecra} abre e calcula sem erros`, async ({ page }) => {
    const erros = vigiarErros(page);
    await entrarComo(page, UTILIZADOR.admin);
    await page.goto(`/m/contab/${ecra}`);
    await expect(page.getByRole('heading', { name: new RegExp(titulo, 'i') }).first()).toBeVisible();
    const calcular = page.getByRole('button', { name: 'Calcular' }).first();
    if (await calcular.isVisible()) {
      if (ecra === 'contab_mapa_extrato') await escolherOpcao(page, page.locator('.ant-select').filter({ hasText: /Conta/ }).locator('input').first(), /^511 — /, '511').catch(() => undefined);
      await calcular.click();
      await page.waitForLoadState('networkidle');
    }
    await semNotificacaoErro(page);
    expect(erros).toEqual([]);
  });
}
