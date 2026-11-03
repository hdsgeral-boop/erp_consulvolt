import { Form, Input, Select, message, type SelectProps } from 'antd';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { enviar } from '@/api/cliente';
import { dropdownComCriar, ModalCriacaoRapida } from '@/componentes/formularios';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import type { ContaPlano, RegistoAux } from '../api';
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

/** Sufixo das contas totalizadoras mostradas mas bloqueadas nos selectores (Versão Actual: visíveis, não escolhíveis). */
export const SUFIXO_TOTALIZADORA = ' (totalizadora — não pode ser mapeada)';

/** Opções do selector: as contas de movimento escolhíveis; as totalizadoras visíveis mas bloqueadas (salvo `incluirTotalizadoras`). */
export function opcoesConta(contas: { codigo: string; descricao?: string | null; tipo?: string | null }[], prefixos?: string[], incluirTotalizadoras = false) {
  return contas
    .filter((c) => !prefixos?.length || prefixos.some((p) => c.codigo.startsWith(p)))
    .map((c) => {
      const bloqueada = !incluirTotalizadoras && c.tipo !== 'M';
      return { value: c.codigo, label: `${c.codigo} - ${c.descricao ?? ''}${bloqueada ? SUFIXO_TOTALIZADORA : ''}`, disabled: bloqueada };
    });
}

interface ValoresContaRapida { codigo: string; descricao: string; tipo: 'M' | 'T' }

/** Criação mínima de uma conta do plano (código, descrição e tipo — os mesmos campos obrigatórios do servidor, `GuardarContaRequest`). */
function ModalCriarContaRapida({ aberto, podeCriar, prefixoSugerido, aoFechar, aoCriar }: { aberto: boolean; podeCriar: boolean; prefixoSugerido?: string; aoFechar: () => void; aoCriar: (c: ContaPlano) => void }) {
  const [form] = Form.useForm<ValoresContaRapida>();
  const criar = useMutation({
    mutationFn: (v: ValoresContaRapida) => enviar<ContaPlano>('post', '/contabilidade/plano-contas', { codigo: v.codigo.trim(), descricao: v.descricao.trim(), tipo: v.tipo }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      form.resetFields();
      aoCriar(dados);
    },
    onError: (e) => notificarErro(e, 'Não foi possível criar a conta'),
  });
  return (
    <ModalCriacaoRapida<ValoresContaRapida> aberto={aberto} titulo="Criar nova conta" podeCriar={podeCriar} aGravar={criar.isPending} aoFechar={aoFechar} form={form} onFinish={(v) => criar.mutate(v)}>
      <Form.Item name="codigo" label="Código" initialValue={prefixoSugerido} rules={[{ required: true, message: 'Indique o código.' }, { max: 20 }, { pattern: /^[0-9A-Za-z.]+$/, message: 'Só algarismos, letras e pontos.' }]}>
        <Input autoFocus />
      </Form.Item>
      <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }, { max: 255 }]}>
        <Input />
      </Form.Item>
      <Form.Item name="tipo" label="Tipo" initialValue="M" rules={[{ required: true }]}>
        <Select options={[{ value: 'M', label: 'Movimento (aceita lançamentos)' }, { value: 'T', label: 'Totalizadora (só agrupa)' }]} />
      </Form.Item>
    </ModalCriacaoRapida>
  );
}

/**
 * Selector de conta do plano (pesquisa por código ou descrição). Por omissão só se escolhem contas de movimento
 * (tipo M), as únicas onde o servidor aceita lançamentos; as totalizadoras aparecem bloqueadas, com o sufixo
 * «(totalizadora — não pode ser mapeada)», para se ver a árvore. Sem acesso ao plano, cai num campo de texto livre.
 * permissaoCriar: mostra «+ Criar nova conta» no fundo da lista (modal mínimo, código pré-preenchido com o 1.º prefixo).
 */
export function SeletorConta({ id, value, onChange, placeholder = 'Conta', allowClear, disabled, style, prefixos, incluirTotalizadoras = false, permissaoCriar }: PropsBase<string | undefined> & { prefixos?: string[]; incluirTotalizadoras?: boolean; permissaoCriar?: string }) {
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const plano = usePlanoContas();
  const [aCriar, setACriar] = useState(false);
  const podeCriar = !!permissaoCriar && pode(permissaoCriar);
  const opcoes = useMemo(
    () =>
      opcoesConta(plano.data ?? [], prefixos, incluirTotalizadoras),
    [plano.data, prefixos, incluirTotalizadoras],
  );
  if (plano.isError) {
    return <Input id={id} value={value} onChange={(e) => onChange?.(e.target.value || undefined)} placeholder={placeholder} disabled={disabled} style={style} maxLength={20} />;
  }
  return (
    <>
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
        popupRender={(menu) => dropdownComCriar(menu, { mostrar: !!permissaoCriar, podeCriar, rotulo: 'conta', aoClicar: () => setACriar(true) })}
      />
      {permissaoCriar && (
        <ModalCriarContaRapida
          aberto={aCriar}
          podeCriar={podeCriar}
          prefixoSugerido={prefixos?.[0]}
          aoFechar={() => setACriar(false)}
          aoCriar={(c) => {
            void consultas.invalidateQueries({ queryKey: ['contab', 'plano-contas'] });
            setACriar(false);
            onChange?.(c.codigo);
          }}
        />
      )}
    </>
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

const ROTULO_TABELA_AUX: Record<TabelaAux, string> = {
  'centros-custo': 'centro de custo',
  diarios: 'diário',
  'notas-demonstracao': 'nota de demonstração',
  'notas-fluxo-caixa': 'nota de fluxo de caixa',
};

interface ValoresAuxRapido { codigo: string; descricao: string }

/** Criação mínima de uma linha de tabela auxiliar (código + descrição — os mesmos campos do servidor para qualquer tabela deste endpoint genérico). */
function ModalCriarAuxRapido({ tabela, aberto, podeCriar, aoFechar, aoCriar }: { tabela: TabelaAux; aberto: boolean; podeCriar: boolean; aoFechar: () => void; aoCriar: (r: RegistoAux) => void }) {
  const [form] = Form.useForm<ValoresAuxRapido>();
  const criar = useMutation({
    mutationFn: (v: ValoresAuxRapido) => enviar<RegistoAux>('post', `/contabilidade/tabelas/${tabela}`, { codigo: v.codigo.trim(), descricao: v.descricao.trim() }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      form.resetFields();
      aoCriar(dados);
    },
    onError: (e) => notificarErro(e, `Não foi possível criar ${tabela === 'diarios' ? 'o diário' : 'o registo'}`),
  });
  return (
    <ModalCriacaoRapida<ValoresAuxRapido> aberto={aberto} titulo={`Criar novo ${ROTULO_TABELA_AUX[tabela]}`} podeCriar={podeCriar} aGravar={criar.isPending} aoFechar={aoFechar} form={form} onFinish={(v) => criar.mutate(v)}>
      <Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }, { max: 20 }]}>
        <Input autoFocus />
      </Form.Item>
      <Form.Item name="descricao" label="Descrição" rules={[{ required: true, message: 'Indique a descrição.' }, { max: 255 }]}>
        <Input />
      </Form.Item>
    </ModalCriacaoRapida>
  );
}

/** Notas DEMO / fluxo de caixa / centros de custo / diários (tabelas auxiliares). permissaoCriar: mostra «+ Criar novo X» no fundo da lista. */
export function SeletorAux({ id, tabela, value, onChange, placeholder, allowClear = true, disabled, style, permissaoCriar }: PropsBase<number | undefined> & { tabela: TabelaAux; permissaoCriar?: string }) {
  const { pode } = useSessao();
  const consultas = useQueryClient();
  const t = useTabelaAux(tabela);
  const [aCriar, setACriar] = useState(false);
  const podeCriar = !!permissaoCriar && pode(permissaoCriar);
  return (
    <>
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
        popupRender={(menu) => dropdownComCriar(menu, { mostrar: !!permissaoCriar, podeCriar, rotulo: ROTULO_TABELA_AUX[tabela], aoClicar: () => setACriar(true) })}
      />
      {permissaoCriar && (
        <ModalCriarAuxRapido
          tabela={tabela}
          aberto={aCriar}
          podeCriar={podeCriar}
          aoFechar={() => setACriar(false)}
          aoCriar={(r) => {
            void consultas.invalidateQueries({ queryKey: ['contab', 'tabelas', tabela] });
            setACriar(false);
            onChange?.(r.id);
          }}
        />
      )}
    </>
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
