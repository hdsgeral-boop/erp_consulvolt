import dayjs from 'dayjs';
import { describe, expect, it } from 'vitest';
import { periodoRelatorioLav } from './Lavandaria';

describe('período do relatório da lavandaria (periodoRelatorio do antigo)', () => {
  const d = (s: string) => dayjs(s);
  it('dia, mês e intervalo (invertido) com o rótulo do antigo', () => {
    const dia = periodoRelatorioLav('DIA', d('2026-10-09'), d('2026-10-01'), d('2026-10-01'), d('2026-10-09'));
    expect([dia.de.format('YYYY-MM-DD'), dia.ate.format('YYYY-MM-DD'), dia.rotulo]).toEqual(['2026-10-09', '2026-10-09', 'Dia 09/10/2026']);
    const mes = periodoRelatorioLav('MES', d('2026-10-09'), d('2026-02-14'), d('2026-10-01'), d('2026-10-09'));
    expect([mes.de.format('YYYY-MM-DD'), mes.ate.format('YYYY-MM-DD'), mes.rotulo]).toEqual(['2026-02-01', '2026-02-28', 'Mês 02/2026']);
    const int = periodoRelatorioLav('PERIODO', d('2026-10-09'), d('2026-10-01'), d('2026-10-09'), d('2026-10-01'));
    expect([int.de.format('YYYY-MM-DD'), int.ate.format('YYYY-MM-DD'), int.rotulo]).toEqual(['2026-10-01', '2026-10-09', 'De 01/10/2026 a 09/10/2026']);
  });
});
