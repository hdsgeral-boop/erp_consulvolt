import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App as AntApp, ConfigProvider } from 'antd';
import ptPT from 'antd/locale/pt_PT';
import dayjs from 'dayjs';
import 'dayjs/locale/pt';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SessaoProvider } from '@/sessao/SessaoContexto';
import { TEMA } from '@/estilos/tema';
import { PlataformaApp } from '@/plataforma/PlataformaApp';
import '@fontsource/inter/latin-400.css';
import '@fontsource/inter/latin-600.css';
import '@fontsource/inter/latin-700.css';
import '@/estilos/global.css';
import '@/estilos/identidade.css';
import '@/estilos/escalaResponsiva.css';

dayjs.locale('pt');

// Depois de uma nova publicação os fragmentos antigos deixam de existir: recarrega uma vez (como o ERP).
window.addEventListener('vite:preloadError', (e) => {
  try {
    if (sessionStorage.getItem('plataforma_recarga_fragmento') === location.pathname) return;
    sessionStorage.setItem('plataforma_recarga_fragmento', location.pathname);
  } catch {
    return;
  }
  e.preventDefault();
  location.reload();
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
            <PlataformaApp />
          </SessaoProvider>
        </QueryClientProvider>
      </AntApp>
    </ConfigProvider>
  </StrictMode>,
);
