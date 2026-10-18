## ADDED Requirements

### Requirement: ERP-issued origin preserves complete accounting readiness
Accounting publication of an ERP-issued document SHALL satisfy the complete-only, private-byte availability and length/SHA-256 integrity contract established by complete-xml-accounting-workflow. Drafts, summaries, standalone events and indeterminate artifacts SHALL not become ready accounting rows. ERP issuance provenance SHALL be explicitly distinguished from authenticated recipient distribution and SHALL NOT authorize automatic recipient science or any conclusive recipient event. Publication SHALL preserve Account/Client isolation and original artifact identity when merging capture and ERP references.

#### Scenario: Self-issued ERP note becomes available
- **WHEN** a verified complete authorized ERP XML is delivered to the permitted office Client
- **THEN** it becomes available once under its actual ERP-issued origin without dispatching automatic recipient manifestation

#### Scenario: Issued file is corrupt
- **WHEN** an ERP handoff references bytes whose hash differs from the accepted artifact
- **THEN** the note is excluded from ready publication and integrity attention preserves its historical provider evidence
