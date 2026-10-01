import { Input, Select, type SelectProps } from 'antd';
import { useMemo, useState } from 'react';
import { useDiarios, usePlanoContas, useTabelaAux, useTerceiros, useUnidadesNegocio, type TabelaAux } from './dados';

interface PropsBase<V> {
  /** Injectado pelo Form.Item: liga o rótulo ao campo (clique no rótulo, leitores de ecrã). */
  id?: string;
  value?: V;
  onChange?: (v: V) => void;
  placeholder?: string;
  allowClear?: boolean;
  disabled?: boolean;
  style?: React.CSSProperties;
}

/**
 * Selector de conta do plano (pesquisa por código ou descrição). Por omissão só contas de movimento (tipo M),
 * que são as únicas onde o servidor aceita lançamentos. Sem acesso ao plano, cai num campo de texto livre.
 */
export function SeletorConta({ id, value, onChange, placeholder = 'Conta', allowClear, disabled, style, prefixos, incluirTotalizadoras = false }: PropsBase<string | undefined> & { prefixos?: string[]; incluirTotalizadoras?: boolean }) {
  const plano = usePlanoContas();
  const opcoes = useMemo(
    () =>
      (plano.data ?? [])
        .filter((c) => (incluirTotalizadoras || c.tipo === 'M') && (!prefixos?.length || prefixos.some((p) => c.codigo.startsWith(p))))
        .map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` })),
    [plano.data, prefixos, incluirTotalizadoras],
  );
  if (plano.isError) {
    return <Input id={id} value={value} onChange={(e) => onChange?.(e.target.value || undefined)} placeholder={placeholder} disabled={disabled} style={style} maxLength={20} />;
  }
  return (
    <Select
      id={id}
      showSearch
      value={value}
      onChange={onChange}
      allowClear={allowClear}
      disabled={disabled}
      placeholder={placeholder}
      loading={plano.isLoading}
      options={opcoes}
      optionFilterProp="label"
      style={{ minWidth: 200, ...style }}
      popupMatchSelectWidth={false}
      filterSort={(a, b) => String(a.value).localeCompare(String(b.value))}
    />
  );
}

export function SeletorDiario({ id, value, onChange, placeholder = 'Diário', allowClear, disabled, style }: PropsBase<number | undefined>) {
  const diarios = useDiarios();
  return (
    <Select
      id={id}
      showSearch
      value={value}
      onChange={onChange}
      allowClear={allowClear}
      disabled={disabled}
      placeholder={placeholder}
      loading={diarios.isLoading}
      optionFilterProp="label"
      style={{ minWidth: 180, ...style }}
      popupMatchSelectWidth={false}
      options={(diarios.data ?? []).map((d) => ({ value: d.id, label: `${d.codigo} — ${d.descricao ?? d.nome ?? ''}` }))}
    />
  );
}

/** Notas DEMO / fluxo de caixa / centros de custo (tabelas auxiliares). */
export function SeletorAux({ id, tabela, value, onChange, placeholder, allowClear = true, disabled, style }: PropsBase<number | undefined> & { tabela: TabelaAux }) {
  const t = useTabelaAux(tabela);
  return (
    <Select
      id={id}
      showSearch
      value={value}
      onChange={onChange}
      allowClear={allowClear}
      disabled={disabled}
      placeholder={placeholder}
      loading={t.isLoading}
      optionFilterProp="label"
      style={{ minWidth: 160, ...style }}
      popupMatchSelectWidth={false}
      options={(t.data ?? []).map((r) => ({ value: r.id, label: `${r.codigo} — ${r.descricao ?? ''}` }))}
    />
  );
}

export function SeletorUnidade({ id, value, onChange, placeholder = 'Unidade de negócio', allowClear = true, disabled, style }: PropsBase<number | undefined>) {
  const u = useUnidadesNegocio();
  return (
    <Select
      id={id}
      showSearch
      value={value}
      onChange={onChange}
      allowClear={allowClear}
      disabled={disabled}
      placeholder={placeholder}
      loading={u.isLoading}
      optionFilterProp="label"
      style={{ minWidth: 160, ...style }}
      popupMatchSelectWidth={false}
      options={(u.data ?? []).map((x) => ({ value: x.id, label: `${x.codigo ? `${x.codigo} — ` : ''}${x.nome}` }))}
    />
  );
}

/** Terceiro por pesquisa remota (nome ou NIF). `rotuloInicial` mostra o terceiro já escolhido antes da 1.ª pesquisa. */
export function SeletorTerceiro({ id, value, onChange, placeholder = 'Terceiro (nome ou NIF)', allowClear = true, disabled, style, rotuloInicial }: PropsBase<number | undefined> & { rotuloInicial?: string }) {
  const [pesquisa, setPesquisa] = useState('');
  const t = useTerceiros(pesquisa);
  const opcoes: SelectProps['options'] = (t.data?.itens ?? []).map((x) => ({ value: x.id, label: `${x.nome.trim()}${x.nif ? ` (NIF ${x.nif})` : ''}` }));
  if (value && rotuloInicial && !opcoes.some((o) => o.value === value)) opcoes.unshift({ value, label: rotuloInicial });
  return (
    <Select
      id={id}
      showSearch
      value={value}
      onChange={onChange}
      allowClear={allowClear}
      disabled={disabled}
      placeholder={placeholder}
      filterOption={false}
      onSearch={setPesquisa}
      loading={t.isFetching}
      style={{ minWidth: 220, ...style }}
      popupMatchSelectWidth={false}
      options={opcoes}
    />
  );
}
