import type { ErpCompanySettingsPayload } from '#shared/types/erp-company-settings'

export type ErpSettingsField = {
  key: keyof ErpCompanySettingsPayload | string
  label: string
  type?: 'text' | 'number' | 'decimal' | 'code' | 'select' | 'checkbox'
  group?: string
  options?: Array<{ label: string, value: string | number }>
  description?: string
  hint?: string
  required?: boolean
}

/** Campos de business/partials/settings_business.blade.php (exc. upload de logo/certificado). */
export const erpSettingsBusinessFields: ErpSettingsField[] = [
  { key: 'cnpj', label: 'CPF/CNPJ', required: true, group: 'Identificação da empresa' },
  { key: 'ie', label: 'Inscrição estadual (IE)', required: true, group: 'Identificação da empresa' },
  { key: 'razao_social', label: 'Razão social', required: true, group: 'Identificação da empresa' },
  { key: 'name', label: 'Nome fantasia', required: true, group: 'Identificação da empresa' },
  { key: 'start_date', label: 'Data de início', group: 'Identificação da empresa' },
  { key: 'default_profit_percent', label: 'Margem de lucro padrão (%)', type: 'number', group: 'Preferências operacionais' },
  { key: 'casas_decimais_valor', label: 'Casas decimais valor produto', type: 'select', group: 'Preferências operacionais' },
  { key: 'currency_id', label: 'Moeda (ID legado)', type: 'number', group: 'Preferências operacionais' },
  { key: 'currency_symbol_placement', label: 'Posição do símbolo', type: 'select', group: 'Preferências operacionais' },
  { key: 'time_zone', label: 'Fuso horário', group: 'Preferências operacionais' },
  { key: 'fy_start_month', label: 'Mês início exercício fiscal', type: 'select', group: 'Preferências operacionais' },
  { key: 'accounting_method', label: 'Método contábil', type: 'select', group: 'Preferências operacionais' },
  { key: 'transaction_edit_days', label: 'Dias para editar transação', type: 'number', group: 'Preferências operacionais' },
  { key: 'date_format', label: 'Formato de data', type: 'select', group: 'Preferências operacionais' },
  { key: 'time_format', label: 'Formato de hora', type: 'select', group: 'Preferências operacionais' },
  { key: 'rua', label: 'Rua', required: true, group: 'Endereço e contato' },
  { key: 'numero', label: 'Número', required: true, group: 'Endereço e contato' },
  { key: 'cidade_id', label: 'Cidade (ID legado)', type: 'number', group: 'Endereço e contato' },
  { key: 'bairro', label: 'Bairro', required: true, group: 'Endereço e contato' },
  { key: 'cep', label: 'CEP', required: true, group: 'Endereço e contato' },
  { key: 'telefone', label: 'Telefone', required: true, group: 'Endereço e contato' },
  { key: 'regime', label: 'Regime tributário', type: 'select', group: 'Configuração fiscal' },
  { key: 'ultimo_numero_nfe', label: 'Último número NFe', type: 'number', group: 'Numeração fiscal' },
  { key: 'ultimo_numero_nfce', label: 'Último número NFCe', type: 'number', group: 'Numeração fiscal' },
  { key: 'ultimo_numero_cte', label: 'Último número CTe', type: 'number', group: 'Numeração fiscal' },
  { key: 'ultimo_numero_mdfe', label: 'Último número MDFe', type: 'number', group: 'Numeração fiscal' },
  { key: 'inscricao_municipal', label: 'Inscrição municipal', group: 'Configuração fiscal' },
  { key: 'numero_serie_nfe', label: 'Série NFe', type: 'number', group: 'Numeração fiscal' },
  { key: 'numero_serie_nfce', label: 'Série NFCe', type: 'number', group: 'Numeração fiscal' },
  { key: 'numero_serie_cte', label: 'Série CTe', group: 'Numeração fiscal' },
  { key: 'numero_serie_mdfe', label: 'Série MDFe', group: 'Numeração fiscal' },
  { key: 'ambiente', label: 'Ambiente fiscal', type: 'select', group: 'Configuração fiscal' },
  { key: 'csc_id', label: 'CSC ID', group: 'Configuração fiscal' },
  { key: 'csc', label: 'CSC', group: 'Configuração fiscal' },
  { key: 'aut_xml', label: 'AUT XML', group: 'Configuração fiscal' }
]
/** Campos de business/partials/settings_tax.blade.php */
export const erpSettingsTaxFields: ErpSettingsField[] = [
  { key: 'tax_label_1', label: 'Nome imposto 1', group: 'Impostos personalizados' },
  { key: 'tax_number_1', label: 'Número imposto 1', group: 'Impostos personalizados' },
  { key: 'tax_label_2', label: 'Nome imposto 2', group: 'Impostos personalizados' },
  { key: 'tax_number_2', label: 'Número imposto 2', group: 'Impostos personalizados' },
  { key: 'enable_inline_tax', label: 'Habilitar imposto inline', type: 'checkbox', group: 'Impostos personalizados' }
]

export const erpSettingsSystemFields: ErpSettingsField[] = [
  { key: 'theme_color', label: 'Cor do tema', group: 'Preferências do sistema' },
  { key: 'enable_tooltip', label: 'Tooltips', type: 'checkbox', group: 'Preferências do sistema' },
  { key: 'exclusao_icms_pis_cofins', label: 'Exclusão ICMS PIS/COFINS', type: 'checkbox', group: 'Preferências do sistema' },
  { key: 'cst_csosn_padrao', label: 'CST/CSOSN padrão', type: 'code', group: 'Classificação fiscal' },
  { key: 'cst_pis_padrao', label: 'CST PIS padrão', type: 'code', group: 'Classificação fiscal' },
  { key: 'cst_cofins_padrao', label: 'CST COFINS padrão', type: 'code', group: 'Classificação fiscal' },
  { key: 'cst_ipi_padrao', label: 'CST IPI padrão', type: 'code', group: 'Classificação fiscal' },
  { key: 'ncm_padrao', label: 'NCM padrão', type: 'code', group: 'Classificação fiscal' },
  { key: 'perc_icms_padrao', label: '% ICMS padrão', type: 'decimal', group: 'Alíquotas padrão' },
  { key: 'perc_pis_padrao', label: '% PIS padrão', type: 'decimal', group: 'Alíquotas padrão' },
  { key: 'perc_cofins_padrao', label: '% COFINS padrão', type: 'decimal', group: 'Alíquotas padrão' },
  { key: 'perc_ipi_padrao', label: '% IPI padrão', type: 'decimal', group: 'Alíquotas padrão' },
  { key: 'pCredSN', label: 'Crédito do Simples Nacional (%)', type: 'decimal', group: 'Alíquotas padrão' }
]
