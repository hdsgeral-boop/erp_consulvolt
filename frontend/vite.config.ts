import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// Em desenvolvimento a API é a do nginx do Docker (:8080); em produção o nginx serve frontend/dist e /api.
export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  // ERP_API_ALVO permite apontar o servidor de desenvolvimento para o ambiente E2E (http://127.0.0.1:8081, dados fictícios).
  server: { port: 5173, proxy: { '/api': process.env.ERP_API_ALVO ?? 'http://127.0.0.1:8080' } },
  build: { outDir: 'dist', emptyOutDir: true, chunkSizeWarningLimit: 1500 },
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/configuracao-testes.ts'] },
});
