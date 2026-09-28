// tests/monitoringTermGuidance.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  enablementAction,
  enablementConfirm,
  enablementNotice,
  enablementState
} from '../app/utils/serproEnablement.ts'

describe('habilitação da integração por Account', () => {
  it('desabilitação nomeia o Account sem apagar histórico', () => {
    assert.equal(enablementNotice(false), 'Integração desabilitada para este Account')
    assert.equal(enablementNotice(true), 'Integração habilitada')
  })

  it('o estado lido é o flag, com a legenda que a desliga promete conservar', () => {
    assert.equal(enablementState(true).label, 'Habilitada')
    assert.equal(enablementState(false).label, 'Desabilitada')
    assert.match(enablementState(false).description, /histórico/)
  })

  it('a ação é de ligar quando está desligada e de desligar quando está ligada', () => {
    assert.equal(enablementAction(true), 'Desabilitar integração')
    assert.equal(enablementAction(false), 'Habilitar integração')
  })

  it('desligar é o passo que pede confirmação, ligar não', () => {
    assert.equal(enablementConfirm(true) !== null, true)
    assert.equal(enablementConfirm(false), null)
  })
})
