// tests/monitoringStatus.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  formatMonitoringProgress,
  monitoringAttentionReasonPresentation,
  monitoringCategoryPresentation,
  monitoringCounterPresentation,
  monitoringCountersTotal,
  monitoringMissingValue,
  monitoringProgressPresentation,
  monitoringProvenance,
  monitoringProvenanceLabels,
  monitoringSituacaoMissingPresentation,
  monitoringSituacaoPresentation,
  serproRunCountersTotal,
  serproRunItemStatePresentation,
  serproRunStatePresentation,
  serproTermGuidance,
  serproTermStatePresentation
} from '../app/utils/monitoringPresentation.ts'
import type { AttentionReasonCode, MonitoringCounter, MonitoringSituacao, ObligationCategory, SerproRunItemState, SerproSyncRunState } from '../app/types/serpro.ts'

const counters: MonitoringCounter[] = ['em_dia', 'processando', 'pendencias', 'atencao']
const situacoes: MonitoringSituacao[] = ['em_dia', 'processando', 'pendencias', 'atencao', 'encerrado']
const categories: ObligationCategory[] = ['direct', 'derived', 'unavailable', 'extinct']
const runStates: SerproSyncRunState[] = ['queued', 'running', 'completed', 'partial', 'failed']
const itemStates: SerproRunItemState[] = ['sincronizado', 'ignorado', 'falhou', 'indeterminado', 'nao_processado']
const causes: AttentionReasonCode[] = ['sem_declaracao', 'sem_procuracao', 'procuracao_invalida', 'contam_debitos']

describe('monitoring counters', () => {
  it('labels, colours and icons every counter of the partition', () => {
    for (const counter of counters) {
      const entry = monitoringCounterPresentation[counter]
      assert.ok(entry.label, `${counter} has no label`)
      assert.ok(entry.color, `${counter} has no colour`)
      assert.ok(entry.icon, `${counter} has no icon`)
    }
  })

  it('keeps encerrado out of the four counters', () => {
    assert.equal((monitoringCounterPresentation as Record<string, unknown>).encerrado, undefined)
  })

  it('sums the four counters plus the never-consulted into the total', () => {
    assert.equal(monitoringCountersTotal({
      em_dia: 8,
      processando: 1,
      pendencias: 1,
      atencao: 2,
      nao_consultadas: 3
    }), 15)
  })

  it('counts the never-consulted in the total without touching pendencias', () => {
    assert.equal(monitoringCountersTotal({
      em_dia: 0,
      processando: 0,
      pendencias: 0,
      atencao: 0,
      nao_consultadas: 7
    }), 7)
  })
})

describe('the progress axis', () => {
  it('reads as a reading of the synchronization, not a client state', () => {
    const line = formatMonitoringProgress({ transmitted: 3, requested: 40 })
    assert.match(line ?? '', /3/)
    assert.match(line ?? '', /40/)
    assert.match(line ?? '', /sincroniza/i)
    assert.match(line ?? '', /nenhum cliente/i)
  })

  it('is a sentence, never a truncated "3 de"', () => {
    const line = formatMonitoringProgress({ transmitted: 0, requested: 0 })
    assert.equal(line?.includes('de .'), false)
    assert.ok((line?.match(/de/g) ?? []).length >= 1)
  })

  it('renders nothing at all while the pair is absent', () => {
    assert.equal(formatMonitoringProgress(null), null)
    assert.equal(formatMonitoringProgress(undefined), null)
  })

  it('keeps zero transmitted visible rather than omitting the reading', () => {
    assert.match(formatMonitoringProgress({ transmitted: 0, requested: 12 }) ?? '', /: 0 de 12/)
  })

  it('carries the icon the panel draws it with', () => {
    assert.ok(monitoringProgressPresentation.icon.startsWith('i-lucide-'))
    assert.ok(monitoringProgressPresentation.label.length > 0)
  })
})

describe('monitoring situations', () => {
  it('labels, colours and icons every row state', () => {
    for (const situacao of situacoes) {
      const entry = monitoringSituacaoPresentation[situacao]
      assert.ok(entry.label, `${situacao} has no label`)
      assert.ok(entry.color, `${situacao} has no colour`)
      assert.ok(entry.icon, `${situacao} has no icon`)
    }
  })

  it('presents a closed obligation as neutral, outside the partition', () => {
    assert.equal(monitoringSituacaoPresentation.encerrado.color, 'neutral')
  })

  it('draws the situation of a never-consulted row as the em dash, not a state', () => {
    assert.equal(monitoringSituacaoMissingPresentation.label, monitoringMissingValue)
    assert.equal(monitoringSituacaoMissingPresentation.color, 'neutral')
  })
})

describe('monitoring attention reasons', () => {
  it('resolves each cause code to a label and a colour', () => {
    for (const code of causes) {
      const entry = monitoringAttentionReasonPresentation(code)
      assert.ok(entry.label, `${code} has no label`)
      assert.ok(entry.color, `${code} has no colour`)
    }
  })

  it('resolves an unknown code from the backend label rather than going blank', () => {
    const entry = monitoringAttentionReasonPresentation('causa_nova', 'Procuração suspensa')
    assert.equal(entry.label, 'Procuração suspensa')
    assert.equal(entry.color, 'warning')
  })

  it('falls back to the code itself when no wording arrives', () => {
    const entry = monitoringAttentionReasonPresentation('causa_nova')
    assert.equal(entry.label, 'causa_nova')
    assert.equal(entry.color, 'warning')
  })
})

describe('obligation categories', () => {
  it('labels and explains every category', () => {
    for (const category of categories) {
      const entry = monitoringCategoryPresentation[category]
      assert.ok(entry.label, `${category} has no label`)
      assert.ok(entry.description.length > 20, `${category} has no explanation`)
    }
  })

  it('says an unserved obligation is not a client pending', () => {
    assert.match(monitoringCategoryPresentation.unavailable.description, /obrigação/i)
    assert.match(monitoringCategoryPresentation.extinct.description, /deixou de ser devida/i)
  })
})

describe('provenance of a derived obligation', () => {
  const derived = {
    category: 'derived' as const,
    derivedFrom: 'o relatório SITFIS',
    service: 'SITFIS/RELATORIOSITFIS92'
  }

  it('names what it projects over and the call it projects it with', () => {
    const provenance = monitoringProvenance(derived)
    assert.equal(provenance?.origin, 'o relatório SITFIS')
    assert.equal(provenance?.service, 'SITFIS/RELATORIOSITFIS92')
  })

  it('has no provenance for an obligation that is not a projection', () => {
    assert.equal(monitoringProvenance({ category: 'direct', service: 'MEI/DIVIDAATIVA24' }), null)
    assert.equal(monitoringProvenance({ category: 'unavailable', service: null }), null)
    assert.equal(monitoringProvenance({ category: 'extinct', service: null }), null)
  })

  it('still renders a derived obligation whose origin was not declared', () => {
    const provenance = monitoringProvenance({ category: 'derived', service: 'CAIXAPOSTAL' })
    assert.equal(provenance?.origin, null)
    assert.equal(provenance?.service, 'CAIXAPOSTAL')
  })

  it('labels the two things a projection has to declare', () => {
    assert.ok(monitoringProvenanceLabels.origin.length > 0)
    assert.ok(monitoringProvenanceLabels.service.length > 0)
  })
})

describe('run counters', () => {
  it('sums the six reported counts, including the two that are not outcomes', () => {
    const run = {
      total: 9,
      synchronized: 4,
      skipped: 1,
      failed: 1,
      indeterminate: 1,
      not_processed: 2
    }
    assert.equal(serproRunCountersTotal(run), 9)
    assert.equal(serproRunCountersTotal(run), run.total)
  })

  it('counts indeterminate on its own, never inside failed', () => {
    const run = {
      total: 3,
      synchronized: 1,
      skipped: 0,
      failed: 0,
      indeterminate: 2,
      not_processed: 0
    }
    assert.equal(run.failed, 0)
    assert.equal(serproRunCountersTotal(run), 3)
  })
})

describe('run and term vocabulary', () => {
  it('labels every run state and item state', () => {
    for (const state of runStates) assert.ok(serproRunStatePresentation[state].label)
    for (const state of itemStates) assert.ok(serproRunItemStatePresentation[state].label)
  })

  it('separates not-processed from skipped', () => {
    assert.notEqual(serproRunItemStatePresentation.nao_processado.label, serproRunItemStatePresentation.ignorado.label)
  })

  it('labels every term state', () => {
    for (const state of ['ausente', 'pendente', 'validado', 'autenticado', 'vencido', 'recusado'] as const) {
      assert.ok(serproTermStatePresentation[state].label)
    }
  })

  it('names the office as the actor for a lapsed or refused term', () => {
    for (const hasCertificate of [false, true]) {
      assert.doesNotMatch(serproTermGuidance('vencido', hasCertificate), /sem nenhuma ação sua|não há nada a fazer/i)
      assert.doesNotMatch(serproTermGuidance('recusado', hasCertificate), /sem nenhuma ação sua|não há nada a fazer/i)
    }
    assert.match(serproTermGuidance('vencido', false), /escritório/i)
  })

  it('gives guidance for every term state, with and without a stored certificate', () => {
    for (const state of ['ausente', 'pendente', 'validado', 'autenticado', 'vencido', 'recusado'] as const) {
      for (const hasCertificate of [false, true]) {
        assert.ok(serproTermGuidance(state, hasCertificate).length > 0, `${state}/${hasCertificate} has no guidance`)
      }
    }
  })
})
