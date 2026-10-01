## MODIFIED Requirements

### Requirement: Estados derivados de validade
The system SHALL derive the certificate state as not registered, valid, expiring within 30 calendar days, or expired using the application date. The system SHALL derive the procuração e-CAC state in the same four states exclusively from the authorized service families the provider confirmed for the client, and SHALL NOT derive it from any date, code or note typed by a member.

#### Scenario: Vencimento em trinta dias
- **WHEN** a certificate, or the earliest expiration among the authorized service families that make up a client's procuração e-CAC, falls between today and 30 calendar days from today inclusive
- **THEN** its state is returned and displayed as expiring soon with the expiration date

#### Scenario: Item vencido
- **WHEN** the expiration date of the certificate, or of any authorized service family that makes up the client's procuração e-CAC, is earlier than today
- **THEN** its state is returned and displayed as expired

#### Scenario: Procuração sem família confirmada
- **WHEN** the provider has not confirmed, as established, any authorized service family that the client's procuração e-CAC depends on
- **THEN** the procuração state is returned as not registered, even if the client once had procuração data typed by a member

## REMOVED Requirements

### Requirement: Controle da procuração e-CAC
**Reason**: A procuração e-CAC é outorgada no e-CAC e lida do provedor por família de serviço (`PROCURACOES/OBTERPROCURACAO41`). O cadastro manual de datas, código e notas criava uma segunda fonte de verdade que a elegibilidade cruzava com a Família de serviço autorizada e que divergia dela sem aviso. **BREAKING**: as rotas `PUT` e `DELETE /api/clients/{client}/ecac-power-of-attorney` deixam de existir e passam a responder 404 (rota inexistente); a tabela `client_ecac_powers_of_attorney` é removida.
**Migration**: A validade da procuração passa a ser a da Família de serviço autorizada, gravada pelo oráculo ao salvar o cliente, na rotina diária e dentro da sincronização (ver `serpro-connection` e `client-portfolio`). Datas e notas digitadas não são migradas; quem precisava delas deve conferir a outorga no e-CAC.
