<?php

namespace ikitiki\DB;

/**
 * Database
 */
class DB
{
    private $host;

    private $port = 5432;

    private $database;

    private $username;

    private $password;

    private $sslMode = false;

    /**
     * Last activity time
     *
     * @var int
     */
    private $lastActivityTime;

    /**
     * @var resource
     */
    private $pgConn;

    /**
     * Commit callbacks
     *
     * @var array
     */
    private $commitCallbacks = [];

    /**
     * Transaction level
     * @var int
     */
    private $transLevel = 0;

    /**
     * Actual (on the db side) transaction level
     * @var int
     */
    private $dbTransLevel = 0;

    /**
     * Transaction status for each transaction level
     * @var array
     */
    private $transLevelRolledBack = [];

    /**
     * Pending transaction status for each level
     * @var bool[]
     */
    private $pendingTransactionBegin = [];

    /**
     * Pending connection
     * @var bool
     */
    private $pendingConnection = true;

    /**
     * Current query
     * @var string
     */
    private $currentQuery;

    /**
     * Last pg error
     *
     * @var string
     */
    private $lastError;


    /**
     * @param string $host
     * @return $this
     */
    public function setHost($host)
    {
        $this->host = $host;
        return $this;
    }

    /**
     * @param int $port
     * @return $this
     */
    public function setPort($port)
    {
        $this->port = $port;
        return $this;
    }

    /**
     * @param string $database
     * @return $this
     */
    public function setDatabase($database)
    {
        $this->database = $database;
        return $this;
    }

    /**
     * @param string $username
     * @return $this
     */
    public function setUsername($username)
    {
        $this->username = $username;
        return $this;
    }

    /**
     * @param string $password
     * @return $this
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    public function requireSSL()
    {
        $this->sslMode = true;
        return $this;
    }

    public function __destruct()
    {
        if ($this->isInDbTransaction()) {
            $this->globalRollback(); // DB destructor called on the open transaction. Rolling back
        }

        pg_close($this->pgConn);
    }

    /**
     * Connection status
     *
     * @return bool
     */
    public function isConnected()
    {
        return (bool) $this->pgConn;
    }

    /**
     * DB Connect
     *
     * @return $this
     * @throws Exception
     */
    private function connect()
    {
        if ($this->isConnected()) {
            return $this;
        }

        $connectionString = sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s sslmode=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->username,
            $this->password,
            $this->sslMode ? 'enabled' : 'disabled'
        );

        $this->pgConn = pg_connect($connectionString);
        if ($this->pgConn === false) {
            throw $this->createException('Can`t connect');
        }

        $this->pendingConnection = false;

        return $this;
    }

    /**
     * Is transaction opened?
     *
     * @return bool
     */
    private function isInTransaction()
    {
        return $this->transLevel > 0;
    }

    /**
     * Is transaction opened on the db side?
     *
     * @return bool
     */
    private function isInDbTransaction()
    {
        return $this->dbTransLevel > 0;
    }

    /**
     * Begin transaction
     *
     * @param bool $immediately
     * @throws Exception
     * @return $this
     */
    public function beginTransaction($immediately = false)
    {
        //Nested transaction calls pending transactions begin
        if (!empty($this->pendingTransactionBegin[$this->transLevel])) {
            $this->dbBeginTransaction();
        }
        $this->transLevel++;
        $this->transLevelRolledBack[$this->transLevel] = false;

        if ($immediately) {
            $this->dbBeginTransaction();
        } else {
            $this->pendingTransactionBegin[$this->transLevel] = true;
        }

        return $this;
    }

    /**
     * Begin transaction on the db side
     */
    private function dbBeginTransaction()
    {
        if ($this->pendingConnection) {
            $this->connect();
        }

        $this->pendingTransactionBegin[$this->transLevel] = false;

        if ($this->dbTransLevel == 0) {
            //Top level transaction
            $res = pg_query($this->pgConn, 'begin');
        } else {
            $res = pg_query(
                $this->pgConn,
                'savepoint level' . $this->dbTransLevel . 'begin'
            );
        }

        if ($res === false) {
            $this->cleanAfterCommit();
            throw $this->createException();
        }

        $this->dbTransLevel++;

        return $res;
    }

    /**
     * Commit transaction
     *
     * @return $this
     * @throws Exception
     */
    public function commit()
    {
        $res = null;

        if (!empty($this->transLevelRolledBack[$this->transLevel])) {
            $this->transLevel--;
            return $this;
        }

        if (empty($this->pendingTransactionBegin[$this->transLevel])) {
            $this->dbTransLevel--;

            if ($this->dbTransLevel == 0) {
                $res = pg_query($this->pgConn, 'commit');
            }

            if ($res === false) {
                $this->cleanAfterCommit();
                throw $this->createException();
            }
        }

        if ($this->transLevel == 0) {
            $this->cleanAfterCommit();
            throw $this->createException('Commit without transaction');
        }

        $this->afterCommit();
        $this->transLevel--;

        return $this;
    }

    /**
     * Rollback transaction on the db side
     *
     * @return bool|int|null
     * @throws Exception
     */
    private function dbRollback()
    {
        $res = null;
        if (!$this->isInDbTransaction()) {
            return $res;
        }

        $this->dbTransLevel--;

        if ($this->dbTransLevel == 0) {
            $res = pg_query($this->pgConn, 'rollback');
        } elseif ($this->dbTransLevel > 0) {
            $res = pg_query(
                $this->pgConn,
                'rollback to level' . $this->dbTransLevel . 'begin'
            );
        }

        if ($res === false) {
            throw $this->createException();
        }

        return $res;
    }

    /**
     * Rollback transaction
     *
     * @throws Exception
     * @return $this
     */
    public function rollback()
    {
        $res = null;

        if (empty($this->pendingTransactionBegin[$this->transLevel])
            && isset($this->transLevelRolledBack[$this->transLevel])
            && $this->transLevelRolledBack[$this->transLevel] == false
        ) {
            $this->dbRollback();
        }

        if ($this->transLevel == 0) {
            throw $this->createException('Rollback without transaction');
        }

        $this->cleanAfterCommit();
        $this->transLevel--;

        return $this;
    }

    /**
     * Global rollback
     *
     * @throws Exception
     * @return $this
     */
    public function globalRollback()
    {
        $res = null;

        if ($this->isInDbTransaction()) {
            $res = pg_query($this->pgConn, 'rollback');
            if ($res === false) {
                throw $this->createException();
            }
        }
        $this->dbTransLevel = 0;
        $this->transLevel = 0;
        $this->commitCallbacks = [];

        return $this;
    }

    /**
     * Run query
     * arguments: format, [args for format]
     * e.g. 'select * from users where user_id = %d', $user_id
     *
     * @throws Exception
     * @return Result
     */
    public function exec()
    {
        $args = func_get_args();

        if (count($args) > 1) {
            $template = array_shift($args);
            $this->currentQuery = vsprintf($template, $args);
        } else {
            $this->currentQuery = $args[0];
        }

        return $this->dbExec();
    }

    /**
     * Execute single row query
     * @throws Exception
     * @return array
     */
    public function execOne()
    {
        $args = func_get_args();

        if (count($args) > 1) {
            $template = array_shift($args);
            $this->currentQuery = vsprintf($template, $args);
        } elseif (count($args) == 1) {
            $this->currentQuery = $args[0];
        } else {
            return null;
        }

        $res = $this->dbExec();
        $rows = $res->getRowsCount();
        if ($rows > 1) {
            throw $this->createException('Return set contains more than one row');
        } elseif ($rows == 0) {
            return null;
        }

        return $res->current();
    }

    /**
     * Run query
     *
     * @throws Exception
     * @return Result
     */
    private function dbExec()
    {
        if ($this->transLevel > 0
            && $this->transLevelRolledBack[$this->transLevel]
        ) {
            throw $this->createException('Trying to run query in rolled back transaction');
        }

        if (!empty($this->pendingTransactionBegin[$this->transLevel])) {
            $this->dbBeginTransaction();
        } else {
            if ($this->pendingConnection) {
                $this->connect();
            }
        }

        $result = pg_query($this->pgConn, $this->currentQuery);

        if ($result !== false) {
            return new Result($result);
        }

        if ($this->isInDbTransaction()) {
            $this->dbRollback();
            $this->transLevelRolledBack[$this->transLevel] = true;
        }

        throw $this->createException();
    }

    /**
     * Register callback on commit
     *
     * @param callable $callback
     *
     * @return $this
     */
    public function registerAfterCommit(callable $callback)
    {
        if (!$this->isInTransaction()) {
            return $this;
        }

        $this->commitCallbacks[] = $callback;
        return $this;
    }

    /**
     * Run callbacks on commit
     */
    private function afterCommit()
    {
        if (!$this->isInTransaction()) {
            return;
        }

        if (!$this->transLevelRolledBack[$this->transLevel]) {
            if ($this->transLevel == 1) {
                foreach ($this->commitCallbacks as $callback) {
                    $callback();
                }
                $this->cleanAfterCommit();
            }
        }
    }

    /**
     * Clear callbacks
     */
    private function cleanAfterCommit()
    {
        if ($this->transLevel == 1) {
            $this->commitCallbacks = [];
        }
    }

    /**
     * Close connection
     * @return $this
     */
    public function disconnect()
    {
        $this->pgConn = null;
        $this->pendingConnection = true;
        $this->lastActivityTime = null;

        return $this;
    }

    /**
     * Quote string
     *
     * @param $value string
     *
     * @return string
     */
    public static function quote($value)
    {
        return pg_escape_string($value);
    }

    /**
     * Convert php array to pg array
     *
     * @param array $arr
     * @param int $level
     * @return string
     */
    public static function toArray(array $arr, $level = 0)
    {
        foreach ($arr as $k => $v) {
            $arr[$k] = (is_array($v) ? self::toArray($v, 1) : '"' . addcslashes($v, '"\\') . '"');
        }

        if ($level === 0) {
            return "'" . pg_escape_string("{" . join(',', $arr) . "}") . "'";
        } else {
            return pg_escape_string("{" . join(',', $arr) . "}");
        }
    }

    public function createException($message = null)
    {
        if ($this->pgConn) {
            $this->lastError = pg_last_error($this->pgConn);
        }

        return new Exception($message ?: $this->lastError);
    }

    /**
     * Unix timestamp to ansi date
     *
     * @param $value
     * @return bool|string
     */
    public static function toTimestamp($value = null)
    {
        return date('Y-m-d H:i:s', $value ?: time());
    }

    /**
     * Convert to ternary boolean
     *
     * @param bool $value
     * @return string
     */
    public static function toBoolean($value)
    {
        return is_null($value) ? 'NULL' : ($value ? 'TRUE' : 'FALSE');
    }

    /**
     * Convert multilevel php array to hstore
     *
     * @param array $array
     * @return string
     */
    public static function toHStore($array)
    {
        $hstore = [];

        if (empty($array)
            || !is_array($array)
        ) {
            return "''";
        }

        foreach ($array as $paramID => $paramValue) {
            if (is_array($paramValue)) {
                $hstore[] = sprintf(
                        "hstore('%s', %s)",
                        self::quote($paramID),
                        '(' . self::toHStore($paramValue) . ')::text'
                    ) . PHP_EOL;
            } else {
                $hstore[] = sprintf(
                    "hstore('%s', '%s')",
                    self::quote($paramID),
                    self::quote($paramValue)
                );
            }
        }

        return join(' || ', $hstore);
    }

}
