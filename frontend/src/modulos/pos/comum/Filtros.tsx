import { Select, type SelectProps } from 'antd';
import { useTerminais, TIPOS_TERMINAL } from './dados';
import type { TipoTerminal } from './tipos';

/** Selector de terminal POS (opcionalmente só de um tipo: lavandaria, hotelaria…). */
export function SeletorTerminal({ tipo, apenasComSessao, ...props }: Omit<SelectProps<number>, 'options' | 'loading'> & { tipo?: TipoTerminal; apenasComSessao?: boolean }) {
  const t = useTerminais();
  const opcoes = (t.data ?? [])
    .filter((x) => (!tipo || x.tipo === tipo) && (!apenasComSessao || !!x.sessao_aberta))
    .map((x) => ({ value: x.id, label: `${x.codigo} — ${x.nome}${tipo ? '' : ` (${TIPOS_TERMINAL[x.tipo] ?? x.tipo})`}${x.ativo ? '' : ' · inactivo'}` }));
  return <Select<number> allowClear showSearch optionFilterProp="label" placeholder="Terminal" style={{ minWidth: 220 }} loading={t.isLoading} options={opcoes} {...props} />;
}
