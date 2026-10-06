import { expect, test, type Page } from '@playwright/test';
import { UTILIZADOR, entrarComo, numeroPt, semNotificacaoErro, vigiarErros } from './apoio';

/**
 * RH/Salários com o utilizador de RH (perfil sem validação nem contabilização): abrir o período do mês corrente,
 * importar os lançamentos dos contratos (3 colaboradores × 3 rubricas), ver os resultados calculados e os totais,
 * e abrir os mapas de IRT e de remunerações. Colaboradora Ana: 300 000 base + 40 000 alimentação + 25 000 transporte →
 * INSS 9 000, base IRT 301 000 (excesso de 10 000 da alimentação; o transporte fica abaixo dos 30 000) → IRT 49 440.
 */
test.describe.configure({ mode: 'serial' });

let periodoId = 0;

const totalResumo = async (page: Page, rotulo: string) =>
  numeroPt(await page.locator('.ant-descriptions-item-label', { hasText: new RegExp(`^${rotulo}$`) }).locator('xpath=following-sibling::*[1]').innerText());

test('abrir o período salarial, importar dos contratos, calcular e ver os resultados', async ({ page }) => {
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.rh);
  await page.goto('/m/rh/calcular');
  await page.getByRole('button', { name: 'Abrir período' }).click();
  await page.locator('.ant-modal').filter({ hasText: 'Abrir período salarial' }).getByRole('button', { name: 'Abrir' }).click();
  await expect(page).toHaveURL(/\/m\/rh\/calcular\/\d+$/);
  periodoId = Number(new URL(page.url()).pathname.split('/').pop());
  await expect(page.getByRole('heading', { name: /^Processamento \d{2}\/\d{4}$/ })).toBeVisible();

  await page.getByRole('button', { name: /Importar( down)?$/ }).click();
  await page.getByText('Importar dos contratos').click();
  await page.locator('.ant-modal-confirm').getByRole('button', { name: 'Importar' }).click();
  await expect(page.getByText(/Importação dos contratos: 9 lançamento\(s\) criado\(s\)/)).toBeVisible();
  await page.locator('.ant-modal').filter({ hasText: 'Resultado' }).getByRole('button', { name: 'Fechar' }).click();
  await expect(page.getByRole('tab', { name: 'Lançamentos (9)' })).toBeVisible();

  await page.getByRole('tab', { name: /^Resultados \(3\)$/ }).click();
  const tabela = page.locator('.ant-tabs-tabpane-active .ant-table-tbody');
  await expect(tabela.locator('tr.ant-table-row')).toHaveCount(3);
  await expect(tabela).toContainText('Colaboradora Demo Ana Fictícia');

  const detalhe = await api.get<{ estado: string; fotografia: boolean; totais: Record<string, string>; resultados: { colaborador_id: number; irt: string; liquido: string; inss_trabalhador: string; bruto: string }[] }>(`/rh/salarios/periodos/${periodoId}`);
  expect(detalhe.estado).toBe('ABERTO');
  expect(detalhe.fotografia).toBe(false);
  // a colaboradora Ana (o maior salário): valores de referência do motor salarial
  const ana = detalhe.resultados.find((r) => r.bruto === '365000.00');
  expect(ana).toMatchObject({ inss_trabalhador: '9000.00', irt: '49440.00', liquido: '306560.00' });
  const somaLiquido = detalhe.resultados.reduce((s, r) => s + Number(r.liquido), 0);
  expect(Number(detalhe.totais.liquido)).toBeCloseTo(somaLiquido, 2);

  // o resumo no ecrã mostra os mesmos totais do servidor
  expect(await totalResumo(page, 'Bruto')).toBe(Number(detalhe.totais.bruto));
  expect(await totalResumo(page, 'IRT')).toBe(Number(detalhe.totais.irt));
  expect(await totalResumo(page, 'Líquido a pagar')).toBe(Number(detalhe.totais.liquido));
  await semNotificacaoErro(page);
  expect(erros).toEqual([]);
  await api.fechar();
});

for (const [ecra, titulo] of [
  ['rh_rel_irt', 'Mapa de IRT'],
  ['rh_rel_remuneracoes', 'Mapa de remunerações'],
  ['rh_rel_inss', 'Mapa de Segurança Social'],
] as const) {
  test(`${titulo} abre com o período calculado`, async ({ page }) => {
    const erros = vigiarErros(page);
    await entrarComo(page, UTILIZADOR.rh);
    await page.goto(`/m/rh/${ecra}`);
    await expect(page.getByRole('heading', { name: titulo }).first()).toBeVisible();
    await page.waitForLoadState('networkidle');
    await expect(page.getByText('Colaboradora Demo Ana Fictícia').first()).toBeVisible();
    await semNotificacaoErro(page);
    expect(erros).toEqual([]);
  });
}
