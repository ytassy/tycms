#!/bin/sh
set -eu

# Les noms sont fixes et distincts de la base et du compte du site.
: "${MARIADB_ROOT_PASSWORD:?Mot de passe root MariaDB absent}"
: "${TYCMS_TEST_DB_PASSWORD:?Mot de passe de test absent}"

# Refuser une configuration du site qui réutiliserait les identités de test.
if [ "${MARIADB_DATABASE:-}" = tycms_test_main ] || [ "${MARIADB_USER:-}" = tycms_test_runner ]; then
    echo "La base et le compte du site doivent être distincts de ceux des tests." >&2
    exit 1
fi

# Doubler les apostrophes ; le mode SQL ci-dessous traite les antislashs littéralement.
test_password=$(printf '%s' "$TYCMS_TEST_DB_PASSWORD" | sed "s/'/''/g")

MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --user=root <<SQL
SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES';
CREATE DATABASE IF NOT EXISTS \`tycms_test_main\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tycms_test_runner'@'%' IDENTIFIED BY '$test_password';
ALTER USER 'tycms_test_runner'@'%' IDENTIFIED BY '$test_password';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'tycms_test_runner'@'%';
GRANT ALL PRIVILEGES ON \`tycms\_test\_main\`.* TO 'tycms_test_runner'@'%';
SQL
