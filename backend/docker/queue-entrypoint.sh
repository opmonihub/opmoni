#!/bin/sh
# Entrypoint do serviço `queue` (dev e prod).
#
# Não roda o entrypoint do backend de propósito: em dev o vendor/.env/.env-key
# são preparados pelo container `backend` (mesmo bind mount) e em prod vêm da
# imagem; este script só espera o app ficar utilizável e sobe o worker.
set -eu

cd /var/www/html

# Dev: vendor/ mora no volume compartilhado e ainda pode estar sendo instalado.
# Prod: existe desde o build, o laço apenas não itera.
while [ ! -f vendor/autoload.php ]; do
    echo "[queue] vendor/autoload.php ainda não existe — aguardando o container backend."
    sleep 2
done

# Postgres pode subir depois deste container: no compose o depends_on do
# backend cobre, no Swarm não existe depends_on e tudo sobe em paralelo.
# O probe é direto no PDO do framework — `db:show`/`migrate:status` não servem
# aqui (intl ausente na imagem; migrate:status falha também quando o schema
# ainda não existe, que é justamente o caso de quem roda a migration depois).
#
# Conectar não basta: em prod o worker é recriado com a imagem nova ANTES da
# migration manual do deploy, e um job nesse meio-tempo quebra em coluna nova.
# Por isso o probe também exige a tabela `migrations`: ela só existe depois da
# primeira migration, então o worker espera o schema em vez de despachar job
# contra o schema velho. O nome da tabela é o que o MigrationRepository usa
# (Illuminate\Database\Migrations\MigrationRepository, via getTable()).
db_ready() {
    php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    $db = $app->make("db")->connection();
    $db->getPdo();
    $db->table("migrations")->count();
} catch (Throwable $e) {
    exit(1);
}
' >/dev/null 2>&1
}

attempt=0
until db_ready; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "[queue] banco indisponível após ${attempt} tentativas — abortando para o orquestrador reiniciar." >&2
        exit 1
    fi
    echo "[queue] banco ainda indisponível (tentativa ${attempt}) — repetindo em 3s."
    sleep 3
done

# Se vier argumento, ele vale (útil para `queue:listen --queue=notificacoes`).
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# O schedule não tem container próprio: sem ele, nenhuma agenda de
# routes/console.php (`serpro:renew-terms`, `serpro:refresh-powers`,
# `fiscal:capture`, o cão de guarda...) disparava neste ambiente. O
# `schedule:work` roda aqui, irmão do worker, porque é o mecanismo mais
# simples que cobre dev e prod: o script é o mesmo nas duas imagens
# (ver backend/Dockerfile) e o docker-stack.prod.yml reutiliza este
# entrypoint. O `exec` abaixo substitui o shell pelo worker, e o schedule
# fica em segundo plano, preso ao ciclo do container: recicla junto no
# `--max-time`, morre no stop — e, se morrer sozinho, volta no próximo
# reciclo, no máximo uma hora depois. Janela aceitável para agendas
# diárias/horárias; um supervisor próprio seria um mecanismo novo.
# No caminho de argumento (abaixo), o schedule não sobe de propósito:
# ali o container é um worker ad hoc de fila específica, e duas cópias
# do schedule no mesmo volume disparariam as agendas em dobro.
php artisan schedule:work &

# --max-time recicla o worker de hora em hora: memória do processo e código
# novo em prod, sem depender de restart do container.
exec php artisan queue:work --sleep=3 --tries=3 --timeout=120 --max-time=3600
