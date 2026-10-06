import { DeleteOutlined, SaveOutlined, StarFilled, StarOutlined } from '@ant-design/icons';
import { Button, Input, Modal, Select, Space, Tooltip } from 'antd';
import { useEffect, useMemo, useRef, useState } from 'react';
import { usePreferencias } from './usePreferencias';

/**
 * Visões guardadas da Análise Dinâmica (M-19; legado cuboGuardarVisao / cuboAplicarVisao / cuboApagarVisao, que ficavam
 * no localStorage): guardar a configuração actual com um nome, aplicar, apagar e marcar uma como padrão do conjunto
 * (aplicada ao abrir). Guardadas no servidor por utilizador (preferências tipo `visoes_cubo`, nome «conjunto:nome»).
 *
 * Uso (AnaliseDinamica.tsx, módulo Geral):
 *   <VisoesGuardadas conjunto={conjunto.id} visao={{ linhas, colunas, medidas, filtros, apuramento }}
 *     aoAplicar={(v) => { setLinhas(v.linhas); setColunas(v.colunas); … }} />
 */
export interface ValorVisao<T> {
  visao: T;
  padrao?: boolean;
}

export function VisoesGuardadas<T>({ conjunto, visao, aoAplicar, tamanho = 'middle' }: { conjunto: string; visao: T; aoAplicar: (v: T) => void; tamanho?: 'small' | 'middle' }) {
  const { lista, gravar, eliminar } = usePreferencias<ValorVisao<T>>('visoes_cubo');
  const prefixo = `${conjunto}:`;
  const visoes = useMemo(() => lista.filter((p) => p.nome.startsWith(prefixo)).map((p) => ({ ...p, rotulo: p.nome.slice(prefixo.length) })), [lista, prefixo]);
  const [seleccionada, setSeleccionada] = useState<string | undefined>();
  const [aGuardar, setAGuardar] = useState(false);
  const [nome, setNome] = useState('');
  const aplicouPadrao = useRef<string | null>(null);

  // a visão padrão do conjunto é aplicada uma vez ao abrir
  useEffect(() => {
    if (aplicouPadrao.current === conjunto) return;
    const padrao = visoes.find((v) => v.valor.padrao);
    if (padrao) {
      aplicouPadrao.current = conjunto;
      setSeleccionada(padrao.nome);
      aoAplicar(padrao.valor.visao);
    }
  }, [visoes, conjunto, aoAplicar]);

  const actual = visoes.find((v) => v.nome === seleccionada);
  const nomeValido = /^[A-Za-z0-9 _.\-À-ÿ]{1,80}$/.test(nome.trim());
  const chave = (rotulo: string) => `${prefixo}${rotulo.trim().replace(/[^A-Za-z0-9_.\-]+/g, '_')}`.slice(0, 150);

  return (
    <Space.Compact size={tamanho}>
      <Select
        style={{ minWidth: 180, maxWidth: '100%' }}
        placeholder="Visões guardadas"
        aria-label="Visões guardadas"
        value={seleccionada}
        allowClear
        onChange={(v?: string) => {
          setSeleccionada(v);
          const escolhida = visoes.find((x) => x.nome === v);
          if (escolhida) aoAplicar(escolhida.valor.visao);
        }}
        options={visoes.map((v) => ({ value: v.nome, label: `${v.valor.padrao ? '★ ' : ''}${v.rotulo.replace(/_/g, ' ')}` }))}
        notFoundContent="Sem visões guardadas"
      />
      <Tooltip title="Guardar a configuração actual como visão">
        <Button icon={<SaveOutlined />} aria-label="Guardar visão" onClick={() => { setNome(actual?.rotulo.replace(/_/g, ' ') ?? ''); setAGuardar(true); }} />
      </Tooltip>
      <Tooltip title={actual?.valor.padrao ? 'Deixar de ser a visão padrão' : 'Definir como visão padrão (aplicada ao abrir)'}>
        <Button
          icon={actual?.valor.padrao ? <StarFilled /> : <StarOutlined />}
          aria-label="Visão padrão"
          disabled={!actual}
          onClick={() => {
            if (!actual) return;
            const marcar = !actual.valor.padrao;
            visoes.filter((v) => v.valor.padrao && v.nome !== actual.nome).forEach((v) => gravar({ nome: v.nome, valor: { ...v.valor, padrao: false } }));
            gravar({ nome: actual.nome, valor: { ...actual.valor, padrao: marcar } });
          }}
        />
      </Tooltip>
      <Tooltip title="Apagar a visão seleccionada">
        <Button icon={<DeleteOutlined />} aria-label="Apagar visão" disabled={!actual} onClick={() => { if (actual) { eliminar(actual.nome); setSeleccionada(undefined); } }} />
      </Tooltip>
      <Modal
        title="Guardar visão"
        open={aGuardar}
        okText="Guardar"
        cancelText="Cancelar"
        okButtonProps={{ disabled: !nomeValido }}
        onCancel={() => setAGuardar(false)}
        onOk={() => {
          const n = chave(nome);
          gravar({ nome: n, valor: { visao, padrao: visoes.find((v) => v.nome === n)?.valor.padrao ?? false } });
          setSeleccionada(n);
          setAGuardar(false);
        }}
        destroyOnHidden
      >
        <Input autoFocus maxLength={80} value={nome} onChange={(e) => setNome(e.target.value)} placeholder="Ex.: Vendas por cliente e mês" aria-label="Nome da visão" />
      </Modal>
    </Space.Compact>
  );
}
