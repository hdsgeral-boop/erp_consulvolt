import { Card, DatePicker, Input, Select, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { dataApi, formatarDataHora } from '@/utilitarios/formatacao';
import type { PropsSeparador } from '../DetalheProjecto';

interface Registo {
  id: number;
  ocorrido_em: string;
  nome_utilizador: string | null;
  acao: string;
  detalhes: string | null;
}

/**
 * Actividade do projecto (GET /projetos/{id}/atividade — o registo gravado por ServicoProjetos::registar, como o
 * logMovement('Projectos', …) do legado): lista cronológica, mais recente primeiro, com quem, quando e o quê; filtros
 * por texto, acção, utilizador e intervalo de datas (no servidor).
 */
export function SeparadorActividade({ projecto }: PropsSeparador) {
  const [pesquisa, setPesquisa] = useState<string>();
  const [acao, setAcao] = useState<string>();
  const [utilizador, setUtilizador] = useState<string>();
  const [datas, setDatas] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const opcoes = useQuery({
    queryKey: ['projectos', 'atividade-filtros', projecto.id],
    queryFn: () => obter<{ acoes: string[]; utilizadores: string[] }>(`/projetos/${projecto.id}/atividade/filtros`),
  });
  const colunas: ColunaApi<Registo>[] = [
    { title: 'Quando', dataIndex: 'ocorrido_em', width: 160, render: (v: string) => formatarDataHora(v) },
    { title: 'Quem', dataIndex: 'nome_utilizador', width: 180, render: (v: string | null) => v || <Typography.Text type="secondary">sistema</Typography.Text> },
    { title: 'Acção', dataIndex: 'acao', width: 240, render: (v: string) => <strong>{v}</strong> },
    { title: 'Detalhe', dataIndex: 'detalhes', render: (v: string | null) => v || '—' },
  ];
  const de = datas?.[0] ? dataApi(datas[0]) : undefined;
  const ate = datas?.[1] ? dataApi(datas[1]) : undefined;
  return (
    <Card title="Actividade do projecto">
      <BarraFiltros>
        <Input.Search placeholder="Procurar na acção ou no detalhe" allowClear onSearch={(v) => setPesquisa(v || undefined)} style={{ width: 260 }} aria-label="Procurar na actividade" />
        <Select allowClear showSearch placeholder="Acção" value={acao} onChange={setAcao} style={{ width: 220 }} aria-label="Acção" loading={opcoes.isLoading}
          options={(opcoes.data?.acoes ?? []).map((a) => ({ value: a, label: a }))} />
        <Select allowClear showSearch placeholder="Utilizador" value={utilizador} onChange={setUtilizador} style={{ width: 180 }} aria-label="Utilizador" loading={opcoes.isLoading}
          options={(opcoes.data?.utilizadores ?? []).map((a) => ({ value: a, label: a }))} />
        <DatePicker.RangePicker format="DD/MM/YYYY" value={datas} onChange={(v) => setDatas(v)} aria-label="Intervalo de datas" />
      </BarraFiltros>
      <TabelaApi<Registo>
        url={`/projetos/${projecto.id}/atividade`}
        chaveConsulta={['projectos', 'atividade', projecto.id]}
        filtros={{ pesquisa, acao, utilizador, de, ate }}
        porPagina={50}
        columns={colunas}
        size="small"
        scroll={scrollTabela()}
        impressao={{
          titulo: `Actividade — ${projecto.codigo ? `${projecto.codigo} ` : ''}${projecto.nome}`,
          filtros: [pesquisa && `Pesquisa: ${pesquisa}`, acao && `Acção: ${acao}`, utilizador && `Utilizador: ${utilizador}`, de && `De ${datas?.[0]?.format('DD/MM/YYYY')}`, ate && `Até ${datas?.[1]?.format('DD/MM/YYYY')}`],
        }}
      />
    </Card>
  );
}
