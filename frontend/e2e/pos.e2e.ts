import { expect, test, type Page } from '@playwright/test';
import { UTILIZADOR, entrarComo, esperarSucesso, numeroPt, semNotificacaoErro, vigiarErros } from './apoio';

/**
 * POS: o operador (perfil só de POS) entra na frente de caixa imersiva, abre a sessão no terminal T01 com fundo de 5 000 Kz, vende 2 × Artigo Demo A
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
  // cartão «Frente de Caixa Imersiva» → ambiente imersivo (PC / Tablet / Mobile, «Voltar ao Office ERP»)
  await expect(page.getByText('Frente de Caixa Imersiva')).toBeVisible();
  await page.getByRole('button', { name: 'Iniciar Ponto de Venda (POS)' }).click();
  const imersivo = page.locator('.pos-imersivo');
  await expect(imersivo.getByText('Ambiente Imersivo Dedicado')).toBeVisible();
  await expect(imersivo.getByRole('button', { name: 'Voltar ao Office ERP' })).toBeVisible();

  // abertura de caixa: terminal, fundo de maneio padrão do terminal (5 000) e operador
  await expect(imersivo.getByRole('heading', { name: 'Abertura de Caixa' })).toBeVisible();
  // um único terminal activo: já vem escolhido no selector «Terminal POS / Caixa»
  await expect(imersivo.locator('.ant-select-selection-item').first()).toContainText('Loja Demo (T01');
  expect(numeroPt(await imersivo.getByLabel('Fundo de Maneio / Valor Inicial (Kz)').inputValue())).toBe(5000);
  // o operador aparece pelo nome completo (ADR-066), como no legado
  await expect(imersivo.getByText('Operador POS E2E')).toBeVisible();
  await imersivo.getByRole('button', { name: 'Abrir Caixa & Iniciar Vendas' }).click();
  await esperarSucesso(page);
  // barra da sessão: fundo, vendas acumuladas, sessão e operador, «Fechar Caixa»
  await expect(imersivo.locator('.pos-sessao-info')).toContainText(/T01-\d{4}-\d{4}/);
  await expect(imersivo.locator('.pos-sessao-info')).toContainText('Operador POS E2E');
  await expect(imersivo.locator('.pos-saldo-sessao')).toContainText(/Fundo:\s*5\s?000,00 Kz/);
  await expect(imersivo.getByRole('button', { name: 'Fechar Caixa' })).toBeVisible();

  // venda: 2 × Artigo Demo A (cartão com «Stock: 100»)
  const botaoProduto = imersivo.locator('button.pos-cartao-produto').filter({ hasText: 'Artigo Demo A (caixa)' });
  await expect(botaoProduto).toContainText('Stock: 100');
  await botaoProduto.click();
  await botaoProduto.click();
  await expect(imersivo.locator('.pos-carrinho h3')).toContainText(/Carrinho\s*·\s*2\s*un\./);
  expect(numeroPt(await imersivo.locator('.pos-total-geral').innerText())).toBe(2000);
  await imersivo.getByRole('button', { name: 'Checkout' }).click();

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
  // vendas acumuladas na barra da sessão (relatório X do servidor)
  await expect(page.locator('.pos-saldo-sessao')).toContainText(/Vendas:\s*2\s?000,00 Kz/);
  await page.getByRole('button', { name: 'Fechar Caixa' }).click();
  const z = page.locator('.ant-modal').filter({ hasText: 'Fecho de caixa' });
  await expect(z.getByText('Vendas da sessão por meio de pagamento')).toBeVisible();
  await z.getByText('Pelo total').click();
  await z.getByLabel('Total contado').fill('5500');
  await z.getByLabel('Valor do talão Multicaixa (TPA)').fill('1500');
  await expect(z.getByText(/^0,00 Kz \(dentro da tolerância\)$/)).toBeVisible();
  await z.getByRole('button', { name: /Fechar caixa e emitir Z$/ }).click();
  await page.locator('.ant-modal-confirm').getByRole('button', { name: 'Fechar sessão' }).click();
  await expect(z.locator('.ant-result-success')).toContainText(/Sessão fechada: Z-T01-\d{4}-\d{4}/);
  // regressão: a lista de terminais recarrega (a sessão deixa de estar aberta: volta a «Abertura de Caixa») e o resultado do Z continua visível
  await expect(page.getByRole('heading', { name: 'Abertura de Caixa' })).toBeVisible();
  await expect(z.locator('.ant-result-success')).toBeVisible();
  await expect(z.getByRole('button', { name: 'Imprimir relatório Z' })).toBeVisible();
  await z.getByRole('button', { name: 'Concluir' }).click();
  await expect(z).toBeHidden();
  await page.getByRole('button', { name: 'Voltar ao Office ERP' }).click();
  await expect(page.getByText('Frente de Caixa Imersiva')).toBeVisible();

  const sessao = await api.get<{ estado: string; desvio: string; numerario_contado: string }>(`/pos/sessoes/${sessaoId}`);
  expect(sessao).toMatchObject({ estado: 'FECHADA', desvio: '0.00', numerario_contado: '5500.00' });
  await semNotificacaoErro(page);
  expect(erros).toEqual([]);
  await api.fechar();
});

test('administrador pré-visualiza e integra a sessão fechada na contabilidade', async ({ page }) => {
  expect(sessaoId, 'depende da sessão do teste anterior').toBeGreaterThan(0);
  const erros = vigiarErros(page);
  const api = await entrarComo(page, UTILIZADOR.admin);
  await page.goto('/m/pos/pos_integracao');
  // secção «Integração contabilística das sessões do POS»: as sessões por integrar vêm marcadas
  const seccao = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Integração contabilística das sessões do POS' }) });
  const linha = seccao.locator('.ant-table-tbody tr.ant-table-row').filter({ hasText: /Z-T01-/ });
  await expect(linha).toHaveCount(1);
  await expect(linha.locator('.ant-checkbox-input')).toBeChecked();
  await seccao.getByRole('button', { name: 'Pré-visualizar e contabilizar' }).click();
  const previa = page.locator('.ant-modal').filter({ hasText: 'Pré-visualização da integração' });
  await expect(previa.getByTestId('bloco-sessao')).toContainText(/Z-T01-/);
  await previa.getByRole('button', { name: /Contabilizar 1 sessão/ }).click();
  await expect(page.locator('.ant-modal-confirm').filter({ hasText: 'Contabilizadas' })).toBeVisible();
  await page.locator('.ant-modal-confirm .ant-btn-primary').click();

  const sessao = await api.get<{ estado_contabilizacao: string; lans_contabilizacao: string[] | null }>(`/pos/sessoes/${sessaoId}`);
  expect(sessao.estado_contabilizacao).toBe('CONTABILIZADA');
  expect(sessao.lans_contabilizacao?.length).toBeGreaterThan(0);
  // a lista das sessões por integrar deixa de a mostrar e passa a «Sessões contabilizadas»
  await expect(linha).toHaveCount(0);
  const feitas = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Sessões contabilizadas' }) });
  await expect(feitas.locator('.ant-table-tbody tr.ant-table-row').filter({ hasText: /Z-T01-/ })).toHaveCount(1);
  expect(erros).toEqual([]);
  await api.fechar();
});
