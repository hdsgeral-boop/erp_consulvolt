import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// Em desenvolvimento a API é a do nginx do Docker (:8080); em produção o nginx serve frontend/dist e /api.
export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  // ERP_API_ALVO permite apontar o servidor de desenvolvimento para o ambiente E2E (http://127.0.0.1:8081, dados fictícios).
  server: { port: 5173, proxy: { '/api': process.env.ERP_API_ALVO ?? 'http://127.0.0.1:8080' } },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    chunkSizeWarningLimit: 1500,
    rolldownOptions: {
      output: {
        // Bibliotecas de terceiros em fragmentos próprios e estáveis: mudam raramente, por isso o browser mantém-nos em
        // cache entre publicações (só o código da aplicação muda de nome). As páginas fora do caminho principal do ERP
        // são carregadas a pedido (React.lazy em src/App.tsx e nos registos de ecrãs de cada módulo).
        codeSplitting: {
          groups: [
            { name: 'react', test: /node_modules[\/](react|react-dom|scheduler|react-router)[\/]/, priority: 30 },
            { name: 'react-query', test: /node_modules[\/]@tanstack[\/]/, priority: 20 },
            { name: 'dayjs', test: /node_modules[\/]dayjs[\/]/, priority: 20 },
          ],
        },
      },
    },
  },
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/configuracao-testes.ts'] },
});
