## MODIFIED Requirements

### Requirement: Congelamento do gerado
The system SHALL NOT alter generated processes or their tasks when the template rule, client regime/tags or blueprint change afterwards; such changes SHALL only affect future reference months; the department is excluded from this freeze: generated tasks SHALL show the current name of the referenced department, and deleting that department SHALL leave them without department; changing which department a blueprint step references SHALL only affect future reference months.

#### Scenario: Blueprint muda após geração
- **WHEN** a blueprint step is edited after March generation
- **THEN** March processes keep the snapshot values while April processes use the new ones

#### Scenario: Renomear departamento reflete no gerado
- **WHEN** the Fiscal department is renamed to Tributário after March generation
- **THEN** March tasks that reference it show Tributário

#### Scenario: Trocar o departamento da etapa não altera o gerado
- **WHEN** a blueprint step is moved from Fiscal to Pessoal after March generation
- **THEN** March tasks keep referencing Fiscal while April tasks reference Pessoal
