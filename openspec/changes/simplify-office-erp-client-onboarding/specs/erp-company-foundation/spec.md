## ADDED Requirements

### Requirement: Administrative provisioning separates subscription from owner access
An eligible office administrator SHALL be able to provision a new Company for an active portfolio company Client without inventing an owner identity. The Company SHALL remain explicitly awaiting verified owner setup until its intended responsible person creates or authenticates their identity using a revocable, single-use setup continuation. Subscription assignment SHALL grant neither direct Company membership to the office administrator nor implicit accounting delegation. A Company with an existing owner SHALL retain that owner and require the owner's explicit authorization before a new office association. Pending owner setup SHALL not expose operating Company data to the office. Setup SHALL be recoverable without repeating subscription activation, invoices or trial allocation.

#### Scenario: Office admin attempts to operate a newly provisioned Company
- **WHEN** an office admin has only commercial administration and no direct Company membership or explicit delegation
- **THEN** Company selection and operating access are denied even when its subscription is active

#### Scenario: Verified owner completes access setup
- **WHEN** the intended responsible person completes valid one-time setup for an unclaimed office-provisioned Company
- **THEN** that identity becomes its owner without creating an office Account, changing the assigned subscription or issuing another invoice

#### Scenario: Unrelated identity attempts owner setup
- **WHEN** an identity different from the intended responsible person attempts setup or a consumed continuation is reused by a foreign actor
- **THEN** access is denied without revealing or changing Company ownership
