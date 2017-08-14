<?php
/*
 Author: Andrews54757
 License: MIT (https://github.com/ThreeLetters/SuperSQL/blob/master/LICENSE)
 Source: https://github.com/ThreeLetters/SQL-Library
 Build: v1.0.2
 Built on: 14/08/2017
*/

// lib/connector/index.php
class Response
{
    public $result;
    public $affected;
    public $ind = 0;
    public $error;
    public $errorData;
    public $outTypes;
    public $complete = false;
    public $stmt;
    function __construct($data, $error, &$outtypes, &$mode)
    {
        $this->error = !$error;
        if (!$error) {
            $this->errorData = $data->errorInfo();
        } else {
            $this->outTypes = $outtypes;
            $this->init($data,$mode);
            $this->affected = $data->rowCount();
        }
    }
    private function init(&$data, &$mode) {
        if ($mode === 0) { 
            $outtypes = $this->outTypes;
            $d = $data->fetchAll();
            if ($outtypes) {
                foreach ($d as $i => &$row) {
                    $this->map($row,$outtypes);
                }
            }
            $this->result = $d;
            $this->complete = true;
        } else if ($mode === 1) { 
            $this->stmt = $data;
            $this->result = array();
        }
    }
    function close() {
            $this->complete = true;
        if ($this->stmt) {
            $this->stmt->closeCursor();
            $this->stmt = null;
        }
    }
    private function fetchNextRow() {
       $row = $this->stmt->fetch();
        if ($row) {
         if ($this->outTypes) {
        $this->map($row,$this->outTypes);   
        }
        array_push($this->result,$row);
        return $row;
        } else {
            $this->complete = true;
            $this->stmt->closeCursor();
            $this->stmt = null;
            return false;
        }
    }
    private function fetchAll() {
        while ($row = $this->fetchNextRow()) {
        }
    }
    function map(&$row,&$outtypes) {
                    foreach ($outtypes as $col => $dt) {
                        if (isset($row[$col])) {
                            switch ($dt) {
                                case 'int':
                                    $row[$col] = (int)$row[$col];
                                    break;
                                case 'string':
                                     $row[$col] = (string)$row[$col];
                                    break;
                                case 'bool':
                                     $row[$col] = $row[$col] ? true : false;
                                    break;
                                case 'json':
                                    $row[$col] = json_decode($row[$col]);
                                    break;
                                case 'obj':
                                    $row[$col] = unserialize($row[$col]);
                                    break;
                            }
                        }
                    }   
    }
    function error()
    {
        return $this->error ? $this->errorData : false;
    }
    function getData($current = false)
    {
        if (!$this->complete && !$current) $this->fetchAll();
        return $this->result;
    }
    function getAffected()
    {
        return $this->affected;
    }
    function countRows() {
        return count($this->result);
    }
    function next()
    {
        if (isset($this->result[$this->ind])) {
            return $this->result[$this->ind++];
        } else if (!$this->complete) {
            $row = $this->fetchNextRow();
            $this->ind++;
            return $row;
        } else {
            return false;
        }
    }
    function reset()
    {
        $this->ind = 0;
    }
}
class Connector
{
    public $db;
    public $log = array();
    public $dev = false;
    function __construct($dsn, $user, $pass)
    {
        $this->db  = new \PDO($dsn, $user, $pass);
        $this->log = array();
    }
    function query($query,$obj = null,$outtypes = null, $mode = 0)
    {
            $q = $this->db->prepare($query);
        if ($obj) $e = $q->execute($obj);
        else $e = $q->execute();
        if ($this->dev)
            array_push($this->log, array(
                $query,
                $obj
            ));
        if ($mode !== 3) {
         return new Response($q,$e,$outtypes,$mode);   
        } else {
        return $q;
        }
    }
    function _query(&$sql, $values, &$insert, &$outtypes = null, $mode = 0)
    {
        $q                   = $this->db->prepare($sql);
         if ($this->dev) 
             array_push($this->log,array(
                    $sql,
                    $values,
                    $insert
             ));
        foreach ($values as $key => &$va) {
                $q->bindParam($key + 1, $va[0],$va[1]);
        }
         $e = $q->execute();
        if (!isset($insert[0])) { 
            return new Response($q, $e, $outtypes, $mode);
        } else { 
            $responses = array();
            array_push($responses,new Response($q, $e, $outtypes, 0));
            foreach ($insert as $key => $value) {
                foreach ($value as $k => &$val) {
                    $values[$k][0] = $val;
                }
                $e = $q->execute();
                array_push($responses, new Response($q, $e, $outtypes, 0));
            }
            return $responses;
        }
    }
    function close()
    {
        $this->db      = null;
        $this->queries = null;
    }
}

// lib/parser/Simple.php
class SimParser
{
    public static function WHERE($where, &$sql, &$insert)
    {
        if (!empty($where)) {
            $sql .= ' WHERE ';
            $i = 0;
            foreach ($where as $key => $value) {
                if ($i !== 0) {
                    $sql .= ' AND ';
                }
                $sql .= '`' . $key . '` = ?';
                array_push($insert,$value);
                $i++;
            }
        }
    }
    public static function SELECT($table, $columns, $where, $append)
    {
        $sql    = 'SELECT ';
        $insert = array();
        if (!isset($columns[0])) { 
            $sql .= '*';
        } else { 
            $len    = count($columns);
            for ($i = 0; $i < $len; $i++) {
                if ($i !== 0) {
                    $sql .= ', ';
                }
                $sql .= '`' . $columns[$i] . '`';
            }
        }
        $sql .= ' FROM `' . $table . '`';
        self::WHERE($where, $sql, $insert);
       if ($append) $sql .= ' ' . $append;
        return array(
            $sql,
            $insert
        );
    }
    public static function INSERT($table, $data)
    {
        $sql    = 'INSERT INTO `' . $table . '` (';
        $add    = ') VALUES (';
        $insert = array();
        $i = 0;
        foreach ($data as $key => $value) {
            if ($i !== 0) {
                $sql .= ', ';
                $add .= ', ';
            }
            $sql .= '`' . $key . '`';
            $add .= '?';
            array_push($insert, $value);
            $i++;
        }
        $sql .= $add . ')';
        return array(
            $sql,
            $insert
        );
    }
    public static function UPDATE($table, $data, $where)
    {
        $sql    = 'UPDATE `' . $table . '` SET ';
        $insert = array();
        $i = 0;
        foreach ($data as $key => $value) {
            if ($i !== 0) {
                $sql .= ', ';
            }
            $sql .= '`' . $key . '` = ?';
            array_push($insert, $value);
            $i++;
        }
        self::WHERE($where, $sql, $insert);
        return array(
            $sql,
            $insert
        );
    }
    public static function DELETE($table, $where)
    {
        $sql    = 'DELETE FROM `' . $table . '`';
        $insert = array();
        self::WHERE($where, $sql, $insert);
        return array(
            $sql,
            $insert
        );
    }
}

// lib/parser/Advanced.php
class AdvParser
{
    static function getArg(&$str)
    {
        if (isset($str[3]) && $str[0] === '[' && $str[3] === ']') {
            $out = $str[1] . $str[2];
            $str = substr($str, 4);
            return $out;
        } else {
            return false;
        }
    }
    static function append(&$args, $val, $index, $values)
    {
        if (is_array($val) && $values[$index][2] < 5) {
            $len = count($val);
            for ($k = 1; $k < $len; $k++) {
                if (!isset($args[$k - 1]))
                    $args[$k - 1] = array();
                $args[$k - 1][$index] = $val[$k];
            }
        }
    }
    static function append2(&$insert, $indexes, $dt, $values)
    {
        function stripArgs(&$key)
        {
            if (substr($key, -1) === ']') {
                $b   = strrpos($key, '[', -1);
                $key = substr($key, 0, $b);
            }
            $b = strrpos($key, ']', -1);
            if ($b !== false)
                $key = substr($key, $b + 1);
            if ($key[0] === '#') {
                    $key = substr($key, 1);
             }
        }
        function escape($val, $dt)
        {
        if (!isset($dt[2])) return $val;
        switch ($dt[2]) {
            case 0: 
                return $val ? '1' : '0';
                break;
            case 1: 
                return (int)$val;
                break;
            case 2: 
                return (string)$val;
                break;
            case 3: 
                return $val;
                break;
            case 4: 
                return null;
                 break;
            case 5: 
                return json_encode($val);
                break;
            case 6: 
                return serialize($val);
                break;
        }
        }
        function recurse(&$holder, $val, $indexes, $par, $values)
        {
            foreach ($val as $k => &$v) {
                stripArgs($k);
                $k1 = $k . '#' . $par;
                if (isset($indexes[$k1]))
                    $d = $indexes[$k1];
                else
                    $d = $indexes[$k];
                $isArr = is_array($v) && (!isset($values[$d][2]) || $values[$d][2] < 5);
                if ($isArr) {
                    if (isset($v[0])) {
                        foreach ($v as $i => &$j) {
                            $a = $d + $i;
                            if (isset($holder[$a])) echo 'SUPERSQL WARN: Key collision: ' . $k;
                            $holder[$a] = escape($j,$values[$a]);
                        }
                    } else {
                        recurse($holder, $v, $indexes, $par . '/' . $k, $values);
                    }
                } else {
                      if (isset($holder[$d])) echo 'SUPERSQL WARN: Key collision: ' . $k;
                    $holder[$d] = escape($v,$values[$d]);
                }
            }
        }
        $len = count($dt);
        for ($key = 1; $key < $len; $key++) {
            $val = $dt[$key];
            if (!isset($insert[$key - 1]))
                $insert[$key - 1] = array();
            recurse($insert[$key - 1], $val, $indexes, '', $values);
        }
    }
    static function quote($str)
    {
        if (strpos($str,'.') === false) {
            return '`' . $str . '`';
        } else {
        $str = explode('.', $str);
        $out = '';
        $c = count($str);
        for ($i = 0; $i < $c; $i++) {
            if ($i !== 0)
                $out .= '.';
            $out .= '`' . $str[$i] . '`';
        }
        return $out;
        }
    }
    static function table($table)
    {
        if (is_array($table)) {
            $sql = '';
            foreach ($table as $i => &$val) {
                $t = self::getType($val);
                if ($i !== 0)
                    $sql .= ', ';
                $sql .= '`' . $val . '`';
                if ($t)
                    $sql .= ' AS `' . $t . '`';
            }
            return $sql;
        } else {
            return '`' . $table . '`';
        }
    }
    static function value($type, $value)
    {
        $var = $type ? $type : gettype($value);
        $type = \PDO::PARAM_STR;
        $dtype = 2;
        if ($var === 'integer' || $var === 'int' || $var === 'double' || $var === 'doub') {
            $type = \PDO::PARAM_INT;
            $dtype = 1;
            $value = (int) $value;
        } else if ($var === 'string' || $var === 'str') {
            $value = (string) $value;
            $dtype = 2;
        } else if ($var === 'boolean' || $var === 'bool') {
            $type  = \PDO::PARAM_BOOL;
            $value = $value ? '1' : '0';
            $dtype = 0;
        } else if ($var === 'null' || $var === 'NULL') {
            $dtype = 4;
            $type  = \PDO::PARAM_NULL;
            $value = null;
        } else if ($var === 'resource' || $var === 'lob') {
            $type = \PDO::PARAM_LOB;
            $dtype = 3;
        } else if ($var === 'json') {
            $dtype = 5;
            $value = json_encode($value);
        } else if ($var === 'obj') {
              $dtype = 6;
            $value = serialize($value);
        } else {
            $value = (string)$value;
            echo 'SUPERSQL WARN: Invalid type ' . $var . ' Assumed STRING';
        }
        return array(
            $value,
            $type,
            $dtype
        );
    }
    static function getType(&$str)
    {   
        if (isset($str[1]) && $str[strlen($str) - 1] === ']') {
            $start = strrpos($str, '[');
            if ($start === false) {
                return '';
            }
            $out = substr($str, $start + 1, -1);
            $str = substr($str, 0, $start);
            return $out;
        } else
            return '';
    }
    static function rmComments($str) {
        $i = strpos($str,'#');
        if ($i !== false) {
            $str = trim(substr($str,0,$i));
        }
        return $str;
    }
    static function conditions($dt, &$values = false, &$map = false, &$index = 0)
    {
        $build = function(&$build, $dt, &$map, &$index, &$values, $join = ' AND ', $operator = ' = ', $parent = '')
        {
            $num = 0;
            $sql = '';
            foreach ($dt as $key => &$val) {
                if ($key[0] === '#') {
                    $raw = true;
                    $key = substr($key, 1);
                } else {
                    $raw = false;
                }
                $arg         = self::getArg($key);
                $arg2        = $arg ? self::getArg($key) : false;
                $useBind     = !isset($val[0]);
                $newJoin     = $join;
                $newOperator = $operator;
                $type = $raw ? false : self::getType($key);
                $column = self::quote(self::rmComments($key));
                switch ($arg) {
                    case '||':
                        $arg     = $arg2;
                        $newJoin = ' OR ';
                        break;
                    case '&&':
                        $arg     = $arg2;
                        $newJoin = ' AND ';
                        break;
                }
                switch ($arg) { 
                    case '!=':
                        $newOperator = ' != ';
                        break;
                    case '>>':
                        $newOperator = ' > ';
                        break;
                    case '<<':
                        $newOperator = ' < ';
                        break;
                    case '>=':
                        $newOperator = ' >= ';
                        break;
                    case '<=':
                        $newOperator = ' <= ';
                        break;
                    case '~~':
                        $newOperator = ' LIKE ';
                        break; 
                    case '!~':
                        $newOperator = ' NOT LIKE ';
                        break; 
                    default:
                        if (!$useBind || $arg === '==')
                            $newOperator = ' = '; 
                        break;
                }
                if ($num !== 0)
                    $sql .= $join;
                if (is_array($val) && $type !== 'json' && $type !== 'obj') {
                    if ($useBind) {
                        $sql .= '(' . $build($build, $val, $map, $index, $values, $newJoin, $newOperator, $parent . '/' . $key) . ')';
                    } else {
                        if ($map !== false && !$raw) {
                            $map[$key]                 = $index;
                            $map[$key . '#' . $parent] = $index++;
                        }
                        foreach ($value as $k => &$v) {
                            if ($k !== 0)
                                $sql .= $newJoin;
                            $index++;
                            $sql .= $column . $newOperator;
                            if ($raw) {
                                $sql .= $v;
                            } else if ($values !== false) {
                                $sql .= '?';
                                array_push($values, self::value($type, $v));
                            } else {
                                if (is_int($v)) {
                                    $sql .= $v;
                                } else {
                                    $sql .= self::quote($v);
                                }
                            }
                        }
                    }
                } else {
                    $sql .= $column . $newOperator;
                    if ($raw) {
                          $sql .= $val;
                    } else {
                        if ($values !== false) {
                            $sql .= '?';
                            array_push($values, self::value($type, $val));
                        } else {
                            if (is_int($val)) {
                                $sql .= $val;
                            } else {
                                $sql .= self::quote($val);
                            }
                        }
                        if ($map !== false) {
                            $map[$key]                 = $index;
                            $map[$key . '#' . $parent] = $index++;
                        }
                    }
                }
                 $num++;
            }
            return $sql;
        };
        return $build($build, $dt, $map, $index, $values);
    }
    static function JOIN($join, &$sql) {
        foreach ($join as $key => &$val) {
                if ($key[0] === '#') {
                    $raw = true;
                    $key = substr($key, 1);
                } else {
                    $raw = false;
                }
                $arg = self::getArg($key);
                switch ($arg) {
                    case '<<':
                        $sql .= ' RIGHT JOIN ';
                        break;
                    case '>>':
                        $sql .= ' LEFT JOIN ';
                        break;
                    case '<>':
                        $sql .= ' FULL JOIN ';
                        break;
                    default: 
                        $sql .= ' JOIN ';
                        break;
                }
                $sql .= '`' . $key . '` ON ';
                if ($raw) {
                    $sql .= 'val';
                } else {
                    $sql .= self::conditions($val);
                }
            }
    }
    static function SELECT($table, $columns, $where, $join, $limit)
    {
        $sql = 'SELECT ';
        $values = array();
        $insert = array();
        $outTypes = null;
        if (!isset($columns[0])) { 
            $sql .= '*';
        } else { 
            $req  = 0;
            $into = '';
            $f = $columns[0][0];
            if ($f === 'D' || $f === 'I') {
            if ($columns[0] === 'DISTINCT') {
                $req = 1;
                $sql .= 'DISTINCT ';
                array_splice($columns,0,1);
            } else if (substr($columns[0], 0, 11) === 'INSERT INTO') {
                $req = 1;
                $sql = $columns[0] . ' ' . $sql;
                array_splice($columns,0,1);
            } else if (substr($columns[0], 0, 4) === 'INTO') {
                $req  = 1;
                $into = ' ' . $columns[0] . ' ';
                array_splice($columns,0,1);
            }
            }
            if (isset($columns[0])) { 
                foreach ($columns as $i => &$val) {
                    $b = self::getType($val);
                    $t = $b ? self::getType($val) : false;
                    if (!$t && $b) {
                        if (!($b === 'int' || $b === 'string' || $b === 'json' || $b === 'obj' || $b === 'bool')) {
                            $t = $b;
                            $b = false;   
                        }
                    }
                    if ($b) {
                        if (!$outTypes) $outTypes = array();
                        if ($t) {
                        $outTypes[$t] = $b;
                        } else {
                        $outTypes[$val] = $b;
                        }
                    }
                    if ($i != 0) {
                        $sql .= ', ';
                    }
                    $sql .= self::quote($val);
                    if ($t)
                        $sql .= ' AS `' . $t . '`';
                }
            } else
                $sql .= '*';
            $sql .= $into;
        }
        $sql .= ' FROM ' . self::table($table);
        if ($join) {
            self::JOIN($join,$sql);
        }
        if (!empty($where)) {
            $sql .= ' WHERE ';
            $index = array();
            if (isset($where[0])) {
                $sql .= self::conditions($where[0], $values, $index);
                self::append2($insert, $index, $where, $values);
            } else {
                $sql .= self::conditions($where, $values, $index);
            }
        }
        if ($limit) {
            if (is_int($limit)) {
                 $sql .= ' LIMIT ' . $limit; 
            } else if (is_string($limit)) {
                 $sql .= ' ' . $limit; 
            }
        }
        return array(
            $sql,
            $values,
            $insert,
            $outTypes
        );
    }
    static function INSERT($table, $data)
    {
        $sql        = 'INSERT INTO ' . self::table($table) . ' (';
        $values     = array();
        $insert     = array();
        $append     = '';
        $i       = 0;
        $b       = 0;
        $indexes = array();
        $multi   = isset($data[0]);
        $dt      = $multi ? $data[0] : $data;
        foreach ($dt as $key => &$val) {
            if ($key[0] === '#') {
                $raw = true;
                $key = substr($key, 1);
            } else {
                $raw = false;
            }
            if ($b !== 0) {
                $sql .= ', ';
                $append .= ', ';
            }
            $type = self::getType($key);
            $sql .= '`' . self::rmComments($key) . '`';
            if ($raw) {
                $append .= $val;
            } else {
                $append .= '?';
                array_push($values, self::value($type, $val));
                if ($multi) {
                    $indexes[$key] = $i++;
                } else {
                    self::append($insert, $val, $i++, $values);
                }
            }
            $b++;
        }
        if ($multi)
            self::append2($insert, $indexes, $data, $values);
        $sql .= ') VALUES (' . $append . ')';
        return array(
            $sql,
            $values,
            $insert
        );
    }
    static function UPDATE($table, $data, $where)
    {
        $sql        = 'UPDATE ' . self::table($table) . ' SET ';
        $values     = array();
        $insert     = array();
        $i          = 0;
        $b          = 0;
        $indexes    = array();
        $multi      = isset($data[0]);
        $dt         = $multi ? $data[0] : $data;
        foreach ($dt as $key => &$val) {
            if ($key[0] === '#') {
                $raw = true;
                $key = substr($key, 1);
            } else {
                $raw = false;
            }
            if ($b !== 0) {
                $sql .= ', ';
            }
            if ($raw) {
                $sql .= '`' . $key . '` = ' . $val;
            } else {
                $arg = self::getArg($key);
                $sql .= '`' . $key . '` = ';
                switch ($arg) {
                    case '+=':
                        $sql .= '`' . $key . '` + ?';
                        break;
                    case '-=':
                        $sql .= '`' . $key . '` - ?';
                        break;
                    case '/=':
                        $sql .= '`' . $key . '` / ?';
                        break;
                    case '*=':
                        $sql .= '`' . $key . '` * ?';
                        break;
                    default:
                        $sql .= '?';
                        break;
                }
                $type = self::getType($key);
                array_push($values, self::value($type, $val));
                if ($multi) {
                    $indexes[$key] = $i++;
                } else {
                    self::append($insert, $val, $i++, $values);
                }
            }
            $b++;
        }
        if ($multi)
            self::append2($insert, $indexes, $data, $values);
        if (!empty($where)) {
            $sql .= ' WHERE ';
            $index = array();
            if (isset($where[0])) {
                $sql .= self::conditions($where[0], $values, $index, $i);
                self::append2($insert, $index, $where, $values);
            } else {
                $sql .= self::conditions($where, $values, $index, $i);
            }
        }
        return array(
            $sql,
            $values,
            $insert
        );
    }
    static function DELETE($table, $where)
    {
        $sql        = 'DELETE FROM ' . self::table($table);
        $values     = array();
        $insert     = array();
        if (!empty($where)) {
            $sql .= ' WHERE ';
            $index = array();
            if (isset($where[0])) {
                $sql .= self::conditions($where[0], $values, $index);
                self::append2($insert, $index, $where, $values);
            } else {
                $sql .= self::conditions($where, $values, $index);
            }
        }
        return array(
            $sql,
            $values,
            $insert
        );
    }
}

// index.php
class SuperSQL
{
    public $con;
    public $lockMode = false;
    function __construct($dsn, $user, $pass)
    {
        $this->con = new Connector($dsn, $user, $pass);
    }
    function SELECT($table, $columns = array(), $where = array(), $join = null, $limit = false)
    {
        if ((is_int($join) || is_string($join)) && !$limit) {
            $limit = $join;
            $join  = null;
        }
        $d = AdvParser::SELECT($table, $columns, $where, $join, $limit);
        return $this->con->_query($d[0], $d[1], $d[2], $d[3], $this->lockMode ? 0 : 1);
    }
    function INSERT($table, $data)
    {
        $d = AdvParser::INSERT($table, $data);
        return $this->con->_query($d[0], $d[1], $d[2]);
    }
    function UPDATE($table, $data, $where = array())
    {
        $d = AdvParser::UPDATE($table, $data, $where);
        return $this->con->_query($d[0], $d[1], $d[2]);
    }
    function DELETE($table, $where = array())
    {
        $d = AdvParser::DELETE($table, $where);
        return $this->con->_query($d[0], $d[1], $d[2]);
    }
    function sSELECT($table, $columns = array(), $where = array(), $append = "")
    {
        $d = SimParser::SELECT($table, $columns, $where, $append);
        return $this->con->query($d[0], $d[1],null,$this->lockMode ? 0 : 1);
    }
    function sINSERT($table, $data)
    {
        $d = SimParser::INSERT($table, $data);
        return $this->con->query($d[0], $d[1]);
    }
    function sUPDATE($table, $data, $where = array())
    {
        $d = SimParser::UPDATE($table, $data, $where);
        return $this->con->query($d[0], $d[1]);
    }
    function sDELETE($table, $where = array())
    {
        $d = SimParser::DELETE($table, $where);
        return $this->con->query($d[0], $d[1]);
    }
    function query($query, $obj = null,$outtypes = null, $mode = 0)
    {
        return $this->con->query($query, $obj, $outtypes, $mode);
    }
    function close()
    {
        $this->con->close();
    }
    function dev()
    {
        $this->con->dev = true;
    }
    function getLog()
    {
        return $this->con->log;
    }
    function transact($func) {
        $this->con->db->beginTransaction();
        $r = $func($this);
        if ($r === false)
            $this->con->db->rollBack();
         else 
            $this->con->db->commit();
        return $r;
    }
    function modeLock($val) {
        $this->lockMode = $val;
    }
}
?>
