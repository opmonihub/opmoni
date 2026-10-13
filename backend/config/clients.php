<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Planilha da carteira
    |--------------------------------------------------------------------------
    |
    | Até este total a listagem compacta vai inteira para o frontend.
    | Acima dele a mesma consulta pagina, e a seleção global continua
    | no snapshot do filtro — não no pedaço carregado.
    |
    */

    'sheet_limit' => 10000,

    'sheet_page_size' => 200,

];
