import { Alert, Button, Card, Checkbox, Form } from 'antd';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { formatarKz } from '@/utilitarios/formatacao';
import type { Balanco } from '../api';
import { MovimentosSemNota, TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasBalanco } from '../comum/demonstracoes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { useMapa } from '../comum/useMapa';

/** Mapas › Balanço (ecrã contab_mapa_balanco), por notas DEMO, com comparativo e controlo de equilíbrio. */
export default function MapaBalanco() {
  const mapa = useMapa<Balanco>('balanco', '/contabilidade/relatorios/balanco');
  const [semNota, setSemNota] = useState(false);
  const d = mapa.data;
  const comparativo = !!mapa.parametros?.comparativo;
  const controlo = d?.controlo.atual;

  return (
    <>
      <CabecalhoPagina titulo="Balanço" subtitulo="Demonstração da posição financeira por notas" />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="ate"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          avancados={{ unidade: true, centro: true, classe9: true, apuramento: true }}
          valoresIniciais={{ comparativo: true }}
          extra={
            <Form.Item name="comparativo" valuePropName="checked" style={{ marginBottom: 8 }}>
              <Checkbox>Comparativo com o ano anterior</Checkbox>
            </Form.Item>
          }
        />
      </Card>
      {d && controlo && (
        <>
          {!controlo.equilibrado && (
            <Alert
              type="warning"
              showIcon
              style={{ marginBottom: 16 }}
              message={`O Balanço não está equilibrado: diferença de ${formatarKz(controlo.diferenca, true)} entre o Activo e o Capital Próprio + Passivo.`}
              description={
                controlo.sem_nota.linhas > 0
                  ? `Há ${controlo.sem_nota.linhas} linha(s) sem nota DEMO (débitos ${formatarKz(controlo.sem_nota.debito)} · créditos ${formatarKz(controlo.sem_nota.credito)}).`
                  : undefined
              }
              action={controlo.sem_nota.linhas > 0 ? <Button size="small" onClick={() => setSemNota(true)}>Movimentos por mapear</Button> : undefined}
            />
          )}
          {comparativo && !d.historico_anterior && d.ano_anterior && (
            <Alert type="info" showIcon style={{ marginBottom: 16 }} message={`O comparativo de ${d.ano_anterior} é calculado a partir dos lançamentos (sem saldos históricos importados).`} />
          )}
          <Card>
            <TabelaDemonstracao
              linhas={linhasBalanco(d)}
              comparativo={comparativo}
              rotuloAtual={String(d.ano)}
              rotuloAnterior={String(d.ano_anterior)}
              tipoNota="demonstracao"
              parametros={mapa.parametros ?? {}}
              nomeCsv={`balanco_${d.data_fim}`}
            />
          </Card>
        </>
      )}
      {semNota && mapa.parametros && <MovimentosSemNota parametros={{ data_fim: mapa.parametros.data_fim, data_inicio: d?.data_inicio }} aoFechar={() => setSemNota(false)} />}
    </>
  );
}
