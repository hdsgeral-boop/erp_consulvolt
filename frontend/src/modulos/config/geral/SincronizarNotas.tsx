import { Alert, Button, Card, DatePicker, Descriptions, Modal, Space, Table, Tag, Typography, message } from 'antd';
import { ExperimentOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import type { Dayjs } from 'dayjs';
import { enviar } from '@/api/cliente';
import { scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { formatarNumero } from '@/utilitarios/formatacao';

/** Resposta de POST /contabilidade/tabelas/notas-demonstracao/sincronizar-por-conta (ServicoNotasPorConta::sincronizar). */
export interface ResultadoSincronizacaoNotas {
  aplicado: boolean;
  linhas_a_atribuir: number;
  por_nota: { nota: string; nota_demonstracao_id: number; descricao: string | null; linhas: number; contas: { codigo_conta: string; linhas: number }[] }[];
  sem_regra: { prefixo: string; linhas: number }[];
  nota_em_falta: { nota: string; linhas: number }[];
  exercicios_encerrados: number[];
  linhas_em_exercicios_encerrados: number;
}

export const URL_SINCRONIZAR_NOTAS = '/contabilidade/tabelas/notas-demonstracao/sincronizar-por-conta';
/** Contas mostradas por nota na amostra (as restantes contam-se em «+N»). */
const AMOSTRA = 5;

/** Texto da amostra de contas de uma nota: «3111 (12), 3112 (3) +4». */
export function amostraContas(contas: { codigo_conta: string; linhas: number }[], n = AMOSTRA): string {
  const vistos = contas.slice(0, n).map((c) => `${c.codigo_conta} (${formatarNumero(c.linhas)})`).join(', ');
  return contas.length > n ? `${vistos} +${contas.length - n}` : vistos;
}

/**
 * «Recuperar Dados Contabilísticos → Sincronizar Notas Automática» (legado: js/app_v2.js:8676-8680, recoverDataMapping em :9785).
 * O servidor exige config_ferramentas; aqui simula-se primeiro (nada é gravado) e só depois se aplica, com confirmação.
 * Só são tocadas linhas SEM nota e fora de exercícios encerrados (regras do servidor).
 */
export function SincronizarNotas() {
  const cliente = useQueryClient();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [simulacao, setSimulacao] = useState<ResultadoSincronizacaoNotas | null>(null);
  const [aplicado, setAplicado] = useState<ResultadoSincronizacaoNotas | null>(null);
  const executar = useMutation({
    mutationFn: (aplicar: boolean) =>
      enviar<ResultadoSincronizacaoNotas>('post', URL_SINCRONIZAR_NOTAS, {
        aplicar,
        ...(periodo?.[0] ? { data_inicio: periodo[0].format('YYYY-MM-DD') } : {}),
        ...(periodo?.[1] ? { data_fim: periodo[1].format('YYYY-MM-DD') } : {}),
      }),
    onSuccess: ({ dados }, aplicar) => {
      if (aplicar) {
        // texto do legado (recoverDataMapping)
        message.success(`Sucesso! ${formatarNumero(dados.linhas_a_atribuir)} linhas de Lançamento foram atualizadas com as notas correspondentes.`);
        setAplicado(dados);
        setSimulacao(null);
        void cliente.invalidateQueries({ queryKey: ['contab'] });
        void cliente.invalidateQueries({ queryKey: ['contabilidade'] });
      } else {
        setSimulacao(dados);
        setAplicado(null);
      }
    },
    onError: (e) => notificarErro(e, 'Não foi possível sincronizar as notas'),
  });
  const r = simulacao ?? aplicado;
  const semNotas = !!r && r.por_nota.length === 0 && r.nota_em_falta.length > 0;
  return (
    <Card title="Recuperar Dados Contabilísticos" style={{ height: '100%' }}>
      <Typography.Paragraph type="secondary">
        Associa automaticamente as «Notas às Demonstrações» aos Lançamentos antigos com base nos prefixos das contas (Regras PGC). Só são preenchidas linhas sem nota e fora
        de exercícios encerrados; simule primeiro (nada é gravado).
      </Typography.Paragraph>
      <Space direction="vertical" style={{ width: '100%' }}>
        <DatePicker.RangePicker
          format="DD/MM/YYYY"
          value={periodo}
          allowEmpty={[true, true]}
          placeholder={['Desde', 'Até']}
          aria-label="Período dos lançamentos (opcional)"
          onChange={(v) => {
            setPeriodo(v);
            setSimulacao(null);
          }}
        />
        <Space wrap>
          <Button icon={<ExperimentOutlined />} loading={executar.isPending && executar.variables === false} disabled={executar.isPending} onClick={() => executar.mutate(false)}>
            Simular
          </Button>
          <Button
            type="primary"
            icon={<ThunderboltOutlined />}
            disabled={!simulacao || simulacao.linhas_a_atribuir === 0 || executar.isPending}
            loading={executar.isPending && executar.variables === true}
            onClick={() =>
              Modal.confirm({
                title: 'Sincronizar Notas Automática?',
                content: `Vão receber nota ${formatarNumero(simulacao?.linhas_a_atribuir)} linha(s) de Lançamento sem nota (${simulacao?.por_nota.length ?? 0} nota(s)). A operação é feita numa transacção e regista-se na auditoria.`,
                okText: 'Aplicar',
                cancelText: 'Cancelar',
                onOk: () => executar.mutateAsync(true),
              })
            }
          >
            Aplicar
          </Button>
        </Space>
        {r && (
          <>
            {r.aplicado ? (
              <Alert type="success" showIcon message={`Sucesso! ${formatarNumero(r.linhas_a_atribuir)} linhas de Lançamento foram atualizadas com as notas correspondentes.`} />
            ) : semNotas ? (
              <Alert type="error" showIcon message="Nenhuma «Nota às Demonstrações» encontrada. Por favor, crie as notas primeiro nas Tabelas Auxiliares." />
            ) : r.linhas_a_atribuir === 0 ? (
              <Alert type="info" showIcon message="Nenhum Lançamento pendente de atualização foi encontrado com os critérios de prefixo." />
            ) : (
              <Alert type="info" showIcon message={`Simulação: ${formatarNumero(r.linhas_a_atribuir)} linha(s) sem nota receberiam nota pela conta. Nada foi gravado.`} />
            )}
            {r.por_nota.length > 0 && (
              <Table
                size="small"
                rowKey="nota"
                scroll={scrollTabela()}
                dataSource={r.por_nota}
                pagination={r.por_nota.length > 8 ? { pageSize: 8, size: 'small' } : false}
                columns={[
                  { title: 'Nota', dataIndex: 'nota', width: 70 },
                  { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '—' },
                  { title: 'Linhas', dataIndex: 'linhas', align: 'right', render: formatarNumero },
                  { title: 'Contas', key: 'n_contas', align: 'right', render: (_: unknown, n) => formatarNumero(n.contas.length) },
                  { title: 'Amostra (conta e linhas)', key: 'amostra', render: (_: unknown, n) => <Typography.Text type="secondary">{amostraContas(n.contas)}</Typography.Text> },
                ]}
              />
            )}
            {(r.sem_regra.length > 0 || r.nota_em_falta.length > 0 || r.linhas_em_exercicios_encerrados > 0) && (
              <Descriptions size="small" column={1} bordered title="Ficam por tratar">
                {r.sem_regra.length > 0 && (
                  <Descriptions.Item label="Contas sem regra (prefixo)">
                    <Space size={[4, 4]} wrap>
                      {r.sem_regra.map((s) => (
                        <Tag key={s.prefixo}>{`${s.prefixo}: ${formatarNumero(s.linhas)}`}</Tag>
                      ))}
                    </Space>
                  </Descriptions.Item>
                )}
                {r.nota_em_falta.length > 0 && (
                  <Descriptions.Item label="Nota inexistente na empresa">
                    <Space size={[4, 4]} wrap>
                      {r.nota_em_falta.map((s) => (
                        <Tag key={s.nota} color="warning">{`Nota ${s.nota}: ${formatarNumero(s.linhas)}`}</Tag>
                      ))}
                    </Space>
                  </Descriptions.Item>
                )}
                {r.linhas_em_exercicios_encerrados > 0 && (
                  <Descriptions.Item label="Em exercícios encerrados">
                    {`${formatarNumero(r.linhas_em_exercicios_encerrados)} linha(s) (${r.exercicios_encerrados.join(', ')}) — não são alteradas`}
                  </Descriptions.Item>
                )}
              </Descriptions>
            )}
          </>
        )}
      </Space>
    </Card>
  );
}
