import { defineConfig, devices } from '@playwright/test';

/**
 * Testes ponta-a-ponta (Playwright) contra o ambiente ISOLADO E2E (docker-compose.e2e.yml):
 * nginx em 127.0.0.1:8081 → app_e2e → base erp_consulvolt_e2e com dados fictícios. Nunca contra :8080 (desenvolvimento).
 *
 * Os ficheiros de teste chamam-se *.e2e.ts (não *.spec.ts/*.test.ts) para o Vitest não os apanhar.
 * Os fluxos partilham a mesma base (sessões de POS, períodos salariais, numeração): correm em série, num só worker.
 * O globalSetup recria a base (erp:e2e:preparar) antes de cada execução; E2E_SEM_PREPARAR=1 salta esse passo.
 */
const baseURL = process.env.E2E_URL ?? 'http://127.0.0.1:8081';

export default defineConfig({
  testDir: './e2e',
  testMatch: /.*\.e2e\.ts$/,
  globalSetup: './e2e/preparacao-global.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 15_000 },
  forbidOnly: !!process.env.CI,
  outputDir: './test-results',
  reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]],
  use: {
    baseURL,
    locale: 'pt-PT',
    timezoneId: 'Africa/Luanda',
    viewport: { width: 1440, height: 900 },
    actionTimeout: 15_000,
    navigationTimeout: 30_000,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    video: 'off',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
});
