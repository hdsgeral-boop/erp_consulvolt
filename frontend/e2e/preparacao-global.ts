import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

/**
 * Antes de cada execução: recria a base E2E (migrate:fresh + dados fictícios) no serviço app_e2e e confirma que o
 * nginx E2E responde. O comando recusa correr se a base não terminar em "_e2e" (a base de desenvolvimento nunca é tocada).
 * E2E_SEM_PREPARAR=1 salta a recriação (útil para repetir um único ficheiro de teste).
 */
export default async function preparacaoGlobal(): Promise<void> {
  const raiz = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
  if (process.env.E2E_SEM_PREPARAR !== '1') {
    const inicio = Date.now();
    execFileSync(
      'docker',
      ['compose', '-f', 'docker-compose.yml', '-f', 'docker-compose.e2e.yml', 'exec', '-T', 'app_e2e', 'php', 'artisan', 'erp:e2e:preparar', '--force'],
      { cwd: raiz, stdio: ['ignore', 'ignore', 'inherit'], timeout: 300_000 },
    );
    console.log(`Base E2E recriada em ${((Date.now() - inicio) / 1000).toFixed(1)} s`);
  }
  const url = process.env.E2E_URL ?? 'http://127.0.0.1:8081';
  const r = await fetch(`${url}/api/sistema/logotipo-login`).catch(() => null);
  if (!r || r.status >= 500) {
    throw new Error(`O ambiente E2E não responde em ${url}. Arranque-o: docker compose -f docker-compose.yml -f docker-compose.e2e.yml up -d app_e2e web_e2e`);
  }
}
