import { Alert, Button, Card, Flex, InputNumber, Typography, message, theme } from 'antd';
import { useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';

interface ValidacaoInventarioAgt {
  nome: string;
  produtos: number;
  avisos: string[];
}

const AZUL = '#0f172a';

/**
 * Ficheiro de Inventário/Existências para a AGT (Regime Jurídico das Faturas, Decreto Presidencial n.º 71/25;
 * estrutura pela Circular n.º 05/GACA/GJ/DSF/AGT/2026): stock a 31 de Dezembro do exercício, em CSV (a AGT aceita
 * XML, JSON ou Excel/CSV pelo Portal do Contribuinte — não há uma XSD oficial publicada, ao contrário dos outros
 * ficheiros SAF-T deste sistema). Prazo legal: 15 de Fevereiro do ano seguinte.
 */
export default function InventarioAgt() {
  const { pode } = useSessao();
  const { token } = theme.useToken();
  const [ano, setAno] = useState<number>(new Date().getFullYear() - 1);
  const [aValidar, setAValidar] = useState(false);
  const [aGerar, setAGerar] = useState(false);
  const [previa, setPrevia] = useState<ValidacaoInventarioAgt | null>(null);
  const permitido = pode('armazem_stock_view');

  const validar = async () => {
    setAValidar(true);
    setPrevia(null);
    try {
      setPrevia(await obter<ValidacaoInventarioAgt>('/logistica/inventario-agt/validar', { ano }));
    } catch (e) {
      notificarErro(e, 'Não foi possível validar o exercício');
    } finally {
      setAValidar(false);
    }
  };

  const gerar = async () => {
    setAGerar(true);
    try {
      const v = await obter<ValidacaoInventarioAgt>('/logistica/inventario-agt/validar', { ano });
      const d = await descarregar('/logistica/inventario-agt', { ano }, v.nome || `INVENTARIO_AO_${ano}.csv`);
      message.success(`Inventário gerado: ${d.cabecalhos['x-inventario-produtos'] ?? v.produtos} produto(s).`);
      const avisos = d.cabecalhos['x-inventario-avisos'] ? decodeURIComponent(d.cabecalhos['x-inventario-avisos']) : v.avisos.join(' | ');
      if (avisos) message.warning(`Avisos: ${avisos}`, 10);
    } catch (e) {
      notificarErro(e, 'Não foi possível gerar o ficheiro de Inventário');
    } finally {
      setAGerar(false);
    }
  };

  if (!permitido) return <Alert type="info" showIcon message="Sem permissão para exportar o Inventário (AGT)." />;

  return (
    <>
      <div style={{ background: `linear-gradient(135deg, ${AZUL} 0%, #334155 100%)`, color: '#fff', borderRadius: 4, padding: 24, marginBottom: 24, position: 'relative', overflow: 'hidden' }}>
        <IconeFa nome="warehouse" style={{ position: 'absolute', right: -10, bottom: -20, fontSize: 120, opacity: 0.12, transform: 'rotate(-15deg)' }} />
        <h3 style={{ margin: '0 0 8px', fontSize: 22, fontWeight: 800, color: '#fff' }}><IconeFa nome="file-excel" /> Inventário / Existências (AGT)</h3>
        <p style={{ margin: 0, fontSize: 14, opacity: 0.95, maxWidth: 680 }}>
          Ficheiro do stock a 31 de Dezembro do exercício escolhido (quantidade, unidade, valor ao custo médio ponderado), conforme a Circular n.º 05/GACA/GJ/DSF/AGT/2026.
          Prazo legal: 15 de Fevereiro do ano seguinte.
        </p>
      </div>

      <Alert
        type="warning"
        showIcon
        style={{ marginBottom: 16 }}
        message="A AGT não publicou uma estrutura técnica oficial para este ficheiro (ao contrário do SAF-T de Facturação e Contabilidade)."
        description="Aceita XML, JSON ou Excel/CSV pelo Portal do Contribuinte; este ecrã gera CSV com os campos descritos pela Circular. Confirme o leiaute com o seu contabilista antes de submeter."
      />

      <Card>
        <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          <IconeFa nome="filter" style={{ color: AZUL }} /> Exercício
        </h4>
        <Flex gap={16} align="flex-end" wrap>
          <div>
            <Typography.Text style={{ display: 'block', fontSize: 12, fontWeight: 800, color: token.colorTextSecondary, textTransform: 'uppercase', marginBottom: 6 }}>
              Ano
            </Typography.Text>
            <InputNumber min={2000} max={new Date().getFullYear()} value={ano} onChange={(v) => setAno(v ?? ano)} aria-label="Exercício" style={{ width: 140 }} />
          </div>
          <Button icon={<IconeFa nome="search-dollar" />} loading={aValidar} onClick={() => void validar()}>Validar</Button>
          <Button type="primary" icon={<IconeFa nome="file-download" />} loading={aGerar} onClick={() => void gerar()}>Gerar e Descarregar CSV</Button>
        </Flex>
        {previa && (
          <Alert
            style={{ marginTop: 16 }}
            type={previa.avisos.length ? 'warning' : 'success'}
            showIcon
            message={`${previa.produtos} produto(s) de stock no exercício de ${ano}.`}
            description={previa.avisos.length ? <ul style={{ margin: 0, paddingLeft: 18 }}>{previa.avisos.map((a) => <li key={a}>{a}</li>)}</ul> : undefined}
          />
        )}
      </Card>
    </>
  );
}
