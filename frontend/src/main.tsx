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
import '@/estilos/global.css';
import '@/estilos/identidade.css';

dayjs.locale('pt');

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
