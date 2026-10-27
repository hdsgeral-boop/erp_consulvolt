import { Dropdown, Tooltip } from 'antd';
import { FlagOutlined } from '@ant-design/icons';
import { useAccao } from '@/componentes/Accoes';
import { CORES_FLAG, FLAGS, ROTULOS_FLAG } from './flags';
import type { CorFlag } from './tipos';

/** Ponto colorido da bandeira actual (sem acção; para mostrar no cartão/linha sem abrir o selector). */
export function PontoFlag({ flag }: { flag?: CorFlag | null }) {
  if (!flag) return null;
  return (
    <Tooltip title={ROTULOS_FLAG[flag]}>
      <span style={{ display: 'inline-block', width: 9, height: 9, borderRadius: '50%', background: CORES_FLAG[flag], marginRight: 2 }} aria-label={`Bandeira: ${ROTULOS_FLAG[flag]}`} />
    </Tooltip>
  );
}

/** Selector de bandeira (verde/amarelo/vermelho/preto): clicar abre o menu, sem modal. Chama guardarTarefa só com `flag`. */
export function SeletorFlag({ projecto, tarefa, flag }: { projecto: number; tarefa: number; flag?: CorFlag | null }) {
  const accao = useAccao({ invalidar: [['projectos']], tituloErro: 'Não foi possível gravar a bandeira' });
  const definir = (v: CorFlag | null) => accao.mutate({ metodo: 'put', url: `/projetos/${projecto}/tarefas/${tarefa}`, dados: { flag: v } });
  const items = [
    ...FLAGS.map((c) => ({
      key: c,
      label: (
        <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <span style={{ display: 'inline-block', width: 10, height: 10, borderRadius: '50%', background: CORES_FLAG[c] }} />
          {ROTULOS_FLAG[c]}
        </span>
      ),
      onClick: () => definir(c),
    })),
    { type: 'divider' as const },
    { key: 'limpar', label: 'Sem bandeira', disabled: !flag, onClick: () => definir(null) },
  ];
  return (
    <Dropdown menu={{ items }} trigger={['click']}>
      <Tooltip title={flag ? ROTULOS_FLAG[flag] : 'Marcar bandeira de atenção'}>
        <button type="button" onClick={(e) => e.stopPropagation()} aria-label="Bandeira de atenção"
          style={{ border: 'none', background: 'transparent', cursor: 'pointer', padding: 2, display: 'inline-flex', alignItems: 'center' }}>
          {flag
            ? <span style={{ display: 'inline-block', width: 11, height: 11, borderRadius: '50%', background: CORES_FLAG[flag] }} />
            : <FlagOutlined style={{ color: '#cbd5e1' }} />}
        </button>
      </Tooltip>
    </Dropdown>
  );
}
