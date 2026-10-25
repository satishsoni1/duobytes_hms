<?php defined('BASEPATH') or exit('No direct script access allowed');																	   
	 
$query_builder = true;				  

$db['default'] = array(
    'dsn'          => '',
    // Connection details come from .env (see .env.example) - never commit them here.
    'hostname'     => env('DB_HOST', 'localhost'),
    'username'     => env('DB_USERNAME', ''),
    'password'     => env('DB_PASSWORD', ''),
    'database'     => env('DB_DATABASE', ''),
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
$branch_dir   = APPPATH . 'cache';
if (!is_dir($branch_dir)) {
    @mkdir($branch_dir, 0755, true);
}
if (!is_writable($branch_dir)) {
    $branch_dir = sys_get_temp_dir(); // cache folder missing after a git deploy, or not writable
}
$branch_cache = $branch_dir . '/sh_multi_branch_db_' . md5(APPPATH) . '.php';
$branch_rows  = null;
if (is_file($branch_cache) && filemtime($branch_cache) > time() - 600) {
    $branch_rows = include $branch_cache;
}

if (!is_array($branch_rows)) {
    $branch_rows = array();
    $mydb        = $db['default'];
    try {
        $mysqli = new mysqli($mydb['hostname'], $mydb["username"], $mydb["password"], $mydb["database"]);
        $db_error = $mysqli->connect_errno ? $mysqli->connect_error : '';
    } catch (mysqli_sql_exception $e) {
        $db_error = $e->getMessage();
    }

    if ($db_error !== '') {
        // Say why, without revealing credentials: most failures are a missing or unread .env.
        $hint = !ENV_FILE_LOADED ? ' (.env not found next to index.php)'
            : (env('DB_HOST') === NULL ? ' (.env found but DB_HOST is not set)' : '');
        printf("connection failed: %s%s\n", $db_error, $hint);
        exit();
    }

    try {
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
    } catch (mysqli_sql_exception $e) {
        log_message('error', 'multi_branch lookup failed: ' . $e->getMessage());
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