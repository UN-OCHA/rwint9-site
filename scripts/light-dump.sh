#!/usr/bin/env sh

alias docker_exec="docker exec -u appuser rwint9-site"

DB_INFO=$(docker_exec drush sql-connect)
DB_NAME=$(echo "$DB_INFO" | sed -E 's/.*--database=(\S+).*/\1/g')
DB_USER=$(echo "$DB_INFO" | sed -E 's/.*--user=(\S+).*/\1/g')
DB_PASS=$(echo "$DB_INFO" | sed -E 's/.*--password=(\S+).*/\1/g')
DB_HOST=$(echo "$DB_INFO" | sed -E 's/.*--host=(\S+).*/\1/g')
DB_PORT=$(echo "$DB_INFO" | sed -E 's/.*--port=(\S+).*/\1/g')

MYSQLDUMP="mysqldump --user=$DB_USER --password=$DB_PASS --host=$DB_HOST --port=$DB_PORT $DB_NAME"

DUMP_FILE="light-dump.sql"

LIMIT=1000

# Get the most recent reports, training, jobs and all the other type nodes.
NODE_IDS=$(docker_exec drush sql-query "
  (SELECT nid FROM node WHERE type = 'report' ORDER BY nid DESC LIMIT $LIMIT)
  UNION
  (SELECT nid FROM node WHERE type = 'job' ORDER BY nid DESC LIMIT $LIMIT)
  UNION
  (SELECT nid FROM node WHERE type = 'training' ORDER BY nid DESC LIMIT $LIMIT)
  UNION
  (SELECT nid FROM node WHERE type NOT IN ('report', 'job', 'training'))
" | awk '{printf("%s,",$0)} END { printf " " }' | sed -E 's/,+\s*$//')

#--------- NODE TABLES ---------#

# Node tables
TABLE_QUERY="
SELECT table_name
FROM information_schema.tables
WHERE table_schema = '$DB_NAME'
AND table_name IN ('node', 'node_revision', 'node_field_data', 'node_field_revision')
ORDER BY table_name ASC
"

# Get the list of tables.
TABLES=$(docker_exec drush sql-query "$TABLE_QUERY" | awk '{printf("%s ",$0)} END { printf "\n" }')

# Dump the tables.
docker_exec $MYSQLDUMP $TABLES --where="nid IN ($NODE_IDS)" > "$DUMP_FILE"

#--------- NODE FIELD TABLES ---------#

# Node tables
TABLE_QUERY="
SELECT table_name
FROM information_schema.tables
WHERE table_schema = '$DB_NAME'
AND table_name REGEXP 'node(_revision)?__(body|field_.+)'
ORDER BY table_name ASC
"

# Get the list of tables and
TABLES=$(docker_exec drush sql-query "$TABLE_QUERY" | awk '{printf("%s ",$0)} END { printf "\n" }')

# Condition to limit the number of records.
CONDITION="--where=\"entity_id IN ($NODE_IDS)\""

# Dump the tables.
docker_exec $MYSQLDUMP $TABLES --where="entity_id IN ($NODE_IDS)" >> "$DUMP_FILE"

#--------- TERM TABLES ---------#

# Term tables.
TABLE_QUERY="
SELECT table_name
FROM information_schema.tables
WHERE table_schema = '$DB_NAME'
AND table_name LIKE 'taxonomy_term%'
ORDER BY table_name ASC
"

# Get the list of tables.
TABLES=$(docker_exec drush sql-query "$TABLE_QUERY" | awk '{printf("%s ",$0)} END { printf "\n" }')

# Dump the tables.
docker_exec $MYSQLDUMP $TABLES >> "$DUMP_FILE"

#--------- TAXONOMY INDEX TABLE ---------#

# Dump the table.
docker_exec $MYSQLDUMP taxonomy_index --where="nid IN ($NODE_IDS)" >> "$DUMP_FILE"

#--------- PATH ALIAS TABLE ---------#

# Dump the table.
docker_exec $MYSQLDUMP path_alias --where="path NOT LIKE '/node/%' OR SUBSTR(path, 7) IN ($NODE_IDS)" >> "$DUMP_FILE"

#--------- OTHER TABLES ---------#

# Non-node/term tables.
TABLE_QUERY="
SELECT CASE
  WHEN table_name LIKE 'cache%' THEN CONCAT(table_name, ' --ignore-table-data=$DB_NAME.', table_name)
  WHEN table_name IN ('batch', 'queue', 'semaphore', 'sessions', 'watchdog') THEN CONCAT(table_name, ' --ignore-table-data=$DB_NAME.', table_name)
  ELSE table_name
END
FROM information_schema.tables
WHERE table_schema = '$DB_NAME'
AND table_name NOT LIKE 'migrate_%'
AND table_name NOT LIKE 'taxonomy_term%'
AND table_name NOT REGEXP 'node(_revision)?__(body|field_.+)'
AND table_name NOT IN ('node', 'node_revision', 'node_field_data', 'node_field_revision', 'taxonomy_index', 'path_alias', 'reliefweb_migrate_uri_mapping')
ORDER BY table_name ASC
"

# Get the list of tables and additional parameters to ignore data.
TABLES=$(docker_exec drush sql-query "$TABLE_QUERY" | awk '{printf("%s ",$0)} END { printf "\n" }')

# Dump the tables.
docker_exec $MYSQLDUMP $TABLES >> "$DUMP_FILE"
