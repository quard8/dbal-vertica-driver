<?php
/*
 * Copyright (c)
 * Kirill chEbba Chebunin <iam@chebba.org>
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.
 */

namespace Che\DBAL\Vertica;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Event\SchemaCreateTableColumnEventArgs;
use Doctrine\DBAL\Event\SchemaCreateTableEventArgs;
use Doctrine\DBAL\Events;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Type;

/**
 * DBAL Platform for Vertica
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @license http://www.opensource.org/licenses/mit-license.php MIT
 */
class VerticaPlatform extends PostgreSqlPlatform
{
    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'vertica';
    }

    public function supportsCreateDropDatabase()
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function supportsCommentOnStatement()
    {
        return true;
    }

    public function getCreateDatabaseSQL($name)
    {
        throw DBALException::notSupported(__METHOD__);
    }

    public function getDropDatabaseSQL($database)
    {
        throw DBALException::notSupported(__METHOD__);
    }

    /**
     * {@inheritDoc}
     */
    public function getListDatabasesSQL()
    {
        return "SELECT name as datname FROM v_catalog.databases";
    }

    public function getListSequencesSQL($database)
    {
        return "SELECT sequence_name, increment_by, minimum from v_catalog.sequences";
    }

    public function getListTablesSQL()
    {
        return "SELECT table_name, table_schema as schema_name FROM v_catalog.tables";
    }

    /**
     * {@inheritDoc}
     */
    public function getListViewsSQL($database)
    {
        return "SELECT table_name as viewname, view_definition as definition FROM v_catalog.views WHERE NOT is_system_view";
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableForeignKeysSQL($table, $database = null)
    {
        return "SELECT constraint_id, constraint_name, column_name, reference_table_name, reference_column_name
                FROM v_catalog.foreign_keys
                WHERE table_name = '$table'";
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableConstraintsSQL($table)
    {
        return "SELECT c.constraint_id, c.column_name, c.constraint_name, c.constraint_type FROM v_catalog.constraint_columns c
                  LEFT JOIN primary_keys p ON p.constraint_id = c.constraint_id AND p.column_name = c.column_name
                WHERE c.constraint_type IN ('u', 'p') AND c.table_name = '$table'
                ORDER BY c.constraint_id, p.ordinal_position, c.column_name";
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableIndexesSQL($table, $currentDatabase = null)
    {
        // There is no indexes in Vertica but doctrine treats unique constraints as indexes
        return $this->getListTableConstraintsSQL($table);
    }

    public function getCreateIndexSQL(Index $index, $table)
    {
        if ($index->isPrimary()) {
            return $this->getCreatePrimaryKeySQL($index, $table);
        }

        if ($index->isSimpleIndex()) {
            throw new DBALException(sprintf(
                'Can not create index "%s" for table "%s": %s does not support common indexes',
                $index->getName(),
                $table,
                $this->getName()
            ));
        }

        return $this->getCreateConstraintSQL($index, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function getListTableColumnsSQL($table, $database = null)
    {
        return "SELECT col.column_name, col.data_type, col.character_maximum_length, col.numeric_precision, col.numeric_scale,
                    col.is_nullable, col.column_default, col.is_identity, con.constraint_type, com.comment
                FROM v_catalog.columns col
                LEFT JOIN v_catalog.constraint_columns con
                    ON con.table_id = col.table_id AND con.column_name = col.column_name AND constraint_type = 'p'
                LEFT JOIN v_catalog.comments com ON com.object_type = 'TABLE' AND com.object_name = col.table_name
                WHERE col.table_name = '$table'";
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateTableSQL(Table $table, $createFlags = self::CREATE_INDEXES)
    {
        $sql = parent::getCreateTableSQL($table, $createFlags);

        $columnComments = array();
        foreach ($table->getColumns() as $column) {
            if ($comment = $this->getColumnComment($column)) {
                $columnComments[$column->getName()] = $comment;
            }
        }
        if (!empty($columnComments)) {
            // Remove empty items from column comments
            $sql = array_filter($sql);
            $sql[] = $this->getCommentOnTableColumnsSQL($table->getName(), $columnComments);
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL($tableName, array $columns, array $options = array())
    {
        $queryFields = $this->getColumnDeclarationListSQL($columns);

        if (isset($options['primary']) && ! empty($options['primary'])) {
            $keyColumns = array_unique(array_values($options['primary']));
            $queryFields .= ', PRIMARY KEY(' . implode(', ', $keyColumns) . ')';
        }

        $query = 'CREATE TABLE ' . $tableName . ' (' . $queryFields . ')';
        $sql = array($query);
        

        if (isset($options['indexes']) && ! empty($options['indexes'])) {
            /** @var Index $index */
            foreach ($options['indexes'] as $index) {
                if ($index->isUnique() && !$index->isPrimary()) {
                    $sql[] = $this->getCreateConstraintSQL($index, $tableName);
                }
            }
        }

        if (isset($options['foreignKeys'])) {
            foreach ((array) $options['foreignKeys'] as $definition) {
                $sql[] = $this->getCreateForeignKeySQL($definition, $tableName);
            }
        }

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    protected function onSchemaAlterTableAddColumn(Column $column, TableDiff $diff, &$columnSql)
    {
        $defaultPrevented = parent::onSchemaAlterTableAddColumn($column, $diff, $columnSql);
        if ($defaultPrevented) {
            return true;
        }

        /** @var Column $column */
        foreach ($diff->addedColumns as $column) {
            $columnData = $column->toArray();
            $notNullWithoutDefault = !empty($columnData['notnull']) && !isset($columnData['default']);
            if ($notNullWithoutDefault) {
                $columnData['notnull'] = false;
            }

            $query = 'ADD ' . $this->getColumnDeclarationSQL($column->getQuotedName($this), $columnData);
            $columnSql[] = 'ALTER TABLE ' . $diff->name . ' ' . $query;
            if ($notNullWithoutDefault) {
                $columnSql[] = 'ALTER TABLE ' . $diff->name . ' ALTER ' . $column->getQuotedName($this) . ' SET NOT NULL';
            }
        }

        return true;
    }

    public function getAlterTableSQL(TableDiff $diff)
    {
        $sql = parent::getAlterTableSQL($diff);

        $columnComments = array();
        /** @var ColumnDiff $columnDiff */
        foreach ($diff->changedColumns as $columnDiff) {
            if ($columnDiff->hasChanged('comment') && $comment = $this->getColumnComment($columnDiff->column)) {
                $columnComments[$columnDiff->column->getName()] = $comment;
            }
        }
        if (!empty($columnComments)) {
            $sql[] = $this->getCommentOnTableColumnsSQL($diff->name, $columnComments);
        }

        return $sql;
    }

    /**
     * SQL for generating table comment with column comments
     *
     * @param array $tableName
     * @param array $columnComments An array of [columnName => columnComment]
     *
     * @return string
     */
    protected function getCommentOnTableColumnsSQL($tableName, array $columnComments)
    {
        return sprintf("COMMENT ON TABLE %s IS '%s'", $tableName, json_encode($columnComments));
    }

    /**
     * {@inheritDoc}
     */
    public function getCommentOnColumnSQL($tableName, $columnName, $comment)
    {
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getTruncateTableSQL($tableName, $cascade = false)
    {
        // Vertica does not support cascade
        return 'TRUNCATE TABLE '.$tableName;
    }

    /**
     * {@inheritDoc}
     */
    public function getReadLockSQL()
    {
        // Vertica supports only exclusive lock (FOR UPDATE)
        return $this->getForUpdateSQL();
    }

    /**
     * {@inheritDoc}
     */
    public function getAdvancedForeignKeyOptionsSQL(ForeignKeyConstraint $foreignKey)
    {
        // Vertica does not support any advanced options
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getDropSequenceSQL($sequence)
    {
        if ($sequence instanceof Sequence) {
            $sequence = $sequence->getQuotedName($this);
        }
        return 'DROP SEQUENCE ' . $sequence;
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $field)
    {
        if (!empty($field['autoincrement'])) {
            return 'AUTO_INCREMENT';
        }

        return 'INT';
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $field)
    {
        // Vertica's int is a big int
        return $this->getIntegerTypeDeclarationSQL($field);
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $field)
    {
        // Vertica has only int
        return $this->getIntegerTypeDeclarationSQL($field);
    }

    /**
     * {@inheritDoc}
     */
    public function getGuidTypeDeclarationSQL(array $field)
    {
        return $this->getVarcharTypeDeclarationSQL($field);
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $field)
    {
        // Vertica has only varchar with 65000 bytes, use it as text
        return 'VARCHAR(65000)';
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $field)
    {
        // BYTEA is a synonym for VARBINARY, but use of VARBINARY is more clear
        return 'VARBINARY';
    }

    /**
     * {@inheritDoc}
     */
    public function getVarcharMaxLength()
    {
        // Vertica's VARCHAR has 65k bytes (not chars), 1 byte for clob and divide by 4 for utf-8 support
        return 65000/4 - 1;
    }

    /**
     * {@inheritDoc}
     */
    protected function initializeDoctrineTypeMappings()
    {
        $this->doctrineTypeMapping = [
            // Vertica has only 64-bit integer, but we will treat al ass integer except bigint
            'bigint'            => 'integer',
            'integer'           => 'integer',
            'int'               => 'integer',
            'int8'              => 'integer',
            'smallint'          => 'integer',
            'tinyint'           => 'integer',

            'boolean'           => 'boolean',

            'varchar'           => 'string',
            'character varying' => 'string',
            'char'              => 'string',
            'character'         => 'string',

            // custom type, Vertica has only varchar, but we will treat bi varchars (4k+) as text
            'text'              => 'text',

            'date'              => 'date',
            'datetime'          => 'datetime',
            'smalldatetime'     => 'datetime',
            'timestamp'         => 'datetime',
            'timestamptz'       => 'datetimetz',
            'time'              => 'time',
            'timetz'            => 'time',

            'float'             => 'float',
            'float8'            => 'float',
            'double precision'  => 'float',
            'real'              => 'float',

            'decimal'           => 'decimal',
            'money'             => 'decimal',
            'numeric'           => 'decimal',
            'number'            => 'decimal',

            'binary'            => 'blob',
            'varbinary'         => 'blob',
            'bytea'             => 'blob',
            'raw'               => 'blob'
        ];
    }
}
