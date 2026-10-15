import { Alert, Button, Col, InputNumber, Row, Select, Space, Table, Typography } from 'antd';
import { CloseOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { formatarKz } from '@/utilitarios/formatacao';
import { simularReparticao, useCriterios } from './api';
import { alocacaoParaApi, FORMAS, validarAlocacao, type Alocacao, type LinhaReparticao } from './regras';

export interface PropsAlocacaoUN {
  /** Injectado pelo Form.Item (campo `unidades_alocadas`). */
  value?: Alocacao | null;
  onChange?: (v: Alocacao | null) => void;
  /** Valor de referência para a pré-visualização das parcelas (opcional). */
  valor?: number | null;
  titulo?: string;
  disabled?: boolean;
}

/**
 * Componente de alocação de unidades de negócio num objecto (AlocacaoUN.montar do legado novo, alocacao_un.js): critério
 * de repartição (vazio = uma só unidade, a do campo normal), linhas «unidade + peso» (ou «parte igual»/«automático»,
 * conforme a forma) e pré-visualização das percentagens e valores calculados no servidor.
 */
export function AlocacaoUN({ value, onChange, valor, titulo = 'Unidades de negócio', disabled }: PropsAlocacaoUN) {
  const criterios = useCriterios(true);
  const unidades = useUnidadesNegocio();
  const estado: Alocacao = value ?? { criterio_id: null, unidades: [] };
  const criterio = (criterios.data ?? []).find((c) => c.id === estado.criterio_id) ?? null;
  const forma = criterio ? FORMAS[criterio.criterio] ?? FORMAS.PERCENTAGEM : null;
  const erro = criterio ? validarAlocacao(estado, criterio.criterio) : null;
  const corpo = criterio && !erro ? alocacaoParaApi(estado, criterio.criterio) : null;

  const previa = useQuery({
    queryKey: ['reparticao', 'simular', JSON.stringify(corpo), valor ?? 0],
    queryFn: () => simularReparticao(corpo!, valor),
    enabled: !!corpo,
    retry: false,
    staleTime: 30_000,
  });

  const mudar = (v: Alocacao) => onChange?.(v.criterio_id ? v : null);
  const linha = (i: number, campos: Partial<Alocacao['unidades'][number]>) =>
    mudar({ ...estado, unidades: estado.unidades.map((u, n) => (n === i ? { ...u, ...campos } : u)) });

  const opcoesUnidades = useMemo(
    () => (unidades.data ?? []).filter((u) => u.estado !== 'INATIVO').map((u) => ({ value: u.id, label: `${u.codigo ? `${u.codigo} — ` : ''}${u.nome}` })),
    [unidades.data],
  );

  return (
    <div style={{ border: '1px solid var(--ant-color-border, #e2e8f0)', borderRadius: 6, padding: '10px 12px' }} aria-label={titulo} role="group">
      <Typography.Text strong>{titulo}</Typography.Text>
      <div>
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          Alocar a mais do que uma unidade. O critério diz só a forma de repartir; os valores de cada unidade são deste registo.
        </Typography.Text>
      </div>
      <div style={{ margin: '8px 0' }}>
        <Select
          aria-label="Critério de repartição"
          style={{ width: '100%' }}
          disabled={disabled}
          loading={criterios.isLoading}
          value={estado.criterio_id ?? ''}
          onChange={(id: number | '') => {
            const us = [...estado.unidades];
            while (id && us.length < 2) us.push({});   // ao escolher o critério abrem-se logo duas linhas (alocacao_un.js:79)
            mudar({ criterio_id: id || null, unidades: us });
          }}
          options={[{ value: '', label: '— uma só unidade (sem repartir) —' }, ...(criterios.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} — ${c.nome}` }))]}
        />
        {forma && <Typography.Text type="secondary" style={{ fontSize: 12 }}>{forma.ajuda}</Typography.Text>}
      </div>
      {!criterio ? (
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>Sem repartição: o registo usa a unidade de negócio indicada no campo normal.</Typography.Text>
      ) : (
        <>
          {estado.unidades.map((u, i) => (
            <Row key={i} gutter={6} style={{ marginBottom: 4 }} align="middle">
              <Col flex="auto">
                <Select
                  aria-label={`Unidade ${i + 1}`}
                  placeholder="— unidade —"
                  style={{ width: '100%' }}
                  disabled={disabled}
                  showSearch
                  optionFilterProp="label"
                  value={u.unidade_negocio_id ?? undefined}
                  onChange={(id: number) => linha(i, { unidade_negocio_id: id })}
                  options={opcoesUnidades}
                />
              </Col>
              <Col flex="140px">
                {forma?.sem_peso ? (
                  <Typography.Text type="secondary">parte igual</Typography.Text>
                ) : forma?.automatico ? (
                  <Typography.Text type="secondary">automático</Typography.Text>
                ) : (
                  <InputNumber
                    aria-label={`${forma?.rotulo_peso ?? 'Peso'} da unidade ${i + 1}`}
                    min={0}
                    style={{ width: '100%' }}
                    disabled={disabled}
                    placeholder={forma?.rotulo_peso}
                    value={u.peso === '' || u.peso == null ? null : Number(u.peso)}
                    onChange={(v) => linha(i, { peso: v })}
                  />
                )}
              </Col>
              <Col flex="32px">
                <Button type="text" danger size="small" icon={<CloseOutlined />} disabled={disabled} aria-label={`Retirar a unidade ${i + 1}`}
                  onClick={() => mudar({ ...estado, unidades: estado.unidades.filter((_, n) => n !== i) })} />
              </Col>
            </Row>
          ))}
          <Space style={{ marginTop: 4 }}>
            <Button size="small" icon={<PlusOutlined />} disabled={disabled} onClick={() => mudar({ ...estado, unidades: [...estado.unidades, {}] })}>Alocar unidade</Button>
          </Space>
          {erro && <Alert style={{ marginTop: 8 }} type="warning" showIcon message={erro} />}
          {previa.error && <Alert style={{ marginTop: 8 }} type="warning" showIcon message={(previa.error as Error).message} />}
          {previa.data && !erro && (
            <Table<LinhaReparticao>
              style={{ marginTop: 8 }}
              size="small"
              pagination={false}
              rowKey="unidade_negocio_id"
              dataSource={previa.data.linhas}
              columns={[
                { title: 'Unidade', dataIndex: 'nome' },
                { title: '%', dataIndex: 'percentagem', align: 'right', render: (p: string) => `${Number(p).toFixed(2).replace('.', ',')} %` },
                ...(previa.data.total ? [{ title: 'Valor', dataIndex: 'valor', align: 'right' as const, render: (v: string) => formatarKz(v) }] : []),
              ]}
              summary={() => (previa.data?.total ? (
                <Table.Summary.Row>
                  <Table.Summary.Cell index={0}><strong>Total</strong></Table.Summary.Cell>
                  <Table.Summary.Cell index={1} />
                  <Table.Summary.Cell index={2} align="right"><strong>{formatarKz(previa.data.total)}</strong></Table.Summary.Cell>
                </Table.Summary.Row>
              ) : null)}
            />
          )}
        </>
      )}
    </div>
  );
}

/** Regra do Form.Item `unidades_alocadas`: recusa uma alocação inválida antes de enviar (o servidor valida de novo). */
export function regraAlocacao(criterios: { id: number; criterio: keyof typeof FORMAS }[] | undefined) {
  return {
    validator: async (_: unknown, v: Alocacao | null | undefined) => {
      if (!v || !v.criterio_id) return;
      const c = (criterios ?? []).find((x) => x.id === v.criterio_id);
      const erro = validarAlocacao(v, c?.criterio);
      if (erro) throw new Error(erro);
    },
  };
}
