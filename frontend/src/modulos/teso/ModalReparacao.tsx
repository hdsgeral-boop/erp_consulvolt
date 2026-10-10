import { Alert, Button, Checkbox, Input, Modal, Space, Table, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { IntegracaoOrfa } from './api';
import { MINIMO_MOTIVO, motivoValido } from './motivo';

interface Previa {
  documentos: IntegracaoOrfa[];
  total: number;
  truncado: boolean;
}

interface ResultadoReparacao {
  repostos: { id: number; numero_documento: string }[];
  erros: { id: number; codigo: string; mensagem: string }[];
}

export const ROTULO_MOTIVO_ORFA: Record<IntegracaoOrfa['motivo'], string> = {
  LANCAMENTO_ESTORNADO: 'Lançamento estornado na Contabilidade',
  LANCAMENTO_INEXISTENTE: 'Lançamento inexistente',
  LEGADO_SEM_LIGACAO: 'Sistema anterior — sem ligação ao Diário',
};

/** Pré-selecção: os documentos com n.º de lançamento (estornado/inexistente); os do legado só por escolha explícita. */
export function preSeleccao(docs: IntegracaoOrfa[]): number[] {
  return docs.filter((d) => d.motivo !== 'LEGADO_SEM_LIGACAO').map((d) => d.id);
}

/** O pedido de reposição é válido? (algum escolhido, motivo, e confirmação se houver documentos do legado). */
export function pedidoValido(escolhidos: IntegracaoOrfa[], motivo: string, confirmouLegado: boolean): boolean {
  if (!escolhidos.length || !motivoValido(motivo)) return false;
  return !escolhidos.some((d) => d.motivo === 'LEGADO_SEM_LIGACAO') || confirmouLegado;
}

/**
 * «Reparar Inconsistências» / «Resgatar Documento» do legado como operação SEGURA: GET /tesouraria/reparacao/integracoes
 * (pré-visualização — nada é gravado) e POST com os escolhidos, motivo e, para documentos do sistema anterior sem ligação
 * ao Diário, a confirmação de que foram verificados no Razão. Repor = voltar a «por integrar», sem tocar no Diário.
 */
export function ModalReparacao({ modo, aberto, aoFechar }: { modo: 'inconsistencias' | 'resgatar'; aberto: boolean; aoFechar: () => void }) {
  const cliente = useQueryClient();
  const [referencia, setReferencia] = useState('');
  const [procura, setProcura] = useState<string | null>(modo === 'inconsistencias' ? '' : null);
  const [escolhidos, setEscolhidos] = useState<number[]>([]);
  const [motivo, setMotivo] = useState('');
  const [confirmouLegado, setConfirmouLegado] = useState(false);
  const [resultado, setResultado] = useState<ResultadoReparacao | null>(null);

  useEffect(() => {
    if (!aberto) return;
    setReferencia('');
    setProcura(modo === 'inconsistencias' ? '' : null);
    setEscolhidos([]);
    setMotivo('');
    setConfirmouLegado(false);
    setResultado(null);
  }, [aberto, modo]);

  const previa = useQuery({
    queryKey: ['teso', 'reparacao', procura],
    queryFn: () => obter<Previa>('/tesouraria/reparacao/integracoes', procura ? { pesquisa: procura } : {}),
    enabled: aberto && procura !== null,
    retry: false,
  });
  useEffect(() => {
    if (previa.data) setEscolhidos(preSeleccao(previa.data.documentos));
  }, [previa.data]);
  const docs = useMemo(() => previa.data?.documentos ?? [], [previa.data]);
  const seleccionados = docs.filter((d) => escolhidos.includes(d.id));
  const temLegado = seleccionados.some((d) => d.motivo === 'LEGADO_SEM_LIGACAO');

  const repor = useMutation({
    mutationFn: () => enviar<ResultadoReparacao>('post', '/tesouraria/reparacao/integracoes', { ids: escolhidos, motivo: motivo.trim(), confirmar_legado: temLegado && confirmouLegado }),
    onSuccess: ({ dados, mensagem }) => {
      message.success(mensagem);
      setResultado(dados);
      void cliente.invalidateQueries({ queryKey: ['teso'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível repor os documentos'),
  });

  const resgatar = modo === 'resgatar';
  return (
    <Modal
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(900)}
      title={<span><IconeFa nome={resgatar ? 'life-ring' : 'tools'} style={{ color: '#f59e0b' }} /> {resgatar ? 'Resgatar Documento Bloqueado' : 'Reparar Inconsistências'}</span>}
      footer={
        resultado ? <Button type="primary" onClick={aoFechar}>Fechar</Button> : (
          <Space wrap>
            <Button onClick={aoFechar}>Cancelar</Button>
            <Button type="primary" style={{ background: '#f59e0b', borderColor: '#f59e0b' }} icon={<IconeFa nome={resgatar ? 'life-ring' : 'tools'} />}
              disabled={!pedidoValido(seleccionados, motivo, confirmouLegado)} loading={repor.isPending}
              onClick={() => Modal.confirm({ title: `Repor ${seleccionados.length} documento(s) por integrar?`, content: 'Os documentos voltam ao estado «por integrar». O Diário não é alterado.', okText: 'Repor', cancelText: 'Cancelar', onOk: () => repor.mutateAsync() })}>
              {resgatar ? 'Resgatar' : 'Repor por integrar'} ({seleccionados.length})
            </Button>
          </Space>
        )
      }
    >
      <Typography.Paragraph type="secondary">
        {resgatar
          ? 'Liberta um documento preso em estado INTEGRADO sem lançamentos no Diário (ex.: lançamento estornado directamente na Contabilidade).'
          : 'Detecta documentos INTEGRADOS que perderam os lançamentos no Diário e repõe-nos por integrar, para voltarem a ser integrados.'}{' '}
        Só a Tesouraria é tratada: os outros módulos descontabilizam-se nos próprios ecrãs (por estorno).
      </Typography.Paragraph>
      {resgatar && (
        <Space.Compact style={{ width: '100%', marginBottom: 12 }}>
          <Input placeholder="Referência / Nº do Documento (ex.: REC A2026/12)" value={referencia} maxLength={100} aria-label="Referência / Nº do Documento"
            onChange={(e) => setReferencia(e.target.value)} onPressEnter={() => referencia.trim() && setProcura(referencia.trim())} />
          <Button type="primary" icon={<IconeFa nome="search" />} disabled={!referencia.trim()} onClick={() => setProcura(referencia.trim())}>Procurar</Button>
        </Space.Compact>
      )}
      {resultado ? (
        <Space direction="vertical" style={{ width: '100%' }}>
          {resultado.repostos.length > 0 && <Alert type="success" showIcon message={`Repostos por integrar: ${resultado.repostos.map((r) => r.numero_documento).join(', ')}`} />}
          {resultado.erros.length > 0 && <Alert type="warning" showIcon message="Recusados" description={<ul style={{ margin: 0, paddingLeft: 18 }}>{resultado.erros.map((e) => <li key={e.id}>#{e.id}: {e.mensagem}</li>)}</ul>} />}
        </Space>
      ) : procura !== null && (
        <>
          {previa.isError && <Alert type="error" showIcon style={{ marginBottom: 12 }} message="Não foi possível obter a pré-visualização." />}
          {previa.data?.truncado && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Mostram-se os primeiros ${docs.length} de ${previa.data.total}.`} />}
          <Table<IntegracaoOrfa>
            rowKey="id"
            size="small"
            loading={previa.isFetching}
            dataSource={docs}
            pagination={false}
            scroll={scrollTabela(320)}
            locale={{ emptyText: resgatar ? 'Nenhum documento preso com esta referência.' : 'Nenhuma inconsistência encontrada: todos os integrados têm lançamento no Diário.' }}
            rowSelection={{ selectedRowKeys: escolhidos, onChange: (k) => setEscolhidos(k as number[]) }}
            columns={[
              { title: 'Data', dataIndex: 'data_documento', render: formatarData },
              { title: 'Documento', key: 'doc', render: (_, d) => <strong>{d.numero_documento || d.referencia || `TES-${d.id}`}</strong> },
              { title: 'Conta', dataIndex: 'conta_financeira' },
              { title: 'Valor', dataIndex: 'valor_total', align: 'right', render: (v: string) => formatarKz(v) },
              { title: 'Lançamento', dataIndex: 'numero_lan_contabilizacao', render: (v: string | null) => v ?? '—' },
              { title: 'Situação', dataIndex: 'motivo', render: (m: IntegracaoOrfa['motivo']) => <Tag color={m === 'LEGADO_SEM_LIGACAO' ? 'gold' : 'orange'}>{ROTULO_MOTIVO_ORFA[m]}</Tag> },
            ]}
          />
          <Input.TextArea style={{ marginTop: 12 }} rows={2} maxLength={500} value={motivo} onChange={(e) => setMotivo(e.target.value)} placeholder={`Motivo (obrigatório, mínimo ${MINIMO_MOTIVO} caracteres)`} aria-label="Motivo" />
          {temLegado && (
            <Alert type="warning" showIcon style={{ marginTop: 12 }}
              message="Documentos do sistema anterior sem ligação ao Diário"
              description={<Checkbox checked={confirmouLegado} onChange={(e) => setConfirmouLegado(e.target.checked)}>Confirmo que verifiquei no Razão da conta que estes documentos não estão lançados (ex.: numa folha de caixa agregada).</Checkbox>} />
          )}
        </>
      )}
    </Modal>
  );
}
