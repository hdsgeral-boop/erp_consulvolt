import { Card, DatePicker, Flex, Input, Table, Tag, Typography } from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { dataApi, formatarDataHora } from '@/utilitarios/formatacao';
import { diferencas } from './comum/regras';

interface LogAuditoria {
  id: number;
  ocorrido_em: string;
  nome_utilizador: string | null;
  modulo: string | null;
  acao: string | null;
  tabela: string | null;
  registo_id: string | null;
  detalhes: string | null;
  dados_anteriores: Record<string, unknown> | null;
  dados_novos: Record<string, unknown> | null;
  endereco_ip: string | null;
}

const COR_ACCAO: Record<string, string> = { Criou: 'green', Atualizou: 'blue', Actualizou: 'blue', Eliminou: 'red', Entrou: 'cyan', Saiu: 'default' };

/** Configurações › Logs (config_logs): auditoria com filtros e diferenças antes/depois (GET /sistema/logs). */
export default function Logs() {
  const [filtros, setFiltros] = useState<Record<string, string | undefined>>({});
  const [datas, setDatas] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const campo = (k: string) => (v: string) => setFiltros({ ...filtros, [k]: v.trim() || undefined });
  return (
    <>
      <CabecalhoPagina titulo="Logs de auditoria" subtitulo="Quem fez o quê, quando e o que mudou" />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Input.Search placeholder="Pesquisar nos detalhes" allowClear style={{ width: 240 }} onSearch={campo('pesquisa')} />
          <Input.Search placeholder="Utilizador" allowClear style={{ width: 160 }} onSearch={campo('nome_utilizador')} />
          <Input.Search placeholder="Módulo" allowClear style={{ width: 160 }} onSearch={campo('modulo')} />
          <Input.Search placeholder="Acção" allowClear style={{ width: 140 }} onSearch={campo('acao')} />
          <Input.Search placeholder="Tabela" allowClear style={{ width: 160 }} onSearch={campo('tabela')} />
          <Input.Search placeholder="Registo (id)" allowClear style={{ width: 130 }} onSearch={campo('registo_id')} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={datas} onChange={(v) => setDatas(v)} allowEmpty={[true, true]} />
        </Flex>
        <TabelaApi<LogAuditoria>
          url="/sistema/logs"
          chaveConsulta={['sistema', 'logs']}
          porPagina={50}
          filtros={{ ...filtros, data_inicio: dataApi(datas?.[0]), data_fim: dataApi(datas?.[1]) }}
          size="small"
          expandable={{
            rowExpandable: (l) => !!(l.dados_anteriores || l.dados_novos),
            expandedRowRender: (l) => {
              const linhas = diferencas(l.dados_anteriores, l.dados_novos);
              return (
                <Table
                  size="small"
                  rowKey="campo"
                  pagination={false}
                  dataSource={linhas}
                  columns={[
                    { title: 'Campo', dataIndex: 'campo', width: 220 },
                    { title: 'Antes', dataIndex: 'antes', render: (v: string) => <Typography.Text type="secondary" style={{ wordBreak: 'break-all' }}>{v}</Typography.Text> },
                    { title: 'Depois', dataIndex: 'depois', render: (v: string, d) => <Typography.Text strong={d.alterado} style={{ wordBreak: 'break-all' }}>{v}</Typography.Text> },
                  ]}
                />
              );
            },
          }}
          columns={[
            { title: 'Data e hora', dataIndex: 'ocorrido_em', width: 150, render: formatarDataHora },
            { title: 'Utilizador', dataIndex: 'nome_utilizador', width: 140 },
            { title: 'Módulo', dataIndex: 'modulo', width: 160 },
            { title: 'Acção', dataIndex: 'acao', width: 110, render: (a: string | null) => (a ? <Tag color={COR_ACCAO[a]}>{a}</Tag> : '—') },
            { title: 'Registo', key: 'reg', width: 160, render: (_, l) => (l.tabela ? `${l.tabela}${l.registo_id ? ` #${l.registo_id}` : ''}` : '—') },
            { title: 'Detalhes', dataIndex: 'detalhes', render: (v: string | null) => <span style={{ whiteSpace: 'normal' }}>{v ?? '—'}</span> },
            { title: 'IP', dataIndex: 'endereco_ip', width: 120, render: (v: string | null) => v ?? '—' },
          ]}
        />
      </Card>
    </>
  );
}
