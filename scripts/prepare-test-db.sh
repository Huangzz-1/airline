#!/bin/sh
set -eu
# Executed inside a DB container. Password is encoded, safely quoted by MySQL,
# and sent through stdin; it never appears in logs or command arguments.
password_hex="$(printf '%s' "$MYSQL_PASSWORD" | od -An -tx1 | tr -d ' \n')"
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot <<SQL
DROP DATABASE IF EXISTS airline_test;
CREATE DATABASE airline_test;
SET @test_sql = CONCAT("CREATE USER IF NOT EXISTS 'airline_test'@'%' IDENTIFIED BY ", QUOTE(CONVERT(0x$password_hex USING utf8mb4)));
PREPARE test_stmt FROM @test_sql;
EXECUTE test_stmt;
DEALLOCATE PREPARE test_stmt;
GRANT ALL ON airline_test.* TO 'airline_test'@'%';
SQL
