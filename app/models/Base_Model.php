<?php
require_once __DIR__ . "/../core/config.php";

class Base_Model {
    private static ?mysqli $shared = null;
    public $connection;

    public function __construct() {
        if (self::$shared === null) {
            $c = mysqli_init();
            if (!$c->real_connect(DBSERVER, DBUSER, DBPASS, DBNAME, null, null, MYSQLI_CLIENT_FOUND_ROWS)) {
                die("Connection failed: " . mysqli_connect_error());
            }
            $c->set_charset("utf8mb4");

            // Match PHP's Asia/Manila timezone so NOW()/CURDATE() in queries
            // agree with PHP's date()/time() calls (e.g. Ingest.php's date('Y-m-d H:i:s')).
            $c->query("SET time_zone = '+08:00'");

            self::$shared = $c;
        }
        $this->connection = self::$shared;
    }

    // No __destruct on purpose: closing here would kill the shared connection
    // for every other model. PHP closes it automatically when the request ends.
}
?>