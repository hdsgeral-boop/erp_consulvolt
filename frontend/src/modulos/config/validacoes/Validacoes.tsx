import { Alert, Button, Card, Checkbox, Descriptions, Drawer, Empty, Input, Select, Skeleton, Space, Statistic, Table, Tag, Typography } from 'antd';
import { ExportOutlined, ReloadOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { BotoesExportar, pares, tabelaHtml } from '@/componentes/impressao';
import { BarraFiltros, COLUNAS_DESCRICOES, larguraGaveta, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarNumero } from '@/utilitarios/formatacao';
import {
  COR_GRAVIDADE,
  LIMITE_DETALHE,
  ROTULO_GRAVIDADE,
  alinharDireita,
  colunasDetalhe,
  filtrarValidacoes,
  formatarCelula,
  ligacaoDaValidacao,
  ordenarValidacoes,
  resumirGravidades,
  rotaDaLinha,
  rotuloColuna,
  type DetalheValidacao,
  type ResumoValidacao,
} from './regras';

const CHAVE = ['sistema', 'validacoes'];

function EtiquetaGravidade({ gravidade }: { gravidade: string }) {
  return <Tag color={COR_GRAVIDADE[gravidade] ?? 'default'}>{ROTULO_GRAVIDADE[gravidade] ?? gravidade}</Tag>;
}

/**
 * Configurações › Manutenção de dados › Validações de dados (A-01, ADR-015): as rotinas do legado que alteravam ou
 * apagavam dados ao abrir ecrãs passaram a relatórios só de leitura. Contagem por validação, gravidade, descrição e
 * origem no legado; detalhe com as linhas e a ligação ao ecrã onde o registo se corrige.
 */
export default function Validacoes() {
  const pequeno = useEcraPequeno();
  const consulta = useQuery({ queryKey: CHAVE, queryFn: () => obter<ResumoValidacao[]>('/sistema/validacoes'), staleTime: 60_000 });
  const [gravidade, setGravidade] = useState<string>();
  const [modulo, setModulo] = useState<string>();
  const [soComOcorrencias, setSoComOcorrencias] = useState(true);
  const [pesquisa, setPesquisa] = useState('');
  const [aberta, setAberta] = useState<ResumoValidacao | null>(null);

  const todas = useMemo(() => ordenarValidacoes(consulta.data ?? []), [consulta.data]);
  const resumo = useMemo(() => resumirGravidades(todas), [todas]);
  const modulos = useMemo(() => [...new Set(todas.map((v) => v.modulo))].sort((a, b) => a.localeCompare(b, 'pt')), [todas]);
  const visiveis = filtrarValidacoes(todas, { gravidade, modulo, soComOcorrencias, pesquisa });
  const filtrosTexto = [gravidade && `Gravidade: ${ROTULO_GRAVIDADE[gravidade] ?? gravidade}`, modulo && `Módulo: ${modulo}`, soComOcorrencias ? 'Só com ocorrências' : 'Todas as validações', pesquisa && `Pesquisa: ${pesquisa}`]
    .filter(Boolean) as string[];

  if (consulta.isLoading) return <Skeleton active />;
  if (consulta.isError) {
    return <Alert type="error" showIcon message="Não foi possível executar as validações." description={consulta.error instanceof Error ? consulta.error.message : undefined} action={<Button onClick={() => void consulta.refetch()}>Tentar de novo</Button>} />;
  }

  return (
    <>
      <Typography.Paragraph type="secondary">
        Verificações só de leitura aos dados da empresa activa (substituem as rotinas do sistema antigo que corrigiam ou apagavam dados sem aviso). Corrija cada caso no
        ecrã indicado; as correcções em massa fazem-se por pedido no separador «Novo pedido».
      </Typography.Paragraph>
      <Card size="small" style={{ marginBottom: 16 }}>
        <Space size={[40, 12]} wrap>
          <Statistic title="Validações com ocorrências" value={`${resumo.comOcorrencias} de ${resumo.total}`} />
          <Statistic title="Erros" value={resumo.porGravidade.ERRO ?? 0} valueStyle={{ color: (resumo.porGravidade.ERRO ?? 0) > 0 ? '#cf1322' : undefined }} />
          <Statistic title="Avisos" value={resumo.porGravidade.AVISO ?? 0} valueStyle={{ color: (resumo.porGravidade.AVISO ?? 0) > 0 ? '#d46b08' : undefined }} />
          <Statistic title="Informações" value={resumo.porGravidade.INFO ?? 0} />
          <Statistic title="Registos assinalados" value={formatarNumero(resumo.ocorrencias)} />
        </Space>
      </Card>
      <BarraFiltros
        style={{ marginBottom: 12 }}
        accoes={
          <>
            <Button icon={<ReloadOutlined />} loading={consulta.isFetching} onClick={() => void consulta.refetch()}>
              Executar novamente
            </Button>
            <BotoesExportar
              desactivado={!visiveis.length}
              obterPedido={() => ({
                titulo: 'Validações de dados',
                filtros: filtrosTexto,
                conteudo:
                  pares([['Validações com ocorrências', `${resumo.comOcorrencias} de ${resumo.total}`], ['Erros', resumo.porGravidade.ERRO ?? 0], ['Avisos', resumo.porGravidade.AVISO ?? 0], ['Registos assinalados', resumo.ocorrencias]], 4) +
                  tabelaHtml({
                    colunas: [
                      { titulo: 'Gravidade', valor: (v: ResumoValidacao) => ROTULO_GRAVIDADE[v.gravidade] ?? v.gravidade },
                      { titulo: 'Módulo', valor: (v) => v.modulo },
                      { titulo: 'Validação', valor: (v) => v.titulo, quebrar: true },
                      { titulo: 'Descrição', valor: (v) => v.descricao, quebrar: true },
                      { titulo: 'Origem no sistema antigo', valor: (v) => v.legado, quebrar: true },
                      { titulo: 'Ocorrências', valor: (v) => v.ocorrencias, formato: 'inteiro', somar: true },
                    ],
                    linhas: visiveis,
                    totais: true,
                  }),
              })}
            />
          </>
        }
      >
        <Select placeholder="Gravidade" allowClear style={{ width: 150 }} value={gravidade} onChange={setGravidade} options={Object.entries(ROTULO_GRAVIDADE).map(([value, label]) => ({ value, label }))} />
        <Select placeholder="Módulo" allowClear style={{ width: 180 }} value={modulo} onChange={setModulo} options={modulos.map((m) => ({ value: m, label: m }))} />
        <Input.Search placeholder="Pesquisar validação" allowClear style={{ width: 240 }} onSearch={setPesquisa} />
        <Checkbox checked={soComOcorrencias} onChange={(e) => setSoComOcorrencias(e.target.checked)}>Só com ocorrências</Checkbox>
      </BarraFiltros>
      <Table<ResumoValidacao>
        rowKey="codigo"
        size={pequeno ? 'small' : 'middle'}
        dataSource={visiveis}
        pagination={false}
        scroll={{ x: 720 }}
        locale={{ emptyText: <Empty description={soComOcorrencias ? 'Nenhuma validação com ocorrências: os dados passam em todas as verificações.' : 'Sem validações.'} /> }}
        onRow={(v) => ({ onClick: () => setAberta(v), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Gravidade', dataIndex: 'gravidade', width: 110, render: (g: string) => <EtiquetaGravidade gravidade={g} /> },
          { title: 'Módulo', dataIndex: 'modulo', responsive: ['md'] },
          {
            title: 'Validação',
            dataIndex: 'titulo',
            render: (t: string, v) => (
              <>
                <strong>{t}</strong>
                <div className="erp-oculto-telemovel" style={{ fontSize: 12, opacity: 0.75 }}>{v.descricao}</div>
              </>
            ),
          },
          { title: 'Ocorrências', dataIndex: 'ocorrencias', align: 'right', render: (n: number) => (n > 0 ? <Typography.Text strong>{formatarNumero(n)}</Typography.Text> : <Typography.Text type="secondary">0</Typography.Text>) },
          { title: '', key: 'ver', width: 90, render: (_, v) => <Button size="small" onClick={(e) => { e.stopPropagation(); setAberta(v); }}>Detalhe</Button> },
        ]}
      />
      {aberta && <DetalheValidacaoGaveta validacao={aberta} aoFechar={() => setAberta(null)} />}
    </>
  );
}

function DetalheValidacaoGaveta({ validacao, aoFechar }: { validacao: ResumoValidacao; aoFechar: () => void }) {
  const navegar = useNavigate();
  const { menu } = useSessao();
  const pequeno = useEcraPequeno();
  const consulta = useQuery({ queryKey: [...CHAVE, validacao.codigo], queryFn: () => obter<DetalheValidacao>(`/sistema/validacoes/${validacao.codigo}`) });
  // chave estável por linha (as consultas não têm todas um id)
  const linhas = useMemo<Record<string, unknown>[]>(() => (consulta.data?.linhas ?? []).map((l, i) => ({ ...l, __chave: i })), [consulta.data]);
  const colunas = colunasDetalhe(consulta.data?.linhas ?? []);
  const ligacao = ligacaoDaValidacao(validacao.codigo, colunas);
  const acessivel = !!ligacao && menu.some((m) => m.id === ligacao.modulo && m.ecras.some((e) => e.id === ligacao.ecra));
  const truncado = (consulta.data?.total_mostrado ?? 0) < validacao.ocorrencias;

  return (
    <Drawer
      open
      title={validacao.titulo}
      width={larguraGaveta(980)}
      onClose={aoFechar}
      extra={
        <BotoesExportar
          tamanho="small"
          desactivado={!linhas.length}
          obterPedido={() => ({
            titulo: validacao.titulo,
            subtitulo: `Validação de dados — ${validacao.modulo}`,
            filtros: [`Gravidade: ${ROTULO_GRAVIDADE[validacao.gravidade] ?? validacao.gravidade}`, `${validacao.ocorrencias} ocorrência(s)${truncado ? `, mostradas ${linhas.length}` : ''}`],
            conteudo:
              pares([['Descrição', validacao.descricao], ['Origem no sistema antigo', validacao.legado]], 1) +
              tabelaHtml({ colunas: colunas.map((c) => ({ titulo: rotuloColuna(c), valor: (l: Record<string, unknown>) => formatarCelula(c, l[c]), quebrar: true })), linhas }),
          })}
        />
      }
    >
      <Descriptions size="small" column={COLUNAS_DESCRICOES} style={{ marginBottom: 12 }}>
        <Descriptions.Item label="Gravidade"><EtiquetaGravidade gravidade={validacao.gravidade} /></Descriptions.Item>
        <Descriptions.Item label="Módulo">{validacao.modulo}</Descriptions.Item>
        <Descriptions.Item label="Ocorrências">{formatarNumero(validacao.ocorrencias)}</Descriptions.Item>
        <Descriptions.Item label="Descrição" span="filled">{validacao.descricao}</Descriptions.Item>
        <Descriptions.Item label="Origem no sistema antigo" span="filled">{validacao.legado}</Descriptions.Item>
        <Descriptions.Item label="Código" span="filled"><Typography.Text code>{validacao.codigo}</Typography.Text></Descriptions.Item>
      </Descriptions>
      {truncado && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`Mostram-se as primeiras ${Math.min(LIMITE_DETALHE, linhas.length)} de ${formatarNumero(validacao.ocorrencias)} ocorrências.`} />}
      {ligacao && !acessivel && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Os registos corrigem-se num ecrã a que não tem acesso." />}
      {consulta.isLoading ? (
        <Skeleton active />
      ) : consulta.isError ? (
        <Alert type="error" showIcon message="Não foi possível obter o detalhe." description={consulta.error instanceof Error ? consulta.error.message : undefined} />
      ) : (
        <Table<Record<string, unknown>>
          rowKey="__chave"
          size={pequeno ? 'small' : 'middle'}
          dataSource={linhas}
          pagination={{ pageSize: 50, showSizeChanger: false, hideOnSinglePage: true }}
          scroll={scrollTabela()}
          locale={{ emptyText: 'Sem ocorrências.' }}
          columns={[
            ...colunas.map((c) => ({
              title: rotuloColuna(c),
              dataIndex: c,
              ellipsis: true,
              align: (alinharDireita(c, linhas.find((l) => l[c] !== null && l[c] !== undefined)?.[c]) ? 'right' : 'left') as 'right' | 'left',
              render: (v: unknown) => formatarCelula(c, v),
            })),
            ...(ligacao && acessivel
              ? [
                  {
                    title: '',
                    key: 'abrir',
                    fixed: 'right' as const,
                    width: 60,
                    render: (_: unknown, l: Record<string, unknown>) => {
                      const rota = rotaDaLinha(ligacao, l);
                      return rota ? <Button size="small" type="link" icon={<ExportOutlined />} aria-label={ligacao.rotulo} title={ligacao.rotulo} onClick={() => navegar(rota)} /> : null;
                    },
                  },
                ]
              : []),
          ]}
        />
      )}
    </Drawer>
  );
}
