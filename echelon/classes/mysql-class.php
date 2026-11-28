<?php

## Die if the user has come to this page directly ##
if ('mysql-class.php' === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    die ('Please do not load this page directly. Thanks!');
}

/**
 * class DB_B3 - connection to the B3 (game) database. Singleton.
 *
 * Performance / memory notes (this class used to):
 *  - write a line to the log file for EVERY query,
 *  - prepare+execute every plain SELECT (two round trips) and copy rows through bind_result,
 *  - run the whole un-paginated query just to count rows for pagination (buffering every row),
 *  - never close statements (the close() calls sat after a return).
 * It now runs plain queries directly, fetches with fetch_all(), counts with COUNT(*), frees results,
 * and only uses prepared statements when parameters are supplied.
 *
 * @param Host
 * @param User
 * @param Password
 * @param Name
 */
class DB_B3 {

    ## Settings ##
    public $mysql = NULL; // object var (if connection exists this will not be NULL)
    public $error = FALSE; // was there a DB query error
    public $query_error_pub = NULL;
    public $error_msg;

    private $error_sec = 'We are having some database problems, please check back later.'; // message to show the public if the DB query/connect fails
    private $error_on = false; // are detailed error reports on (default, can be overidden)

    private static $instance = NULL;

    /**
     * Gets the current instance of the class, there can only be one instance (this make the class a singleton class)
     *
     * @return DB_B3 $instance - the current instance of the class
     */
    public static function getInstance($host, $user, $pass, $name, $error_on = false) {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self($host, $user, $pass, $name, $error_on);
        }

        return self::$instance;
    }

    public static function getPointer() {
        return self::$instance;
    }

    /**
     * Make the connection to the B3 DB. Credentials are not kept on the object.
     */
    public function __construct($host, $user, $pass, $name, $error_on) {
        $this->error_on = $error_on;

        try {
            $this->mysql = new mysqli($host, $user, $pass, $name);
            if (defined('B3_DB_CHARSET') && B3_DB_CHARSET !== '') {
                $this->mysql->set_charset(B3_DB_CHARSET); // optional, see config_sample.php
            }
        } catch (mysqli_sql_exception $e) {
            $code = $e->getCode() ?: 1;
            $this->mysql = NULL;
            $this->error = true;
            $this->error_msg = $this->error_on
                ? '<strong>B3 Database Connection Error:</strong> (#' . $code . ') ' . htmlspecialchars($e->getMessage())
                : $this->error_sec;
            echLog('mysqlconnect', strip_tags($this->error_msg), $code);
        }
    }

    // Do not allow the clone operation
    private function __clone() { }

    /**
     * If access to a protected or private function is called
     */
    public function __call($name, $arg) {
        echLog('error', 'System tried to access function '. $name .', a private or protected function in class '. get_class($this)); // log error
        echo "<strong>" . htmlspecialchars($name) . "</strong> is a private function that cannot be accessed outside the B3 MySQL class";
    }

    /**
     * __destruct : Destructor for class, closes the MySQL connection
     */
    public function __destruct() {
        if($this->mysql instanceof mysqli) {
            @$this->mysql->close();
        }
    }

    /**
     * Record a query failure, set the public error flag and message.
     */
    private function fail(Throwable $e, $sql = '') {
        $this->error = true;
        $this->error_msg = $this->error_on
            ? 'MySQL Query Error (#' . $e->getCode() . '): ' . htmlspecialchars($e->getMessage())
            : $this->error_sec;
        echLog('mysql', $e->getMessage() . ' | SQL: ' . substr($sql, 0, 500), $e->getCode());
    }

    /**
     * Handy Query function (SELECT only)
     *
     * @param string $sql    - the SQL query to execute
     * @param bool   $fetch  - true: return the rows. false: return only the row COUNT (cheap - no rows are fetched)
     * @param string $type   - kept for backwards compatibility, only 'select' is used
     * @param array  $params - optional bound parameters; when given the query is prepared (use ? placeholders)
     * @return array ['num_rows' => int, 'data' => array of associative rows]
     */
    public function query($sql, $fetch = true, $type = 'select', array $params = array()) {

        ## setup results array
        $results = array('data' => array(), 'num_rows' => 0);

        if(empty($sql) || !($this->mysql instanceof mysqli) || $this->error) {
            return $results;
        }

        try {

            ## count only: let the database count instead of shipping every row to PHP
            if (!$fetch) {
                $count_sql = 'SELECT COUNT(*) FROM (' . rtrim(trim($sql), "; \t\n\r") . ') AS echelon_count';
                try {
                    $res = $this->run($count_sql, $params);
                    $row = $res->fetch_row();
                    $res->free();
                    $results['num_rows'] = (int) ($row[0] ?? 0);
                    return $results;
                } catch (mysqli_sql_exception $e) {
                    // e.g. duplicate column names in the select list - fall through to a plain count of the result set
                }
            }

            $res = $this->run($sql, $params);
            $results['num_rows'] = $res->num_rows;
            if ($fetch) {
                $results['data'] = $res->fetch_all(MYSQLI_ASSOC);
            }
            $res->free();

        } catch (mysqli_sql_exception $e) {
            $this->fail($e, $sql);
            return array('data' => array(), 'num_rows' => 0);
        }

        return $results;
    } // end query()

    /**
     * Run a SELECT and return a mysqli_result. Prepares only when there are params.
     */
    private function run($sql, array $params) {
        if (empty($params)) {
            return $this->mysql->query($sql);
        }
        $stmt = $this->mysql->prepare($sql);
        $stmt->execute(array_values($params));
        $res = $stmt->get_result();
        $stmt->close();
        return $res;
    }

    /**
     * Run an INSERT/UPDATE/DELETE with bound params.
     *
     * @return int|false affected rows, or false on error
     */
    public function exec($sql, array $params = array()) {
        if (!($this->mysql instanceof mysqli)) {
            return false;
        }
        try {
            $stmt = $this->mysql->prepare($sql);
            $stmt->execute(array_values($params));
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected;
        } catch (mysqli_sql_exception $e) {
            $this->fail($e, $sql);
            return false;
        }
    }

    function getB3Groups() {
        $res = $this->mysql->query("SELECT id, name FROM usergroups ORDER BY id ASC");
        $groups = $res->fetch_all(MYSQLI_ASSOC);
        $res->free();
        return $groups;
    }

    function getB3GroupsLevel() {
        $res = $this->mysql->query("SELECT id FROM usergroups ORDER BY id ASC");
        $groups = array_column($res->fetch_all(MYSQLI_ASSOC), 'id');
        $res->free();
        return $groups;
    }

    /**
     * insert a penalty into the penalty table in the B3 DB
     *
     * @param string $type - type of penalty
     * @param int $cid - client_id who is getting the penalty
     * @param int $duration - duration of pen
     * @param string $reason - reason for pen
     * @param string $data - some additional data
     * @param int $time_expire - time (unix time) for when the pen will expire
     * @return bool
     */
    function penClient($type, $cid, $duration, $reason, $data, $time_expire) {
        $query = "INSERT INTO penalties (type, client_id, admin_id, duration, inactive, keyword, reason, data, time_add, time_edit, time_expire) VALUES(?, ?, 0, ?, 0, 'Echelon', ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), ?)";
        return $this->exec($query, array($type, (int) $cid, (int) $duration, $reason, $data, (int) $time_expire)) > 0;
    }

    /**
     * Deactivate a penalty
     *
     * @param string $pen_id - id of penalty to deactivate
     * @return bool
     */
    function makePenInactive($pen_id) {
        return $this->exec("UPDATE penalties SET inactive = 1 WHERE id = ? LIMIT 1", array((int) $pen_id)) > 0;
    }

    /**
     * Get the pbid of the client from a penalty id
     *
     * @param string $pen_id - id of penalty to search with
     * @return string - pbid of the client
     */
    function getPBIDfromPID($pen_id) {
        $r = $this->query("SELECT c.pbid FROM penalties p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ? LIMIT 1", true, 'select', array((int) $pen_id));
        return $r['data'][0]['pbid'] ?? null;
    }

    /**
     * Get the guid of the client from a penalty id, similar to pbid
     *
     * @param string $pen_id - id of penalty to search with
     * @return string - guid of the client
     */
    function getGUIDfromPID($pen_id) {
        $r = $this->query("SELECT c.guid FROM penalties p LEFT JOIN clients c ON p.client_id = c.id WHERE p.id = ? LIMIT 1", true, 'select', array((int) $pen_id));
        return $r['data'][0]['guid'] ?? null;
    }

    /*********************************************************************
     * MAP CONFIG
     *********************************************************************/

    /** Columns of the mapconfig table, in the order the B3 fork defines them */
    const MAP_COLUMNS = 'id, mapname, capturelimit, g_suddendeath, g_gear, g_gravity, g_friendlyfire, startmessage, skiprandom, datelastadd, timelimit, gamemodes, bot';

    /**
     * Every map, for the tile board. One query, explicit columns, no per-row work.
     *
     * @return array rows
     */
    function getMapconfigs() {
        $res = $this->query("SELECT " . self::MAP_COLUMNS . " FROM mapconfig ORDER BY mapname ASC");
        return $res['data'];
    }

    /**
     * One map by id
     *
     * @return array|null
     */
    function getMapconfig($id) {
        $res = $this->query("SELECT " . self::MAP_COLUMNS . " FROM mapconfig WHERE id = ? LIMIT 1", true, 'select', array((int) $id));
        return $res['data'][0] ?? null;
    }

    /**
     * Is there already a map with this name (optionally ignoring one id, for renames)
     */
    function mapconfigNameExists($mapname, $exclude_id = 0) {
        $res = $this->query("SELECT id FROM mapconfig WHERE mapname = ? AND id <> ? LIMIT 1", true, 'select', array($mapname, (int) $exclude_id));
        return $res['num_rows'] > 0;
    }

    /**
     * Add the mapconfig information the mapconfig table
     *
     * @return int|false the new map id, or false
     */
    function addMapconfig($mapname, $capturelimit, $g_suddendeath, $g_gear, $g_gravity, $g_friendlyfire, $startmessage, $skiprandom, $datelastadd, $timelimit, $gamemodes, $bot) {
        $query = "INSERT INTO mapconfig (mapname, capturelimit, g_suddendeath, g_gear, g_gravity, g_friendlyfire, startmessage, skiprandom, datelastadd, timelimit, gamemodes, bot) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->mysql->prepare($query);
        $stmt->bind_param('siisiisisisi', $mapname, $capturelimit, $g_suddendeath, $g_gear, $g_gravity, $g_friendlyfire, $startmessage, $skiprandom, $datelastadd, $timelimit, $gamemodes, $bot);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return $id > 0 ? (int) $id : false;
    }

    /** Set only the game types of one map (used when they are read from the game's .arena files) */
    function setMapconfigModes($id, $gamemodes) {
        $stmt = $this->mysql->prepare("UPDATE mapconfig SET gamemodes = ? WHERE id = ? LIMIT 1");
        $gm = (string) $gamemodes; $i = (int) $id;
        $stmt->bind_param('si', $gm, $i);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    /**
     * Deletes a mapconfig
     *
     * @param string $map_id - id of mapconfig to delete
     * @return bool
     */
    function delMapconfig($map_id) {
        return $this->exec("DELETE FROM mapconfig WHERE id = ? LIMIT 1", array((int) $map_id)) === 1;
    }

    /**
     * Update a mapconfig. Returns true when the statement ran - MySQL reports 0 affected rows when you
     * save without changing anything, which used to be shown to the admin as a failure.
     */
    function editMapconfig($id, $mapname, $capturelimit, $g_suddendeath, $g_gear, $g_gravity, $g_friendlyfire, $startmessage, $skiprandom, $datelastadd, $timelimit, $gamemodes, $bot) {
        $query = "UPDATE mapconfig SET mapname = ?, capturelimit = ?, g_suddendeath = ?, g_gear = ? , g_gravity = ? , g_friendlyfire = ?, startmessage = ?, skiprandom = ?, datelastadd = ?, timelimit = ?, gamemodes = ?, bot = ? WHERE id = ? LIMIT 1";
        $stmt = $this->mysql->prepare($query);
        $stmt->bind_param('siisiisisisii', $mapname, $capturelimit, $g_suddendeath, $g_gear, $g_gravity, $g_friendlyfire, $startmessage, $skiprandom, $datelastadd, $timelimit, $gamemodes, $bot, $id);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    /**
     * Stamp "last added to mapcycle" with today's date
     */
    function datelastaddMapconfig($id) {
        return $this->exec("UPDATE mapconfig SET datelastadd = CURRENT_DATE() WHERE id = ? LIMIT 1", array((int) $id)) !== false;
    }

#############################
#############################
} // end class

/**
 * Exceptions class for the B3 DB (kept for plugins that still reference it)
 */
class MysqlException extends Exception {

    public function __construct($error, $errno) {
        echLog('mysql', $error, $errno, $this->getTraceAsString());
        parent::__construct($error, (int) $errno);
    }

}
