#!/bin/sh
#
# Copia la base local 'cordes' entera a la base de Railway.
#
#   scripts/subir-base-a-railway.sh 'mysql://root:CLAVE@xxx.proxy.rlwy.net:12345/railway'
#
# La URL sale de Railway: servicio MySQL > Variables > MYSQL_PUBLIC_URL.
# (La privada, MYSQL_URL, solo funciona adentro de Railway.)
#
# Pisa lo que haya del otro lado: cada tabla se borra y se vuelve a crear.
# Se puede correr las veces que haga falta.
#
set -eu

URL="${1:-}"

if [ -z "$URL" ]; then
    echo "Falta la URL de Railway."
    echo "  uso: $0 'mysql://root:CLAVE@host.proxy.rlwy.net:PUERTO/railway'"
    echo "  sale de: Railway > servicio MySQL > Variables > MYSQL_PUBLIC_URL"
    exit 1
fi

LOCAL_DB="${LOCAL_DB:-cordes}"

# La clave va a un archivo temporal y no a la linea de comandos: asi no queda
# en el historial del shell ni a la vista de un `ps`.
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT INT TERM

printf '%s' "$URL" | python3 -c '
import sys, urllib.parse as u
p = u.urlparse(sys.stdin.read().strip())
if not p.hostname:
    sys.exit("La URL no se entiende. Tiene que ser mysql://usuario:clave@host:puerto/base")
print("[client]")
print("protocol=TCP")
print("host=%s"     % p.hostname)
print("port=%s"     % (p.port or 3306))
print("user=%s"     % u.unquote(p.username or "root"))
print("password=%s" % u.unquote(p.password or ""))
print("# base=%s"   % (p.path.lstrip("/") or "railway"))
' > "$CNF"

REMOTE_DB="$(sed -n 's/^# base=//p' "$CNF")"
REMOTE_HOST="$(sed -n 's/^host=//p' "$CNF")"

echo "Copiando  ${LOCAL_DB} (local)  ->  ${REMOTE_DB} en ${REMOTE_HOST}"
echo

mysqldump \
    -h 127.0.0.1 -P 3306 -u root \
    --single-transaction --no-tablespaces --set-gtid-purged=OFF \
    --default-character-set=utf8mb4 --add-drop-table --routines --events \
    "$LOCAL_DB" \
| mysql --defaults-file="$CNF" "$REMOTE_DB"

echo "Listo. Lo que quedo del otro lado:"
mysql --defaults-file="$CNF" "$REMOTE_DB" -e "
SELECT 'empresas' AS tabla, COUNT(*) AS filas FROM empresas
UNION ALL SELECT 'consultas',       COUNT(*) FROM consultas
UNION ALL SELECT 'consulta_lineas', COUNT(*) FROM consulta_lineas
UNION ALL SELECT 'materiales',      COUNT(*) FROM materiales
UNION ALL SELECT 'formas',          COUNT(*) FROM formas
UNION ALL SELECT 'usuarios',        COUNT(*) FROM users;"
