import { Alert, Button, Card, Space, Table, Tag, Typography, message } from 'antd';
import { CheckCircleTwoTone, PlusOutlined, SwapOutlined, ToolOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';

export interface ContaDiagnostico {
  codigo: string;
  descricao: string | null;
  problema: 'EM_FALTA' | 'TOTALIZADORA';
  passos: number[];
  movimentos_ano: number;
  subcontas: number;
  accao: 'CRIAR' | 'CONVERTER' | 'MANUAL';
  orientacao: string;
}

interface Diagnostico {
  ano: number;
  pronto: boolean;
  contas: ContaDiagnostico[];
}

/**
 * Decisão 20 (ronda 2): «Plano de contas para o encerramento» — contas do apuramento em falta ou totalizadoras (ex.: 769
 * totalizadora, 8xx em falta), com correcção assistida: criar as em falta e converter em movimento as totalizadoras sem
 * subcontas. As totalizadoras com subcontas ficam com orientação (reclassificar os movimentos).
 */
export function PainelPlano({ ano, encerrado }: { ano: number; encerrado: boolean }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [abrir, setAbrir] = useState(false);
  const diag = useQuery({ queryKey: ['contab', 'encerramento', ano, 'plano'], queryFn: () => obter<Diagnostico>(`/contabilidade/encerramento/${ano}/plano`), enabled: !encerrado });
  const corrigir = useMutation({
    mutationFn: (corpo: { criar?: string[]; converter?: string[] }) => enviar<{ diagnostico: Diagnostico }>('post', `/contabilidade/encerramento/${ano}/plano/corrigir`, corpo),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível corrigir o plano de contas'),
  });
  if (encerrado || !diag.data) return null;
  const d = diag.data;
  if (d.pronto)
    return (
      <Alert type="success" showIcon icon={<CheckCircleTwoTone twoToneColor="#52c41a" />} style={{ marginBottom: 16 }} message={`Plano de contas pronto para o apuramento de ${ano}.`} />
    );
  const criar = d.contas.filter((c) => c.accao === 'CRIAR').map((c) => c.codigo);
  const converter = d.contas.filter((c) => c.accao === 'CONVERTER').map((c) => c.codigo);
  return (
    <>
      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: abrir ? 8 : 16 }}
        message={`Plano de contas: ${d.contas.length} conta(s) do apuramento a corrigir antes de encerrar ${ano}.`}
        action={
          <Button size="small" icon={<ToolOutlined />} onClick={() => setAbrir((a) => !a)}>
            {abrir ? 'Ocultar' : 'Corrigir'}
          </Button>
        }
      />
      {abrir && (
        <Card
          size="small"
          title="Plano de contas para o encerramento"
          style={{ marginBottom: 16 }}
          extra={
            <Space wrap>
              {criar.length > 0 && pode('contab_apurar') && (
                <Button size="small" icon={<PlusOutlined />} loading={corrigir.isPending} onClick={() => corrigir.mutate({ criar })}>
                  Criar contas em falta ({criar.length})
                </Button>
              )}
              {converter.length > 0 && pode('contab_plano_gerir') && (
                <Button size="small" icon={<SwapOutlined />} loading={corrigir.isPending} onClick={() => corrigir.mutate({ converter })}>
                  Converter em movimento ({converter.length})
                </Button>
              )}
            </Space>
          }
        >
          <Table<ContaDiagnostico>
            rowKey="codigo"
            size="small"
            pagination={false}
            scroll={scrollTabela()}
            dataSource={d.contas}
            columns={[
              { title: 'Conta', dataIndex: 'codigo', render: (v: string) => <strong>{v}</strong> },
              { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '—', responsive: ['md'] },
              { title: 'Problema', dataIndex: 'problema', render: (v: string) => (v === 'EM_FALTA' ? <Tag color="orange">Em falta</Tag> : <Tag color="red">Totalizadora</Tag>) },
              { title: 'Passos', dataIndex: 'passos', render: (v: number[]) => v.join(', ') },
              { title: 'Mov. no ano', dataIndex: 'movimentos_ano', align: 'right', responsive: ['md'] },
              {
                title: 'Correcção',
                key: 'orientacao',
                render: (_, c) => (
                  <Typography.Text type={c.accao === 'MANUAL' ? 'danger' : undefined} style={{ fontSize: 12 }}>
                    {c.orientacao}
                  </Typography.Text>
                ),
              },
            ]}
          />
        </Card>
      )}
    </>
  );
}
