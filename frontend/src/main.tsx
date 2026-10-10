import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App as AntApp, ConfigProvider } from 'antd';
import ptPT from 'antd/locale/pt_PT';
import dayjs from 'dayjs';
import 'dayjs/locale/pt';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SessaoProvider } from '@/sessao/SessaoContexto';
import { App } from '@/App';
import { TEMA } from '@/estilos/tema';
// Fonte Inter alojada no próprio servidor (o legado carregava-a do Google Fonts; a CSP só permite font-src 'self').
import '@fontsource/inter/latin-300.css';
import '@fontsource/inter/latin-400.css';
import '@fontsource/inter/latin-500.css';
import '@fontsource/inter/latin-600.css';
import '@fontsource/inter/latin-700.css';
import '@fontsource/inter/latin-800.css';
import '@/estilos/global.css';
import '@/estilos/identidade.css';

dayjs.locale('pt');

// O «Modo responsivo» foi retirado (2026-10-10): apaga a preferência antiga que ficou guardada nos postos
try {
  localStorage.removeItem('erp.modoResponsivo');
} catch {
  /* armazenamento indisponível */
}

// Depois de uma nova publicação os fragmentos antigos deixam de existir: recarrega uma vez para obter o index.html novo
// (sem isto o ecrã fica em branco). A marca na sessionStorage evita um ciclo de recargas se o fragmento faltar mesmo.
window.addEventListener('vite:preloadError', (e) => {
  try {
    if (sessionStorage.getItem('erp_recarga_fragmento') === location.pathname) return;
    sessionStorage.setItem('erp_recarga_fragmento', location.pathname);
  } catch {
    return;
  }
  e.preventDefault();
  location.reload();
});
window.addEventListener('load', () => {
  try {
    setTimeout(() => sessionStorage.removeItem('erp_recarga_fragmento'), 10_000);
  } catch {
    /* sem sessionStorage */
  }
});

const cliente = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false } },
});

createRoot(document.getElementById('raiz')!).render(
  <StrictMode>
    <ConfigProvider locale={ptPT} theme={TEMA}>
      <AntApp>
        <QueryClientProvider client={cliente}>
          <SessaoProvider>
            <App />
          </SessaoProvider>
        </QueryClientProvider>
      </AntApp>
    </ConfigProvider>
  </StrictMode>,
);
