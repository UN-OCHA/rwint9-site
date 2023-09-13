<?php

namespace Drupal\reliefweb_moderation\Database\Query;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Select query with index hints.
 *
 * Only work with MySQL (which doesn't override the default Select Query
 * implementation.)
 *
 * Note: we cannot use a SelectQueryExtender because it would not have
 * access to all the properties of its SelectQuery to build the
 * string version of the query (__toString).
 */
class HintedSelect extends Select {

  /**
   * List of index hints.
   *
   * @var array
   */
  protected $hints = [];

  /**
   * {@inheritdoc}
   */
  public function __construct($table, $alias, Connection $connection, $options = []) {
    parent::__construct($table, $alias, $connection, $options);

    // Add convenience tag to mark that this is an extended query. We have to
    // do this in the constructor to ensure that it is set before preExecute()
    // gets called.
    $this->addTag('indexhints');
  }

  /**
   * Add an index hint for the given table alias.
   *
   * @param string $alias
   *   Table alias.
   * @param string $type
   *   One of FORCE, USE or IGNORE.
   * @param string $index
   *   Index name.
   *
   * @return Drupal\reliefweb_moderation\Database\Query\HintedSelect
   *   Return this select query.
   */
  public function addIndexHint($alias, $type, $index) {
    if (in_array($type, ['FORCE', 'USE', 'IGNORE'])) {
      $this->hints[$alias][$type][$index] = TRUE;
    }
    return $this;
  }

  /**
   * Create the string representation of the query to pass to DB::execute().
   *
   * Same things as the default Select Query with the index hints.
   *
   * @see Drupal\Core\Database\Query\Select::__toString()
   */
  public function __toString() {
    // For convenience, we compile the query ourselves if the caller forgot
    // to do it. This allows constructs like "(string) $query" to work. When
    // the query will be executed, it will be recompiled using the proper
    // placeholder generator anyway.
    if (!$this->compiled()) {
      $this->compile($this->connection, $this);
    }

    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);

    // SELECT.
    $query = $comments . 'SELECT ';
    if ($this->distinct) {
      $query .= 'DISTINCT ';
    }

    // FIELDS and EXPRESSIONS.
    $fields = [];
    foreach ($this->tables as $alias => $table) {
      if (!empty($table['all_fields'])) {
        $fields[] = $this->connection->escapeAlias($alias) . '.*';
      }
    }
    foreach ($this->fields as $alias => $field) {
      // Note that $field['table'] holds the table alias.
      // @see \Drupal\Core\Database\Query\Select::addField
      $table = isset($field['table']) ? $this->connection
        ->escapeAlias($field['table']) . '.' : '';

      // Always use the AS keyword for field aliases, as some
      // databases require it (e.g., PostgreSQL).
      $fields[] = $table . $this->connection
        ->escapeField($field['field']) . ' AS ' . $this->connection
        ->escapeAlias($field['alias']);
    }
    foreach ($this->expressions as $expression) {
      $fields[] = $expression['expression'] . ' AS ' . $this->connection
        ->escapeAlias($expression['alias']);
    }
    $query .= implode(', ', $fields);

    // FROM - We presume all queries have a FROM, as any query that doesn't
    // won't need the query builder anyway.
    $query .= "\nFROM ";
    foreach ($this->tables as $alias => $table) {
      $query .= "\n";
      if (isset($table['join type'])) {
        $query .= $table['join type'] . ' JOIN ';
      }

      // If the table is a subquery, compile it and integrate it into
      // this query.
      if ($table['table'] instanceof SelectInterface) {
        // Run preparation steps on this sub-query before converting to string.
        $subquery = $table['table'];
        $subquery->preExecute();
        $table_string = '(' . (string) $subquery . ')';
      }
      else {
        $table_string = $this->connection
          ->escapeTable($table['table']);

        // Do not attempt prefixing cross database / schema queries.
        if (strpos($table_string, '.') === FALSE) {
          $table_string = '{' . $table_string . '}';
        }
      }

      // Don't use the AS keyword for table aliases, as some
      // databases don't support it (e.g., Oracle).
      $query .= $table_string . ' ' . $this->connection
        ->escapeTable($table['alias']);

      // Add the index hints.
      if (!empty($this->hints[$alias])) {
        foreach ($this->hints[$alias] as $type => $indices) {
          if (!empty($indices)) {
            $query .= ' ' . $type . ' INDEX (' . implode(', ', array_keys($indices)) . ')';
          }
        }
      }

      if (!empty($table['condition'])) {
        $query .= ' ON ' . (string) $table['condition'];
      }
    }

    // WHERE.
    if (count($this->condition)) {
      // There is an implicit string cast on $this->condition.
      $query .= "\nWHERE " . $this->condition;
    }

    // GROUP BY.
    if ($this->group) {
      $query .= "\nGROUP BY " . implode(', ', $this->group);
    }

    // HAVING.
    if (count($this->having)) {
      // There is an implicit string cast on $this->having.
      $query .= "\nHAVING " . $this->having;
    }

    // UNION is a little odd, as the select queries to combine are passed into
    // this query, but syntactically they all end up on the same level.
    if ($this->union) {
      foreach ($this->union as $union) {
        $query .= ' ' . $union['type'] . ' ' . (string) $union['query'];
      }
    }

    // ORDER BY.
    if ($this->order) {
      $query .= "\nORDER BY ";
      $fields = [];
      foreach ($this->order as $field => $direction) {
        $fields[] = $this->connection
          ->escapeField($field) . ' ' . $direction;
      }
      $query .= implode(', ', $fields);
    }

    // RANGE.
    // There is no universal SQL standard for handling range or limit clauses.
    // Fortunately, all core-supported databases use the same range syntax.
    // Databases that need a different syntax can override this method and
    // do whatever alternate logic they need to.
    if (!empty($this->range)) {
      $query .= "\nLIMIT " . (int) $this->range['length'] .
        " OFFSET " . (int) $this->range['start'];
    }

    if ($this->forUpdate) {
      $query .= ' FOR UPDATE';
    }

    return $query;
  }

}
