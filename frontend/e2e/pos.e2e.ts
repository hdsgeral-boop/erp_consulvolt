import { expect, test, type Page } from '@playwright/test';
import { UTILIZADOR, entrarComo, esperarSucesso, numeroPt, semNotificacaoErro, vigiarErros } from './apoio';

/**
 * POS: o operador (perfil só de POS) abre a sessão no terminal T01 com fundo de 5 000 Kz, vende 2 × Artigo Demo A
 * (1 000 Kz com IVA) com pagamento misto TPA 1 500 + numerário 1 000 → troco 500, consulta o relatório X e faz o fecho Z
 * sem desvio (esperado 5 000 + 500 = 5 500). O administrador integra a sessão na contabilidade.
 */
test.describe.configure({ mode: 'serial' });

let sessaoId = 0;

const valorDescricao = async (page: Page, contexto: string, rotulo: string) =>
  page.locator(contexto).locator('.ant-descriptions-item-label', { hasText: new RegExp(`^${rotulo}$`) })
    .locator('xpath=following-sibling::*[contains(@class,"ant-descriptions-item-content")][1]').first().innerText();

test('operador abre a sessão, vende com pagamento misto e troco, vê o relatório X e faz o fecho Z sem desvio', async ({ page }) => {
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.pos);
  await page.goto('/m/pos/pos');
  await expect(page.getByText('Escolha o terminal deste posto de trabalho')).toBeVisible();
  await page.locator('.ant-card').filter({ hasText: 'Loja Demo' }).click();
  await expect(page.getByText('Sem sessão aberta')).toBeVisible();

  // abrir a sessão com o fundo de maneio padrão do terminal (5 000)
  await page.getByRole('button', { name: 'Abrir sessão' }).click();
  await esperarSucesso(page);
  // o operador aparece pelo nome completo (ADR-066), como no legado
  await expect(page.getByText(/Sessão T01-\d{4}-\d{4} · aberta por Operador POS E2E/)).toBeVisible();

  // venda: 2 × Artigo Demo A
  const botaoProduto = page.locator('button').filter({ hasText: 'Artigo Demo A (caixa)' });
  await botaoProduto.click();
  await botaoProduto.click();
  await expect(page.locator('.ant-card-head').filter({ hasText: /Carrinho ·\s*2\s*un\./ })).toBeVisible();
  expect(numeroPt(await page.locator('.ant-statistic').filter({ hasText: 'Total a pagar' }).locator('.ant-statistic-content-value').innerText())).toBe(2000);
  await page.getByRole('button', { name: 'Cobrar (F9)' }).click();

  const modal = page.locator('.ant-modal').filter({ hasText: 'Pagamento ·' });
  await expect(modal).toBeVisible();
  await modal.getByRole('button', { name: 'Multicaixa (TPA)' }).click();
  await modal.getByLabel('Valor Multicaixa (TPA)').fill('1500');
  await modal.getByLabel('Valor Numerário').fill('1000');
  await modal.getByLabel('Comprovativo Multicaixa (TPA)').fill('TPA-E2E-001');
  await expect(modal.locator('.ant-statistic').filter({ hasText: 'Troco' })).toContainText('500');
  await modal.getByRole('button', { name: 'Emitir factura-recibo (F9)' }).click();

  const resultado = page.locator('.ant-modal .ant-result-success');
  await expect(resultado).toBeVisible();
  await expect(resultado).toContainText(/Factura-recibo FR T01\S*\/\d+/);
  expect(numeroPt(await resultado.locator('.ant-statistic').filter({ hasText: 'Troco' }).locator('.ant-statistic-content-value').innerText())).toBe(500);
  await page.getByRole('button', { name: 'Nova venda' }).click();

  // confirmação pela API: a venda e os pagamentos (o troco abate ao numerário)
  const terminais = await api.get<{ id: number; codigo: string; sessao_aberta: { id: number } | null }[]>('/pos/terminais');
  sessaoId = terminais.find((t) => t.codigo === 'T01')!.sessao_aberta!.id;
  const x = await api.get<{ total_vendas: string; numerario_esperado: string; numero_vendas: number; vendas_numerario: string }>(`/pos/sessoes/${sessaoId}/relatorio-x`);
  expect(x).toMatchObject({ total_vendas: '2000.00', vendas_numerario: '500.00', numerario_esperado: '5500.00', numero_vendas: 1 });

  // relatório X no ecrã
  await page.getByRole('button', { name: 'Relatório X' }).click();
  const relX = page.locator('.ant-modal').filter({ hasText: 'Relatório X' });
  await expect(relX).toBeVisible();
  expect(numeroPt(await valorDescricao(page, '.ant-modal:has-text("Relatório X")', 'Total de vendas'))).toBe(2000);
  expect(numeroPt(await valorDescricao(page, '.ant-modal:has-text("Relatório X")', 'Numerário esperado'))).toBe(5500);
  await relX.locator('.ant-modal-close').click();

  // fecho Z: numerário pelo total (5 500) e talão do TPA igual ao sistema (1 500) → sem desvio
  await page.getByRole('button', { name: 'Fecho Z' }).click();
  const z = page.locator('.ant-modal').filter({ hasText: 'Fecho de caixa (Z)' });
  await expect(z.getByText('Totais por meio de pagamento')).toBeVisible();
  await z.getByText('Pelo total').click();
  await z.getByLabel('Total contado').fill('5500');
  await z.getByLabel('Valor do talão Multicaixa (TPA)').fill('1500');
  await expect(z.getByText('Sem desvio.')).toBeVisible();
  await z.getByRole('button', { name: 'Fechar sessão (Z)' }).click();
  await page.locator('.ant-modal-confirm').getByRole('button', { name: 'Fechar sessão' }).click();
  await expect(z.locator('.ant-result-success')).toContainText(/Sessão fechada: Z-T01-\d{4}-\d{4}/);
  // regressão: a lista de terminais recarrega (a sessão deixa de estar aberta) e o resultado do Z continua visível
  await expect(page.getByText('Sem sessão aberta')).toBeVisible();
  await expect(z.locator('.ant-result-success')).toBeVisible();
  await expect(z.getByRole('button', { name: 'Imprimir relatório Z' })).toBeVisible();
  await z.getByRole('button', { name: 'Concluir' }).click();
  await expect(z).toBeHidden();

  const sessao = await api.get<{ estado: string; desvio: string; numerario_contado: string }>(`/pos/sessoes/${sessaoId}`);
  expect(sessao).toMatchObject({ estado: 'FECHADA', desvio: '0.00', numerario_contado: '5500.00' });
  await semNotificacaoErro(page);
  expect(erros).toEqual([]);
  await api.fechar();
});

test('administrador integra a sessão fechada na contabilidade', async ({ page }) => {
  expect(sessaoId, 'depende da sessão do teste anterior').toBeGreaterThan(0);
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.admin);
  await page.goto('/m/pos/pos_integracao');
  const linha = page.locator('.ant-table-tbody tr.ant-table-row').filter({ hasText: /Z-T01-/ });
  await expect(linha).toHaveCount(1);
  await linha.getByRole('button', { name: 'Integrar' }).click();
  await page.locator('.ant-modal-confirm').getByRole('button', { name: 'Integrar' }).click();
  await esperarSucesso(page);

  const sessao = await api.get<{ estado_contabilizacao: string; lans_contabilizacao: string[] | null }>(`/pos/sessoes/${sessaoId}`);
  expect(sessao.estado_contabilizacao).toBe('CONTABILIZADA');
  expect(sessao.lans_contabilizacao?.length).toBeGreaterThan(0);
  // a lista (filtro «PENDENTE») deixa de a mostrar
  await expect(linha).toHaveCount(0);
  expect(erros).toEqual([]);
  await api.fechar();
});
