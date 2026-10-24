# Inspected official CFOP source bytes

These files retain the exact source bytes inspected for [task 1.4](../cfop-reference.md), retrieved 2026-10-23. They are provenance for future task 7.6, not an installed runtime dataset or an approved fiscal classifier.

| Local artifact | Official source and publication | SHA-256 |
| --- | --- | --- |
| [cfop-v210.xlsx](cfop-v210.xlsx) | [Portal NF-e CFOP workbook](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=74KmX8poGpM%3D), `IT_2023_002_v2_10_Tabela_CFOP_Descrição.xlsx`, listed 04/09/2026 | `577e05eec452294945d0e9df1f9bb9b21a4af115938e75ec74cf6a541ae4dacf` |
| [cfop-v210-rows.json](cfop-v210-rows.json) | Bounded extraction of the same workbook, rows 1–620 including header; source values are strings keyed by spreadsheet cell | `208fd4d3c01712cd8a080656203f7503b5668fa7a459e11e1364d098c059c2ee` |
| [it-v210.pdf](it-v210.pdf) | [IT 2023.002 v2.10](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=GyPA%2FcEjrMk%3D), portal 04/09/2026; PDF cover September 2026 | `dd47206ec74040b391cf015ef1f1e55e158c0d3f8b339153667e64f379092b86` |
| [nt-2026-002-v111.pdf](nt-2026-002-v111.pdf) | [NT 2026.002 v1.11](https://www.nfe.fazenda.gov.br/portal/exibirArquivo.aspx?conteudo=m8aACnFLK%2FY%3D), portal 01/10/2026; PDF cover September 2026 | `72c033b2c7490710f61488a14d0bb3d7bb54e5345aa8d33d6ff2c270c228f046` |

Extraction is bounded to the workbook's `CFOP` sheet and its first 620 populated rows. The JSON retains raw A–N source cells (code, validity, indicators, title and explanatory note) as strings, including blank end-validity cells; one extra blank formatted cell `AML68` is immaterial and is ignored for column assertions. No normalization of indicator `2`, dotted display, tenant nature, taxpayer regime or applicability was performed. The 619 data codes are unique, match `[123567][0-9]{3}`, and include `5101`, `5102`, `6102`, `7102`, `5405`, `1102`, `1202`, `2202`, `5353`, `5910`, `5949` and `5933`. `indNFe` has 585 `1` and 34 `0` values; `indComb` has 556 `0`, 16 `1` and 47 `2` values. These counts describe the source only.

The official [portal publication index](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D) and [technical note index](https://www.nfe.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=6WfrpZYE4Ik%3D) establish publication identity. The [task report](../cfop-reference.md) cites MOC and CONFAZ for contextual rules and explicitly records unverified consolidated Annex II and UF exceptions. These archived bytes do not establish the current live status of any later deployment.
