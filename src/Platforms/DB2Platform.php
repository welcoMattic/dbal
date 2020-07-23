<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Platforms;

use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

use function array_merge;
use function count;
use function current;
use function explode;
use function implode;
use function sprintf;
use function strpos;
use function strtoupper;

class DB2Platform extends AbstractPlatform
{
    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        // todo blob(n) with $column['length'];
        return 'BLOB(1M)';
    }

    public function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'bigint'    => 'bigint',
            'binary'    => 'binary',
            'blob'      => 'blob',
            'character' => 'string',
            'clob'      => 'text',
            'date'      => 'date',
            'decimal'   => 'decimal',
            'double'    => 'float',
            'integer'   => 'integer',
            'real'      => 'float',
            'smallint'  => 'smallint',
            'time'      => 'time',
            'timestamp' => 'datetime',
            'varbinary' => 'binary',
            'varchar'   => 'string',
        ];
    }

    public function isCommentedDoctrineType(Type $doctrineType): bool
    {
        if ($doctrineType->getName() === Types::BOOLEAN) {
            // We require a commented boolean type in order to distinguish between boolean and smallint
            // as both (have to) map to the same native type.
            return true;
        }

        return parent::isCommentedDoctrineType($doctrineType);
    }

    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return $this->getCharTypeDeclarationSQLSnippet($length) . ' FOR BIT DATA';
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return $this->getVarcharTypeDeclarationSQLSnippet($length) . ' FOR BIT DATA';
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        // todo clob(n) with $column['length'];
        return 'CLOB(1M)';
    }

    public function getName(): string
    {
        return 'db2';
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return 'INTEGER' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return 'BIGINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return 'SMALLINT' . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        $autoinc = '';
        if (! empty($column['autoincrement'])) {
            $autoinc = ' GENERATED BY DEFAULT AS IDENTITY';
        }

        return $autoinc;
    }

    public function getBitAndComparisonExpression(string $value1, string $value2): string
    {
        return 'BITAND(' . $value1 . ', ' . $value2 . ')';
    }

    public function getBitOrComparisonExpression(string $value1, string $value2): string
    {
        return 'BITOR(' . $value1 . ', ' . $value2 . ')';
    }

    protected function getDateArithmeticIntervalExpression(string $date, string $operator, string $interval, string $unit): string
    {
        switch ($unit) {
            case DateIntervalUnit::WEEK:
                $interval = $this->multiplyInterval($interval, 7);
                $unit     = DateIntervalUnit::DAY;
                break;

            case DateIntervalUnit::QUARTER:
                $interval = $this->multiplyInterval($interval, 3);
                $unit     = DateIntervalUnit::MONTH;
                break;
        }

        return $date . ' ' . $operator . ' ' . $interval . ' ' . $unit;
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return 'DAYS(' . $date1 . ') - DAYS(' . $date2 . ')';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        if (isset($column['version']) && $column['version'] === true) {
            return 'TIMESTAMP(0) WITH DEFAULT';
        }

        return 'TIMESTAMP(0)';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    public function getTruncateTableSQL(string $tableName, bool $cascade = false): string
    {
        $tableIdentifier = new Identifier($tableName);

        return 'TRUNCATE ' . $tableIdentifier->getQuotedName($this) . ' IMMEDIATE';
    }

    /**
     * This code fragment is originally from the Zend_Db_Adapter_Db2 class, but has been edited.
     */
    public function getListTableColumnsSQL(string $table, ?string $database = null): string
    {
        $table = $this->quoteStringLiteral($table);

        // We do the funky subquery and join syscat.columns.default this crazy way because
        // as of db2 v10, the column is CLOB(64k) and the distinct operator won't allow a CLOB,
        // it wants shorter stuff like a varchar.
        return "
        SELECT
          cols.default,
          subq.*
        FROM (
               SELECT DISTINCT
                 c.tabschema,
                 c.tabname,
                 c.colname,
                 c.colno,
                 c.typename,
                 c.nulls,
                 c.length,
                 c.scale,
                 c.identity,
                 tc.type AS tabconsttype,
                 c.remarks AS comment,
                 k.colseq,
                 CASE
                 WHEN c.generated = 'D' THEN 1
                 ELSE 0
                 END     AS autoincrement
               FROM syscat.columns c
                 LEFT JOIN (syscat.keycoluse k JOIN syscat.tabconst tc
                     ON (k.tabschema = tc.tabschema
                         AND k.tabname = tc.tabname
                         AND tc.type = 'P'))
                   ON (c.tabschema = k.tabschema
                       AND c.tabname = k.tabname
                       AND c.colname = k.colname)
               WHERE UPPER(c.tabname) = UPPER(" . $table . ')
               ORDER BY c.colno
             ) subq
          JOIN syscat.columns cols
            ON subq.tabschema = cols.tabschema
               AND subq.tabname = cols.tabname
               AND subq.colno = cols.colno
        ORDER BY subq.colno
        ';
    }

    public function getListTablesSQL(): string
    {
        return "SELECT NAME FROM SYSIBM.SYSTABLES WHERE TYPE = 'T'";
    }

    public function getListViewsSQL(string $database): string
    {
        return 'SELECT NAME, TEXT FROM SYSIBM.SYSVIEWS';
    }

    public function getListTableIndexesSQL(string $table, ?string $database = null): string
    {
        $table = $this->quoteStringLiteral($table);

        return "SELECT   idx.INDNAME AS key_name,
                         idxcol.COLNAME AS column_name,
                         CASE
                             WHEN idx.UNIQUERULE = 'P' THEN 1
                             ELSE 0
                         END AS primary,
                         CASE
                             WHEN idx.UNIQUERULE = 'D' THEN 1
                             ELSE 0
                         END AS non_unique
                FROM     SYSCAT.INDEXES AS idx
                JOIN     SYSCAT.INDEXCOLUSE AS idxcol
                ON       idx.INDSCHEMA = idxcol.INDSCHEMA AND idx.INDNAME = idxcol.INDNAME
                WHERE    idx.TABNAME = UPPER(" . $table . ')
                ORDER BY idxcol.COLSEQ ASC';
    }

    public function getListTableForeignKeysSQL(string $table, ?string $database = null): string
    {
        $table = $this->quoteStringLiteral($table);

        return "SELECT   fkcol.COLNAME AS local_column,
                         fk.REFTABNAME AS foreign_table,
                         pkcol.COLNAME AS foreign_column,
                         fk.CONSTNAME AS index_name,
                         CASE
                             WHEN fk.UPDATERULE = 'R' THEN 'RESTRICT'
                             ELSE NULL
                         END AS on_update,
                         CASE
                             WHEN fk.DELETERULE = 'C' THEN 'CASCADE'
                             WHEN fk.DELETERULE = 'N' THEN 'SET NULL'
                             WHEN fk.DELETERULE = 'R' THEN 'RESTRICT'
                             ELSE NULL
                         END AS on_delete
                FROM     SYSCAT.REFERENCES AS fk
                JOIN     SYSCAT.KEYCOLUSE AS fkcol
                ON       fk.CONSTNAME = fkcol.CONSTNAME
                AND      fk.TABSCHEMA = fkcol.TABSCHEMA
                AND      fk.TABNAME = fkcol.TABNAME
                JOIN     SYSCAT.KEYCOLUSE AS pkcol
                ON       fk.REFKEYNAME = pkcol.CONSTNAME
                AND      fk.REFTABSCHEMA = pkcol.TABSCHEMA
                AND      fk.REFTABNAME = pkcol.TABNAME
                WHERE    fk.TABNAME = UPPER(" . $table . ')
                ORDER BY fkcol.COLSEQ ASC';
    }

    public function getCreateViewSQL(string $name, string $sql): string
    {
        return 'CREATE VIEW ' . $name . ' AS ' . $sql;
    }

    public function getDropViewSQL(string $name): string
    {
        return 'DROP VIEW ' . $name;
    }

    public function getCreateDatabaseSQL(string $database): string
    {
        return 'CREATE DATABASE ' . $database;
    }

    public function getDropDatabaseSQL(string $database): string
    {
        return 'DROP DATABASE ' . $database;
    }

    public function supportsCreateDropDatabase(): bool
    {
        return false;
    }

    public function supportsReleaseSavepoints(): bool
    {
        return false;
    }

    public function supportsCommentOnStatement(): bool
    {
        return true;
    }

    public function getCurrentDateSQL(): string
    {
        return 'CURRENT DATE';
    }

    public function getCurrentTimeSQL(): string
    {
        return 'CURRENT TIME';
    }

    public function getCurrentTimestampSQL(): string
    {
        return 'CURRENT TIMESTAMP';
    }

    public function getIndexDeclarationSQL(string $name, Index $index): string
    {
        // Index declaration in statements like CREATE TABLE is not supported.
        throw NotSupported::new(__METHOD__);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(string $tableName, array $columns, array $options = []): array
    {
        $indexes = [];
        if (isset($options['indexes'])) {
            $indexes = $options['indexes'];
        }

        $options['indexes'] = [];

        $sqls = parent::_getCreateTableSQL($tableName, $columns, $options);

        foreach ($indexes as $definition) {
            $sqls[] = $this->getCreateIndexSQL($definition, $tableName);
        }

        return $sqls;
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql         = [];
        $columnSql   = [];
        $commentsSQL = [];

        $queryParts = [];
        foreach ($diff->addedColumns as $column) {
            if ($this->onSchemaAlterTableAddColumn($column, $diff, $columnSql)) {
                continue;
            }

            $columnDef = $column->toArray();
            $queryPart = 'ADD COLUMN ' . $this->getColumnDeclarationSQL($column->getQuotedName($this), $columnDef);

            // Adding non-nullable columns to a table requires a default value to be specified.
            if (
                ! empty($columnDef['notnull']) &&
                ! isset($columnDef['default']) &&
                empty($columnDef['autoincrement'])
            ) {
                $queryPart .= ' WITH DEFAULT';
            }

            $queryParts[] = $queryPart;

            $comment = $this->getColumnComment($column);

            if ($comment === '') {
                continue;
            }

            $commentsSQL[] = $this->getCommentOnColumnSQL(
                $diff->getName($this)->getQuotedName($this),
                $column->getQuotedName($this),
                $comment
            );
        }

        foreach ($diff->removedColumns as $column) {
            if ($this->onSchemaAlterTableRemoveColumn($column, $diff, $columnSql)) {
                continue;
            }

            $queryParts[] =  'DROP COLUMN ' . $column->getQuotedName($this);
        }

        foreach ($diff->changedColumns as $columnDiff) {
            if ($this->onSchemaAlterTableChangeColumn($columnDiff, $diff, $columnSql)) {
                continue;
            }

            if ($columnDiff->hasChanged('comment')) {
                $commentsSQL[] = $this->getCommentOnColumnSQL(
                    $diff->getName($this)->getQuotedName($this),
                    $columnDiff->column->getQuotedName($this),
                    $this->getColumnComment($columnDiff->column)
                );

                if (count($columnDiff->changedProperties) === 1) {
                    continue;
                }
            }

            $this->gatherAlterColumnSQL($diff->getName($this), $columnDiff, $sql, $queryParts);
        }

        foreach ($diff->renamedColumns as $oldColumnName => $column) {
            if ($this->onSchemaAlterTableRenameColumn($oldColumnName, $column, $diff, $columnSql)) {
                continue;
            }

            $oldColumnName = new Identifier($oldColumnName);

            $queryParts[] =  'RENAME COLUMN ' . $oldColumnName->getQuotedName($this) .
                ' TO ' . $column->getQuotedName($this);
        }

        $tableSql = [];

        if (! $this->onSchemaAlterTable($diff, $tableSql)) {
            if (count($queryParts) > 0) {
                $sql[] = 'ALTER TABLE ' . $diff->getName($this)->getQuotedName($this) . ' ' . implode(' ', $queryParts);
            }

            // Some table alteration operations require a table reorganization.
            if (! empty($diff->removedColumns) || ! empty($diff->changedColumns)) {
                $sql[] = "CALL SYSPROC.ADMIN_CMD ('REORG TABLE " . $diff->getName($this)->getQuotedName($this) . "')";
            }

            $sql = array_merge($sql, $commentsSQL);

            $newName = $diff->getNewName();

            if ($newName !== null) {
                $sql[] = sprintf(
                    'RENAME TABLE %s TO %s',
                    $diff->getName($this)->getQuotedName($this),
                    $newName->getQuotedName($this)
                );
            }

            $sql = array_merge(
                $this->getPreAlterTableIndexForeignKeySQL($diff),
                $sql,
                $this->getPostAlterTableIndexForeignKeySQL($diff)
            );
        }

        return array_merge($sql, $tableSql, $columnSql);
    }

    /**
     * Gathers the table alteration SQL for a given column diff.
     *
     * @param Identifier $table      The table to gather the SQL for.
     * @param ColumnDiff $columnDiff The column diff to evaluate.
     * @param string[]   $sql        The sequence of table alteration statements to fill.
     * @param mixed[]    $queryParts The sequence of column alteration clauses to fill.
     */
    private function gatherAlterColumnSQL(Identifier $table, ColumnDiff $columnDiff, array &$sql, array &$queryParts): void
    {
        $alterColumnClauses = $this->getAlterColumnClausesSQL($columnDiff);

        if (empty($alterColumnClauses)) {
            return;
        }

        // If we have a single column alteration, we can append the clause to the main query.
        if (count($alterColumnClauses) === 1) {
            $queryParts[] = current($alterColumnClauses);

            return;
        }

        // We have multiple alterations for the same column,
        // so we need to trigger a complete ALTER TABLE statement
        // for each ALTER COLUMN clause.
        foreach ($alterColumnClauses as $alterColumnClause) {
            $sql[] = 'ALTER TABLE ' . $table->getQuotedName($this) . ' ' . $alterColumnClause;
        }
    }

    /**
     * Returns the ALTER COLUMN SQL clauses for altering a column described by the given column diff.
     *
     * @param ColumnDiff $columnDiff The column diff to evaluate.
     *
     * @return string[]
     */
    private function getAlterColumnClausesSQL(ColumnDiff $columnDiff): array
    {
        $column = $columnDiff->column->toArray();

        $alterClause = 'ALTER COLUMN ' . $columnDiff->column->getQuotedName($this);

        if ($column['columnDefinition'] !== null) {
            return [$alterClause . ' ' . $column['columnDefinition']];
        }

        $clauses = [];

        if (
            $columnDiff->hasChanged('type') ||
            $columnDiff->hasChanged('length') ||
            $columnDiff->hasChanged('precision') ||
            $columnDiff->hasChanged('scale') ||
            $columnDiff->hasChanged('fixed')
        ) {
            $clauses[] = $alterClause . ' SET DATA TYPE ' . $column['type']->getSQLDeclaration($column, $this);
        }

        if ($columnDiff->hasChanged('notnull')) {
            $clauses[] = $column['notnull'] ? $alterClause . ' SET NOT NULL' : $alterClause . ' DROP NOT NULL';
        }

        if ($columnDiff->hasChanged('default')) {
            if (isset($column['default'])) {
                $defaultClause = $this->getDefaultValueDeclarationSQL($column);

                if ($defaultClause !== '') {
                    $clauses[] = $alterClause . ' SET' . $defaultClause;
                }
            } else {
                $clauses[] = $alterClause . ' DROP DEFAULT';
            }
        }

        return $clauses;
    }

    /**
     * {@inheritDoc}
     */
    protected function getPreAlterTableIndexForeignKeySQL(TableDiff $diff): array
    {
        $sql   = [];
        $table = $diff->getName($this)->getQuotedName($this);

        foreach ($diff->removedIndexes as $remKey => $remIndex) {
            foreach ($diff->addedIndexes as $addKey => $addIndex) {
                if ($remIndex->getColumns() !== $addIndex->getColumns()) {
                    continue;
                }

                if ($remIndex->isPrimary()) {
                    $sql[] = 'ALTER TABLE ' . $table . ' DROP PRIMARY KEY';
                } elseif ($remIndex->isUnique()) {
                    $sql[] = 'ALTER TABLE ' . $table . ' DROP UNIQUE ' . $remIndex->getQuotedName($this);
                } else {
                    $sql[] = $this->getDropIndexSQL($remIndex, $table);
                }

                $sql[] = $this->getCreateIndexSQL($addIndex, $table);

                unset($diff->removedIndexes[$remKey], $diff->addedIndexes[$addKey]);

                break;
            }
        }

        $sql = array_merge($sql, parent::getPreAlterTableIndexForeignKeySQL($diff));

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenameIndexSQL(string $oldIndexName, Index $index, string $tableName): array
    {
        if (strpos($tableName, '.') !== false) {
            [$schema]     = explode('.', $tableName);
            $oldIndexName = $schema . '.' . $oldIndexName;
        }

        return ['RENAME INDEX ' . $oldIndexName . ' TO ' . $index->getQuotedName($this)];
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultValueDeclarationSQL(array $column): string
    {
        if (! empty($column['autoincrement'])) {
            return '';
        }

        if (! empty($column['version'])) {
            if ((string) $column['type'] !== 'DateTime') {
                $column['default'] = '1';
            }
        }

        return parent::getDefaultValueDeclarationSQL($column);
    }

    public function getEmptyIdentityInsertSQL(string $tableName, string $identifierColumnName): string
    {
        return 'INSERT INTO ' . $tableName . ' (' . $identifierColumnName . ') VALUES (DEFAULT)';
    }

    public function getCreateTemporaryTableSnippetSQL(): string
    {
        return 'DECLARE GLOBAL TEMPORARY TABLE';
    }

    public function getTemporaryTableName(string $tableName): string
    {
        return 'SESSION.' . $tableName;
    }

    protected function doModifyLimitQuery(string $query, ?int $limit, int $offset): string
    {
        $where = [];

        if ($offset > 0) {
            $where[] = sprintf('db22.DC_ROWNUM >= %d', $offset + 1);
        }

        if ($limit !== null) {
            $where[] = sprintf('db22.DC_ROWNUM <= %d', $offset + $limit);
        }

        if (empty($where)) {
            return $query;
        }

        // Todo OVER() needs ORDER BY data!
        return sprintf(
            'SELECT db22.* FROM (SELECT db21.*, ROW_NUMBER() OVER() AS DC_ROWNUM FROM (%s) db21) db22 WHERE %s',
            $query,
            implode(' AND ', $where)
        );
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if ($start === null) {
            return sprintf('LOCATE(%s, %s)', $substring, $string);
        }

        return sprintf('LOCATE(%s, %s, %s)', $substring, $string, $start);
    }

    public function getSubstringExpression(string $string, string $start, ?string $length = null): string
    {
        if ($length === null) {
            return sprintf('SUBSTR(%s, %s)', $string, $start);
        }

        return sprintf('SUBSTR(%s, %s, %s)', $string, $start, $length);
    }

    public function getCurrentDatabaseExpression(): string
    {
        return 'CURRENT_USER';
    }

    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    public function prefersIdentityColumns(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     *
     * DB2 returns all column names in SQL result sets in uppercase.
     */
    public function getSQLResultCasing(string $column): string
    {
        return strtoupper($column);
    }

    public function getForUpdateSQL(): string
    {
        return ' WITH RR USE AND KEEP UPDATE LOCKS';
    }

    public function getDummySelectSQL(string $expression = '1'): string
    {
        return sprintf('SELECT %s FROM sysibm.sysdummy1', $expression);
    }

    /**
     * {@inheritDoc}
     *
     * DB2 supports savepoints, but they work semantically different than on other vendor platforms.
     *
     * TODO: We have to investigate how to get DB2 up and running with savepoints.
     */
    public function supportsSavepoints(): bool
    {
        return false;
    }

    protected function getReservedKeywordsClass(): string
    {
        return Keywords\DB2Keywords::class;
    }

    public function getListTableCommentsSQL(string $table): string
    {
        return sprintf(
            <<<'SQL'
SELECT REMARKS
  FROM SYSIBM.SYSTABLES
  WHERE NAME = UPPER( %s )
SQL
            ,
            $this->quoteStringLiteral($table)
        );
    }
}
