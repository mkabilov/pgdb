<?php

namespace Ikitiki\DB;

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
    const TIMESTAMPTZ = 'timestamptz';
    const BOOL = 'bool';
    const DATE = 'date';
    const INTERVAL = 'interval';
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
     * Pg type OIDs
     *
     * @var array
     */
    private $oidTypeNames = [];

    /**
     * Constructor
     *
     * @param resource $result
     * @param array $oids
     */
    public function __construct($result, $oids = null)
    {
        $this->result = $result;
        if ($oids) {
            $this->oidTypeNames = $oids;
        }

        $this->rowCount = pg_num_rows($this->result);
        $this->columnCount = pg_num_fields($this->result);
        if ($this->columnCount) {
            for ($i = 0; $i < $this->columnCount; $i++) {
                $fieldName = pg_field_name($this->result, $i);
                $oid = pg_field_type_oid($this->result, $i);

                $this->columnOids[$fieldName] = $oid;
                $this->columnTypes[$fieldName] = isset($this->oidTypeNames[$oid])
                    ? $this->oidTypeNames[$oid]
                    : pg_field_type($this->result, $i);
            }
        }
    }

    /**
     * Convert pg types to php types
     *
     * @param array $row
     *
     * @return array
     */
    private function convertColumns(array $row)
    {
        $result = [];

        foreach ($row as $fieldName => $value) {
            if (is_null($value)) {
                $result[$fieldName] = null;
                continue;
            }
            switch($this->columnTypes[$fieldName]) {
                case self::BOOL:
                    $value = is_null($value) ? null : $value == 't';
                    break;
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
                case self::TIMESTAMPTZ:
                    $value = self::fromTimestamp($value);
                    break;
                case self::SMALLINT_ARRAY:
                case self::INTEGER_ARRAY:
                    $value = self::fromArray($value);
                    if (!is_null($value)) {
                        $value = array_map(function($a) {
                            return $a === 'NULL' ? null : intval($a);
                        }, $value);
                    }
                    break;
                case self::BIGINT_ARRAY:
                case self::NUMERIC_ARRAY:
                    $value = self::fromArray($value);
                    if (!is_null($value)) {
                        $value = array_map(function($a) {
                            return $a === 'NULL' ? null : doubleval($a);
                        }, $value);
                    }
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
                case self::INTERVAL:
                    $value = self::fromInterval($value);
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
     * @throws Exception
     * @return null
     */
    public function fetchField($column)
    {
        if ($this->rowCount == 0) {
            throw new Exception('Empty result set');
        }
        $row = $this->current();
        if (!isset($this->columnOids[$column])) {
            throw new Exception("Field '$column' not found");
        }

        return $row[$column];
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
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
     * @return mixed
     */
    private function fetchRow()
    {
        $row = pg_fetch_array($this->result, null, PGSQL_ASSOC);

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
     * @param string $keyField
     * @param string $valueField
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
     * {@inheritdoc}
     */
    public function key()
    {
        return $this->cursor;
    }

    /**
     * {@inheritdoc}
     */
    public function valid()
    {
        return $this->isValid;
    }

    /**
     * {@inheritdoc}
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
     * {@inheritdoc}
     */
    public function count()
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
    private static function fromArray($arr, &$result = [], $limit = false, $offset = 1)
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
     * @param $string
     *
     * @return array
     */
    private static function fromHStore($string)
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
    private static function fromTimestamp($value)
    {
        $result = false;
        if (empty($value)) {
            return $result;
        }

        $result = new \DateTime($value);

        return $result->getTimestamp();
    }

    /**
     * Convert pg interval to seconds
     *
     * @param string $value
     *
     * @return float|int|null
     */
    private static function fromInterval($value)
    {
        if (is_null($value)) {
            return null;
        }
        $res = preg_match(
            "/(?P<sign>\-)?(?:(?P<years>\d+) years? ?)?(?:(?P<months>\d+) mons? ?)?(?:(?P<days>\d+) days? ?)?" .
            "(?:(?P<h>\d+):(?P<m>\d+):(?P<s>\d+))?(?:\.(?P<ms>\d+))?/",
            $value,
            $match
        );
        if (!$res) {
            throw new Exception('Malformed interval');
        }
        $match += ['years' => 0, 'months' => 0, 'days' => 0, 'h' => 0, 'm' => 0, 's' => 0];

        $res = $match['years'] * 31557600
            + $match['months'] * 2592000
            + $match['days'] * 86400
            + $match['h'] * 3600
            + $match['m'] * 60
            + $match['s'];

        if (!empty($match['ms'])) {
            $res = floatval($res) + floatval($match['ms'] / intval('1' . str_repeat('0', strlen($match['ms']))));
        }

        if (isset($match['sign']) && $match['sign'] == '-') {
            $res *= -1;
        }

        return $res;
    }
}
