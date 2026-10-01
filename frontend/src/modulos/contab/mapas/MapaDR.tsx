import { Alert, Card, Checkbox, Form, Select } from 'antd';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { DemonstracaoResultados } from '../api';
import { TabelaDemonstracao } from '../comum/Demonstracao';
import { linhasDR } from '../comum/demonstracoes';
import { FiltrosMapa } from '../comum/FiltrosMapa';
import { useMapa } from '../comum/useMapa';

/** Mapas › Demonstração de resultados (ecrã contab_mapa_dr), por natureza, com comparativo. */
export default function MapaDR() {
  const mapa = useMapa<DemonstracaoResultados>('dr', '/contabilidade/relatorios/demonstracao-resultados');
  const d = mapa.data;
  const comparativo = !!mapa.parametros?.comparativo;
  const homologo = mapa.parametros?.modo_comparativo === 'homologo';

  return (
    <>
      <CabecalhoPagina titulo="Demonstração de resultados" subtitulo="Por natureza, com notas DEMO" />
      <Card style={{ marginBottom: 16 }}>
        <FiltrosMapa
          modo="periodo"
          aCalcular={mapa.isFetching}
          aoCalcular={mapa.calcular}
          avancados={{ unidade: true, centro: true, diario: true, classe9: true, apuramento: true }}
          valoresIniciais={{ comparativo: true, modo_comparativo: 'ano_anterior' }}
          extra={
            <>
              <Form.Item name="comparativo" valuePropName="checked" style={{ marginBottom: 8 }}>
                <Checkbox>Comparativo</Checkbox>
              </Form.Item>
              <Form.Item name="modo_comparativo" label="Comparar com" style={{ marginBottom: 8 }}>
                <Select style={{ width: 200 }} options={[{ value: 'ano_anterior', label: 'Ano anterior (completo)' }, { value: 'homologo', label: 'Período homólogo' }]} />
              </Form.Item>
            </>
          }
        />
      </Card>
      {d && (
        <>
          {comparativo && !d.historico_anterior && (
            <Alert type="info" showIcon style={{ marginBottom: 16 }} message="O comparativo é calculado a partir dos lançamentos (sem saldos históricos importados)." />
          )}
          <Card>
            <TabelaDemonstracao
              linhas={linhasDR(d)}
              comparativo={comparativo}
              rotuloAtual={String(d.ano)}
              rotuloAnterior={homologo ? `${d.ano - 1} (homólogo)` : String(d.ano - 1)}
              tipoNota="demonstracao"
              parametros={mapa.parametros ?? {}}
              nomeCsv={`demonstracao_resultados_${d.ano}`}
            />
          </Card>
        </>
      )}
    </>
  );
}
