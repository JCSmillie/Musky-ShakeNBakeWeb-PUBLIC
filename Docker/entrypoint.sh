#!/usr/bin/env bash
set -Eeuo pipefail

BASE_DIR="/opt/musky"
RUNTIME_DIR="/var/lib/musky"

mkdir -p "${RUNTIME_DIR}" "${BASE_DIR}/logs" "${RUNTIME_DIR}/Templates" "${RUNTIME_DIR}/MOSBasic-Drop" "${RUNTIME_DIR}/GoogleAuths" "${RUNTIME_DIR}/GoogleCreds"
chown -R www-data:www-data "${BASE_DIR}/logs" "${RUNTIME_DIR}" || true

php "${BASE_DIR}/Docker/write-runtime-config.php"

if [ -f "${BASE_DIR}/Docker/web-root.htaccess" ]; then
  cp "${BASE_DIR}/Docker/web-root.htaccess" "${BASE_DIR}/web/.htaccess"
fi

wait_for_db() {
  local attempts="${MUSKY_DB_WAIT_ATTEMPTS:-90}"
  local sleep_seconds="${MUSKY_DB_WAIT_SLEEP_SECONDS:-2}"
  local try=1

  while [ "${try}" -le "${attempts}" ]; do
    if php -r '
      $dsn = sprintf(
        "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
        getenv("MUSKY_DB_HOST") ?: "host.docker.internal",
        getenv("MUSKY_DB_PORT") ?: "7306",
        getenv("MUSKY_DB_NAME") ?: "nora"
      );
      try {
        new PDO($dsn, getenv("MUSKY_DB_USER") ?: "nora", getenv("MUSKY_DB_PASS") ?: "nora_password", [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        exit(0);
      } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
      }
    '; then
      echo "[OK] Musky reached Nora MariaDB."
      return 0
    fi

    echo "[..] Waiting for Nora MariaDB (${try}/${attempts}) ..."
    sleep "${sleep_seconds}"
    try=$((try + 1))
  done

  echo "[FAIL] Musky could not reach Nora MariaDB."
  return 1
}

wait_for_db

exec "$@"
