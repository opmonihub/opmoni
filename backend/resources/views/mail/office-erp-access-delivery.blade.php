@if (str_contains(mb_strtolower($subjectLine), 'convite'))
Você recebeu um convite para acessar uma empresa no Opmoni.
@else
Foi solicitada uma recuperação de acesso à sua conta Opmoni.
@endif

Acesse o link abaixo para continuar:
{!! $url !!}

Se você não solicitou esta ação, ignore esta mensagem.
