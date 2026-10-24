<?php
namespace FleetApi;

// Small prepared-statement facade shared by the representation and EAI readers.
// No DDL, writes, persistent connection, LOCAL INFILE, retry or EAI transport.
class MysqliDatabase
{
    private $connection;
    public function __construct($connection) { $this->connection = $connection; }

    public static function open(array $config)
    {
        if (!class_exists('mysqli')) { throw new ApiException(503, 'source_unavailable'); }
        $db = null;
        try {
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $db->options(MYSQLI_OPT_LOCAL_INFILE, false);
            if (defined('MYSQLI_OPT_READ_TIMEOUT')) { $db->options(constant('MYSQLI_OPT_READ_TIMEOUT'), 5); }
            if (!@$db->real_connect($config['host'], $config['username'], $config['password'], $config['database'], $config['port'])) { throw new ApiException(503, 'source_unavailable'); }
            if (!$db->set_charset('utf8mb4')) { throw new ApiException(503, 'source_unavailable'); }
            $facade = new self($db);
            $version = $facade->query('SELECT VERSION() AS version')->fetch_assoc();
            // Milliseconds in MySQL, seconds in MariaDB. Unsupported variables fail closed.
            $timeout = (int)$config['queryTimeoutMs'];
            if (stripos($version['version'], 'MariaDB') !== false) {
                $facade->query('SET SESSION max_statement_time = '.sprintf('%.3F', $timeout / 1000));
            } else { $facade->query('SET SESSION max_execution_time = '.$timeout); }
            $facade->query('SET SESSION innodb_lock_wait_timeout = 2');
            $facade->query('SET SESSION lock_wait_timeout = 2');
            $facade->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            return $facade;
        } catch (\Exception $e) {
            if ($db) { @$db->close(); }
            throw new ApiException(503, 'source_unavailable');
        }
    }

    public function query($sql)
    {
        $result = $this->connection->query($sql);
        if ($result === false) { throw new ApiException(503, 'source_unavailable'); }
        return $result;
    }
    public function prepare($sql)
    {
        $statement = $this->connection->prepare($sql);
        if (!$statement) { throw new ApiException(503, 'source_unavailable'); }
        return new MysqliStatement($statement);
    }
    public function beginRead() { $this->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); }
    public function requireInnoDb($table)
    {
        $query=$this->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $query->execute(array($table));$row=$query->fetch();
        if(!$row || strcasecmp($row['ENGINE'],'InnoDB')!==0){throw new ApiException(503,'source_unavailable');}
    }
    public function commit() { if (!$this->connection->commit()) { throw new ApiException(503, 'source_unavailable'); } }
    public function rollback() { @$this->connection->rollback(); }
    public function close() { $this->connection->close(); }
}

class MysqliStatement
{
    private $statement;
    private $result;
    public function __construct($statement) { $this->statement = $statement; }
    public function execute(array $values)
    {
        if ($values) {
            $arguments = array(str_repeat('s', count($values)));
            foreach ($values as $key => $unused) { $arguments[] = &$values[$key]; }
            if (!call_user_func_array(array($this->statement, 'bind_param'), $arguments)) { throw new ApiException(503, 'source_unavailable'); }
        }
        if (!$this->statement->execute()) { throw new ApiException(503, 'source_unavailable'); }
        $this->result = $this->statement->get_result();
        if ($this->result === false) { throw new ApiException(503, 'source_unavailable'); }
        return true;
    }
    public function fetch($unused = null) { return $this->result->fetch_assoc(); }
    public function __destruct()
    {
        if ($this->result) { $this->result->free(); }
        $this->statement->close();
    }
}
