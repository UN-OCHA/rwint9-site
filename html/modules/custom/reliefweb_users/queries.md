# Queries

## What is the total number of jobs posted via the RW interface?

```bash
drush sqlq "SELECT \"Q1:\", COUNT(*) FROM node_field_data WHERE type = \"job\""
drush sqlq "SELECT moderation_status, COUNT(*) FROM node_field_data WHERE type = \"job\" group by moderation_status"
```

## What is the total number of people who can post jobs via the RW interface?

```bash
drush sqlq "SELECT \"Q2:\", COUNT(*) FROM users_field_data WHERE status = 1"
```

## What is the total number of people who are trusted to post jobs directly in the RW interface?

```bash
drush sqlq "SELECT \"Q3:\", COUNT(distinct field_user_posting_rights_id) FROM taxonomy_term__field_user_posting_rights WHERE field_user_posting_rights_job = 3"
```

## In the last 30 days: How many jobs are posted by non-trusted users? (These will not continue to see full form fields.)

```bash
drush sqlq "SELECT \"Q4:\", COUNT(distinct nid) FROM node_field_data n INNER JOIN taxonomy_term__field_user_posting_rights p ON n.uid = p.field_user_posting_rights_id WHERE n.type = \"job\" AND p.field_user_posting_rights_job <> 3 and created > UNIX_TIMESTAMP(DATE_SUB(NOW(), interval 1 month))"
```

## In the last 30 days: How many unique non-trusted users posted jobs?

```bash
drush sqlq "SELECT \"Q5:\", COUNT(distinct uid) FROM node_field_data n INNER JOIN taxonomy_term__field_user_posting_rights p ON n.uid = p.field_user_posting_rights_id WHERE n.type = \"job\" AND p.field_user_posting_rights_job <> 3 and created > UNIX_TIMESTAMP(DATE_SUB(NOW(), interval 1 month))"
```

## In the last 30 days: How many jobs are posted by trusted users? (These will continue to see full form fields.)

```bash
drush sqlq "SELECT \"Q6:\", COUNT(distinct nid) FROM node_field_data n INNER JOIN taxonomy_term__field_user_posting_rights p ON n.uid = p.field_user_posting_rights_id WHERE n.type = \"job\" AND p.field_user_posting_rights_job = 3 and created > UNIX_TIMESTAMP(DATE_SUB(NOW(), interval 1 month))"
```

## In the last 30 days: How many unique trusted users posted jobs?

```bash
drush sqlq "SELECT \"Q7:\", COUNT(DISTINCT uid) FROM node_field_data n INNER JOIN taxonomy_term__field_user_posting_rights p ON n.uid = p.field_user_posting_rights_id WHERE n.type = \"job\" AND p.field_user_posting_rights_job = 3 and created > UNIX_TIMESTAMP(DATE_SUB(NOW(), interval 1 month))"
```

## What are the email addresses for the non-trusted jobs posters from the last two years (request from Rafik to be able to contact them and let them kNOW there will be a change)

```bash
drush sqlq "SELECT distinct u.name, u.mail FROM node_field_data n inner join taxonomy_term__field_user_posting_rights p on n.uid = p.field_user_posting_rights_id inner join users_field_data u on n.uid = u.uid where n.type = \"job\" AND p.field_user_posting_rights_job in (0,2) and n.created > UNIX_TIMESTAMP(DATE_SUB(NOW(), interval 2 year)) ORDER BY u.name"
```
