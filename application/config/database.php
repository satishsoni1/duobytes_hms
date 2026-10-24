<?php defined('BASEPATH') or exit('No direct script access allowed');																	   
	 
$query_builder = true;				  

$db['default'] = array(
    'dsn'          => '',
    // Connection details come from .env (see .env.example) - never commit them here.
    'hostname'     => getenv('DB_HOST') ?: 'localhost',
    'username'     => getenv('DB_USERNAME') ?: '',
    'password'     => getenv('DB_PASSWORD') ?: '',
    'database'     => getenv('DB_DATABASE') ?: '',
    'dbdriver'     => 'mysqli',
    'dbprefix'     => '',
    'pconnect'     => false,
    'db_debug'     => (ENVIRONMENT !== 'production'),
    'cache_on'     => false,
    'cachedir'     => '',
    'char_set'     => 'utf8',
    'dbcollat'     => 'utf8_general_ci',
    'swap_pre'     => '',
    'encrypt'      => false,
    'compress'     => false,
    'stricton'     => false,
    'failover'     => array(),
    'save_queries' => true,
    'multi_branch' => false,
);

$active_group = 'default';

// Branch databases come from the multi_branch table. Looking them up needs its own
// connection, so the result is cached for 10 minutes instead of querying on every
// request (shared hosting caps connections per hour).
$branch_cache = APPPATH . 'cache/multi_branch_db.php';
$branch_rows  = null;
if (is_file($branch_cache) && filemtime($branch_cache) > time() - 600) {
    $branch_rows = include $branch_cache;
}

if (!is_array($branch_rows)) {
    $branch_rows = array();
    $mydb        = $db['default'];
    $mysqli      = new mysqli($mydb['hostname'], $mydb["username"], $mydb["password"], $mydb["database"]);

    if ($mysqli->connect_errno) {
        printf("connection failed: %s\n", $mysqli->connect_error);
        exit();
    }

    if ($results = $mysqli->query("SHOW TABLES LIKE 'multi_branch'")) {
        if ($results->num_rows == 1) {
            if ($result = $mysqli->query("SELECT * FROM multi_branch where is_verified =1")) {
                while ($row = $result->fetch_assoc()) {
                    $branch_rows[] = array(
                        'id'            => $row['id'],
                        'hostname'      => $row['hostname'],
                        'username'      => $row['username'],
                        'password'      => $row['password'],
                        'database_name' => $row['database_name'],
                    );
                }
            }
        }
    }
    $mysqli->close();

    @file_put_contents($branch_cache, "<?php defined('BASEPATH') or exit('No direct script access allowed');\nreturn " . var_export($branch_rows, true) . ";\n", LOCK_EX);
}

foreach ($branch_rows as $row) {
    $short_name                      = "branch_" . $row['id'];
    $db[$short_name]['hostname']     = $row['hostname'];
    $db[$short_name]['username']     = $row['username'];
    $db[$short_name]['password']     = $row['password'];
    $db[$short_name]['database']     = $row['database_name'];
    $db[$short_name]['dbdriver']     = 'mysqli';
    $db[$short_name]['dbprefix']     = '';
    $db[$short_name]['pconnect']     = false;
    $db[$short_name]['db_debug']     = false;
    $db[$short_name]['cache_on']     = false;
    $db[$short_name]['cachedir']     = '';
    $db[$short_name]['char_set']     = 'utf8';
    $db[$short_name]['dbcollat']     = 'utf8_general_ci';
    $db[$short_name]['swap_pre']     = '';
    $db[$short_name]['autoinit']     = false;
    $db[$short_name]['stricton']     = false;
    $db[$short_name]['multi_branch'] = true;
}