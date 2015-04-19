<?php

namespace DB;

use DB;
/**
 * DB result
 */
class Result implements \Iterator, \Countable
{
    /**
     * Types
     */
    const SMALLINT_ARRAY = '_int2';
    const INTEGER_ARRAY = '_int4';
    const BIGINT_ARRAY = '_int8';
    const NUMERIC_ARRAY = '_numeric';
    const TEXT_ARRAY = '_text';
    const VARCHAR_ARRAY = '_varchar';
    const CHAR_ARRAY = '_char';
    const HSTORE_ARRAY = '_hstore';
    const JSON_ARRAY = '_json';
    const HSTORE = 'hstore';
    const TIMESTAMP = 'timestamp';
    const TIMESTAMPZ = 'timestamptz';
    const DATE = 'date';
    const JSON = 'json';
    const SMALLINT = 'int2';
    const INTEGER = 'int4';
    const BIGINT = 'int8';
    const NUMERIC = 'numeric';
    const TEXT = 'text';


    /**
     * Current row
     *
     * @var array
     */
    private $currentRow = [];

    /**
     * Is current row valid?
     *
     * @var bool
     */
    private $isValid = true;

    /**
     * Position of the current row
     *
     * @var int
     */
    private $cursor = -1;

    /**
     * Number of rows
     *
     * @var int
     */
    private $rowCount;

    /**
     * Number of columns
     *
     * @var int
     */
    private $columnCount;

    /**
     * Column oids
     *
     * @var int[]
     */
    private $columnOids = [];

    /**
     * Query
     *
     * @var resource
     */
    private $result;

    /**
     * Native column types
     *
     * @var array
     */
    private $columnTypes = [];

    /**
     * is iterator initialized
     *
     * @var bool
     */
    private $isInitialized = false;

    /**
     * Constructor
     *
     * @param resource $result
     */
    public function __construct($result)
    {
        $this->result = $result;

        $this->rowCount = pg_num_rows($this->result);
        $this->columnCount = pg_num_fields($this->result);
        if ($this->columnCount) {
            for ($i = 0; $i < $this->columnCount; $i++) {
                $fieldName = pg_field_name($this->result, $i);
                $oid = pg_field_type_oid($this->result, $i);

                $this->columnOids[$fieldName] = $oid;
                $this->columnTypes[$fieldName] = $this->getTypename($oid);
            }
        }
    }

    /**
     * Get name of the type by its oid
     *
     * @param $oid
     *
     * @return mixed
     */
    private function getTypename($oid)
    {
        static $types = [];

        if (!$types) {
            $types = include dirname(__FILE__) . '/Pgoids.php';
        }

        return $types[$oid];
    }

    /**
     * Convert pg types to php types
     *
     * @param $row
     *
     * @return array
     */
    private function convertColumns($row)
    {
        $result = [];

        foreach($row as $fieldName => $value) {
            switch($this->columnTypes[$fieldName]) {
                case self::INTEGER:
                case self::SMALLINT:
                case self::BIGINT:
                    $value = (int) $value;
                    break;
                case self::NUMERIC:
                    $value = (float) $value;
                    break;
                case self::JSON:
                    $value = json_decode($value, true);
                    break;
                case self::HSTORE:
                    $value = self::fromHStore($value);
                    break;
                case self::DATE:
                case self::TIMESTAMP:
                case self::TIMESTAMPZ:
                    $value = self::fromTimestamp($value);
                    break;
                case self::SMALLINT_ARRAY:
                case self::INTEGER_ARRAY:
                case self::BIGINT_ARRAY:
                case self::NUMERIC_ARRAY:
                    $value = array_map('intval', self::fromArray($value));
                    break;
                case self::TEXT_ARRAY:
                case self::VARCHAR_ARRAY:
                case self::CHAR_ARRAY:
                    $value = self::fromArray($value);
                    break;
                case self::HSTORE_ARRAY:
                    $array = self::fromArray($value);
                    $value = [];
                    foreach ($array as $val) {
                        $value[] = self::fromHStore($val);
                    }
                    break;
                case self::JSON_ARRAY:
                    $array = self::fromArray($value);
                    $value = [];
                    foreach ($array as $val) {
                        $value[] = json_decode($val, true);
                    }
                    break;
            }

            $result[$fieldName] = $value;
        }
        return $result;
    }


    /**
     * Get column types
     *
     * @return array
     */
    public function getColumnTypes()
    {
        return $this->columnTypes;
    }

    /**
     * Get field value of the current element
     *
     * @param $column
     *
     * @throws DB\Exception
     * @return null
     */
    public function fetchField($column)
    {
        if ($this->rowCount == 0) {
            throw new Exception('Empty return set');
        }
        $row = $this->current();
        if (!array_key_exists($column, $row)) {
            throw new Exception("Field '$column' not found");
        }

        return $row[$column];
    }

    /**
     * Get current element in the iterator
     *
     * @throws DB\Exception
     * @return mixed
     */
    public function current()
    {
        if (!$this->isInitialized) {
            $this->next();
            $this->isInitialized = true;
        }

        return $this->currentRow;
    }

    /**
     * Shift iterator to the next element
     *
     * @return void
     */
    public function next()
    {
        $this->cursor++;

        $row = $this->fetchRow();
        if (empty($row)) {
            $this->isValid = false;
        } else {
            $this->currentRow = $row;
        }
    }

    /**
     * Get current row
     *
     * @param int $resultType
     *
     * @return mixed
     */
    private function fetchRow($resultType = PGSQL_ASSOC)
    {
        $row = pg_fetch_array($this->result, null, $resultType);

        if (empty($row)) {
            return $row;
        }

        return $this->convertColumns($row);
    }

    /**
     * Get result as an array
     * If $keyField is specified, consider it as a key
     * If $valueField is specified, the resulting array will be keyField => valueField
     *
     * @param null $keyField
     * @param null $valueField
     *
     * @return array
     */
    public function fetchArray($keyField = null, $valueField = null)
    {
        $result = [];
        if ($keyField === null) {
            foreach ($this as $row) {
                $result[] = $row;
            }
        } elseif ($valueField === null) {
            foreach ($this as $row) {
                $key = $row[$keyField];
                unset($row[$keyField]); // no need to store key in values array
                $result[$key] = $row;
            }
        } else {
            foreach ($this as $row) {
                $result[$row[$keyField]] = $row[$valueField];
            }
        }

        return $result;
    }

    /**
     * Get key of the current element in the iterator
     *
     * @return mixed
     */
    public function key()
    {
        return $this->cursor;
    }

    /**
     * is element in the iterator is valid
     *
     * @return boolean
     */
    public function valid()
    {
        return $this->isValid;
    }

    /**
     * Rewind iterator to the beginning
     *
     * @throws Exception
     */
    public function rewind()
    {
        if (!$this->isInitialized) {
            $this->next();
            $this->isInitialized = true;
        } else {
            throw new Exception('Trying to use iterator for the second time');
        }
    }

    /**
     * (PHP 5 &gt;= 5.1.0)<br/>
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     */
    public function count()
    {
        return $this->getRowsCount();
    }

    /**
     * Get number of rows
     *
     * @return int
     */
    public function getRowsCount()
    {
        return $this->rowCount;
    }

    /**
     * Convert pg array to the php one
     * http://php.net/manual/en/ref.pgsql.php#89841
     *
     * @param string $arr initial pg array
     * @param array $result
     * @param bool $limit
     * @param int $offset
     * @return array
     */
    public static function fromArray($arr, &$result = [], $limit = false, $offset = 1)
    {
        if (is_null($arr)) {
            return $arr;
        }
        if (false === $limit) {
            $limit = strlen($arr) - 1;
            $result = [];
        }
        if ('{}' != $arr) {
            do {
                if ('{' != $arr{$offset}) {
                    preg_match("/(\\{?\"([^\"\\\\]|\\\\.)*\"|[^,{}]+)+([,}]+)/", $arr, $match, 0, $offset);
                    $offset += strlen($match[0]);
                    $result[] = ('"' != $match[1]{0} ? $match[1] : stripcslashes(substr($match[1], 1, -1)));
                    if ('},' == $match[3]) {
                        return $offset;
                    }
                } else {
                    $innerResult = [];
                    $offset = self::fromArray($arr, $innerResult, $limit, $offset + 1);

                    $result[] = $innerResult;
                }
            } while ($limit > $offset);
        }

        return $result;
    }

    /**
     * Convert hstore to php array
     *
     * @param string $string
     * @access public
     * @return array
     */
    public static function fromHStore($string)
    {
        $result = [];
        if (empty($string)) {
            return $result;
        }

        preg_match_all(
            '#"((?:\\\"|[^"])*)"=>"((?:\\\"|[^"])*)"#U',
            $string,
            $matches
        );
        foreach ($matches[1] as $id => $val) {
            $result[stripcslashes($val)] = stripcslashes($matches[2][$id]);
        }

        return $result;
    }


    /**
     * Convert db time to unix timestamp
     *
     * @param $value
     *
     * @return bool|int
     */
    public static function fromTimestamp($value)
    {
        $result = false;
        if (empty($value)) {
            return $result;
        }

        $result = new \DateTime($value);

        return $result->getTimestamp();
    }

}
