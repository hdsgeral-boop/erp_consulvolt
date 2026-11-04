import { Button } from 'antd';
import { OrderedListOutlined, PlusCircleOutlined } from '@ant-design/icons';
import { useState, type ReactNode } from 'react';
import type { Terminal } from '../comum/tipos';
import { Ordens } from './Ordens';
import { Recepcao } from './Recepcao';

/**
 * Frente de caixa de um terminal de lavandaria, como no protótipo (lavRenderFrente, lavandaria.js:410-437): a barra da
 * sessão no topo e os separadores «Nova ordem de serviço» e «Ordens e levantamentos», na mesma sessão de caixa.
 */
export function FrenteLavandaria({ terminal, barraSessao }: { terminal: Terminal; barraSessao: ReactNode }) {
  const [aba, setAba] = useState<'nova' | 'ordens'>('nova');
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      {barraSessao}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }} role="tablist" aria-label="Lavandaria">
        <Button role="tab" aria-selected={aba === 'nova'} type={aba === 'nova' ? 'primary' : 'default'} icon={<PlusCircleOutlined />} onClick={() => setAba('nova')}>
          Nova ordem de serviço
        </Button>
        <Button role="tab" aria-selected={aba === 'ordens'} type={aba === 'ordens' ? 'primary' : 'default'} icon={<OrderedListOutlined />} onClick={() => setAba('ordens')}>
          Ordens e levantamentos
        </Button>
      </div>
      {aba === 'nova' ? <Recepcao terminal={terminal} /> : <Ordens terminal={terminal} aoNovaOrdem={() => setAba('nova')} />}
    </div>
  );
}
