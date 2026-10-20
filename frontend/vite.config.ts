import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';

// O frontend não tem HTML de entrada: as páginas são views Blade do Laravel (backend/resources/views/erp.blade.php e
// plataforma.blade.php) que carregam estas duas entradas com @vite(). Em produção os activos compilados (frontend/dist)
// são servidos em /build/... (o plugin define base '/build/') e o manifesto fica em dist/manifest.json, montado no
// contentor PHP em public/build. Em desenvolvimento (`npm run dev`) o plugin escreve o ficheiro "hot" em
// backend/public/hot e o @vite aponta para o servidor do Vite (HMR).
// ERP_API_ALVO permite apontar o servidor de desenvolvimento para o ambiente E2E (http://127.0.0.1:8081, dados fictícios).
// O plugin do Laravel só serve ao build e ao servidor de desenvolvimento: nos testes (Vitest, mode 'test') fica de fora,
// porque muda a base das URLs ('/build/') e liga o servidor, o que faz testes pesados de ecrãs ficarem sem terminar.
export default defineConfig(({ mode }) => ({
  plugins: [
    ...(mode === 'test' || process.env.VITEST
      ? []
      : [
          laravel({
            input: ['src/main.tsx', 'src/plataforma/main.tsx'],
            hotFile: '../backend/public/hot',
            buildDirectory: 'build',
          }),
        ]),
    react(),
  ],
  resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
  server: { port: 5173, proxy: { '/api': process.env.ERP_API_ALVO ?? 'http://127.0.0.1:8080' } },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: 'manifest.json',
    chunkSizeWarningLimit: 1500,
  },
  test: { environment: 'jsdom', globals: true, setupFiles: ['./src/configuracao-testes.ts'] },
}));
