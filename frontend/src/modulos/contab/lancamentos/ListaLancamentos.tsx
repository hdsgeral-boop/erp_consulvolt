import { Button, Card, Checkbox, DatePicker, Input, Tag, Tooltip } from 'antd';
import { HistoryOutlined, ImportOutlined, PlusOutlined } from '@ant-design/icons';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import type { LinhaLancamento } from '../api';
import { rotuloTerceiro } from '../comum/terceiro';
import { ValorKz } from '../comum/Componentes';
import { useDiarios, porId } from '../comum/dados';
import { ModalImportar } from '../comum/ficheiros';
import { SeletorConta, SeletorDiario, SeletorTerceiro } from '../comum/Seletores';
import { ModalSaldosHistoricos } from './ModalSaldosHistoricos';

/** Lançamentos › listagem das linhas do diário (GET /contabilidade/lancamentos), com filtros. */
export function ListaLancamentos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const diarios = useDiarios();
  const nomesDiario = porId(diarios.data, (d) => d.codigo);
  const [diario, setDiario] = useState<number>();
  const [conta, setConta] = useState<string>();
  const [terceiro, setTerceiro] = useState<number>();
  const [numeroLan, setNumeroLan] = useState('');
  const [numeroDoc, setNumeroDoc] = useState('');
  const [pesquisa, setPesquisa] = useState('');
  const [classe9, setClasse9] = useState(false);
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [importar, setImportar] = useState(false);
  const [saldos, setSaldos] = useState(false);
  const [aceitarAvisos, setAceitarAvisos] = useState(false);

  const colunas: ColunaApi<LinhaLancamento>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData, width: 105 },
    { title: 'Diário', dataIndex: 'diario_id', render: (v: number) => <Tag>{nomesDiario.get(v) ?? v}</Tag>, responsive: ['md'] },
    { title: 'N.º lançamento', dataIndex: 'numero_lan', render: (v: string) => <strong>{v}</strong> },
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string | null) => v ?? '—', responsive: ['md'] },
    { title: 'Conta', dataIndex: 'codigo_conta' },
    { title: 'Terceiro', key: 'terceiro', ellipsis: true, width: 200, responsive: ['lg'], valorImpressao: (r) => (r.terceiro_id ? rotuloTerceiro(r.terceiro, r.terceiro_id) : '—'), render: (_, r) => (r.terceiro_id ? <Tooltip title={r.terceiro?.nif ? `NIF ${r.terceiro.nif}` : undefined}>{rotuloTerceiro(r.terceiro, r.terceiro_id)}</Tooltip> : '—') },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 320, valorImpressao: (r) => r.descricao ?? '—', render: (v: string | null) => <Tooltip title={v}>{v ?? '—'}</Tooltip> },
    { title: 'Débito', align: 'right', render: (_, r) => (r.tipo_dc === 'D' ? <ValorKz valor={r.valor} /> : null) },
    { title: 'Crédito', align: 'right', render: (_, r) => (r.tipo_dc === 'C' ? <ValorKz valor={r.valor} /> : null) },
    {
      title: 'Situação',
      responsive: ['lg'],
      render: (_, r) => (r.estorno_de_id ? <Tag color="purple">Estorno</Tag> : r.estornado_por_id ? <Tag color="red">Estornado</Tag> : r.tipo_origem ? <Tag>{r.tipo_origem}</Tag> : null),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Lançamentos"
        subtitulo="Linhas dos diários contabilísticos"
        accoes={
          <>
            {pode('lancamentos_import') && (
              <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>
                Importar
              </Button>
            )}
            {pode('lancamentos_saldos') && (
              <Button icon={<HistoryOutlined />} onClick={() => setSaldos(true)}>
                Saldos históricos
              </Button>
            )}
            {pode('lancamentos_post') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
                Novo lançamento
              </Button>
            )}
          </>
        }
      />
      <Card>
        <BarraFiltros>
          <SeletorDiario value={diario} onChange={setDiario} allowClear />
          <SeletorConta value={conta} onChange={setConta} allowClear incluirTotalizadoras placeholder="Conta (prefixo)" />
          <SeletorTerceiro value={terceiro} onChange={setTerceiro} />
          <Input.Search placeholder="N.º lançamento" allowClear style={{ width: 170 }} onSearch={setNumeroLan} />
          <Input.Search placeholder="N.º documento" allowClear style={{ width: 170 }} onSearch={setNumeroDoc} />
          <Input.Search placeholder="Descrição" allowClear style={{ width: 200 }} onSearch={setPesquisa} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Checkbox checked={classe9} onChange={(e) => setClasse9(e.target.checked)}>
            Incluir classe 9
          </Checkbox>
        </BarraFiltros>
        <TabelaApi<LinhaLancamento>
          url="/contabilidade/lancamentos"
          chaveConsulta={['contab', 'lancamentos']}
          porPagina={50}
          filtros={{
            diario_id: diario,
            codigo_conta: conta,
            terceiro_id: terceiro,
            numero_lan: numeroLan,
            numero_documento: numeroDoc,
            pesquisa,
            incluir_classe_9: classe9 ? 1 : undefined,
            data_inicio: dataApi(periodo?.[0]),
            data_fim: dataApi(periodo?.[1]),
          }}
          columns={colunas}
          impressao={{
            titulo: 'Lista de lançamentos',
            periodo: periodo?.[0] && periodo[1] ? `${formatarData(dataApi(periodo[0]))} a ${formatarData(dataApi(periodo[1]))}` : undefined,
            filtros: [
              !!diario && `Diário: ${nomesDiario.get(diario) ?? diario}`,
              conta && `Conta: ${conta}`,
              !!terceiro && `Terceiro: n.º ${terceiro}`,
              numeroLan && `N.º lançamento: ${numeroLan}`,
              numeroDoc && `N.º documento: ${numeroDoc}`,
              pesquisa && `Descrição: ${pesquisa}`,
              classe9 && 'Inclui classe 9',
            ],
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalImportar
        aberto={importar}
        titulo="Importar lançamentos"
        url="/contabilidade/lancamentos/importar"
        comSimulacao
        campos={{ aceitar_avisos: aceitarAvisos }}
        ajuda="Use o template de importação de lançamentos (XLSX, XLS ou CSV). Valide primeiro com a simulação: nada é gravado se houver erros."
        extra={
          <Checkbox checked={aceitarAvisos} onChange={(e) => setAceitarAvisos(e.target.checked)}>
            Aceitar avisos (códigos inexistentes ficam em branco)
          </Checkbox>
        }
        aoFechar={() => setImportar(false)}
        aoConcluir={() => void cliente.invalidateQueries({ queryKey: ['contab'] })}
      />
      {saldos && <ModalSaldosHistoricos aberto={saldos} aoFechar={() => setSaldos(false)} />}
    </>
  );
}
