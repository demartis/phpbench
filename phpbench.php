<?php
/*
 * PHPBENCH - PHP Benchmark Tool
 *
 * Usage:
 * Run this script from the CLI or via a web server to benchmark various aspects
 * of your PHP environment: core operations, I/O, random functions, and MySQL queries.
 *
 * Command line arguments can be passed as `--key=value`:
 *   php phpbench.php --multiplier=2 --mysql_host=127.0.0.1 --mysql_user=root --mysql_password=secret
 *
 * If run via a web server, you can pass query strings like:
 *   http://example.com/phpbench.php?multiplier=2
 *
 * add any of the wanted $default_args params, e.g:
 *   http://example.com/phpbench.php?multiplier=2&mysql_host=127.0.0.1&mysql_user=root&mysql_password=secret
 *
 * Requirements:
 * - PHP 5.6 or higher
 * - Optional: A MySQL/MariaDB server and mysqli extension for MySQL benchmarks.
 */

define('PHPBENCH_SCRIPT_VERSION', "1.1");
define('PHPBENCH_DEBUG', true);  // set to true for more debugging data
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
//ini_set('memory_limit', 128M);

// To enable MySQL with SSL, uncomment and define as needed:
// define('DB_SSL', false );
// define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
// define('MYSQL_CLIENT_FLAGS', MYSQLI_CLIENT_SSL);
// define('MYSQL_SSL_CERT', 'DigiCertGlobalRootCA.crt.pem');

if (PHP_MAJOR_VERSION < 5 || (PHP_MAJOR_VERSION === 5 && PHP_MINOR_VERSION < 6)) {
    echo 'This script requires PHP 5.6 or higher.';
    exit(1);
}

// Default arguments
$default_args = [
    // Increase the multiplier if you want to benchmark longer
    'multiplier'     => 1.0,
    'mysql_host'     => '127.0.0.1',
    'mysql_user'     => null,
    'mysql_password' => null,
    'mysql_port'     => 3306,
    'mysql_database' => 'phpbench',
    'mysql_table'    => '_phpbench_test_' . generate_random_string(6),
    'mysql_socket'   => null,
    'output_width'   => 55,
];

$args = array_merge($default_args, get_args());

// Benchmark sets
$benchmarks = [
    'core'  => get_benchmarks_core(),
    'io'    => get_benchmarks_io(),
    'rand'  => get_benchmarks_rand(),
    'mysql' => get_benchmarks_mysql(),
];

// Environment and initial setup
$is_cli             = (PHP_SAPI === 'cli');
$multiplier         = $args['multiplier'];
$extra_lines        = [];
$current_benchmark  = null;
$mtime              = microtime(true);

// Workaround for DateTime fractional seconds issue
// https://www.php.net/manual/en/datetime.createfromformat.php#128901

if (fmod($mtime, 1) === .0000) {
    $mtime += .0001;
}
$now = DateTime::createFromFormat('U.u', $mtime);

// MySQL parameters
$initial_row_count = 1000;
$mysqli            = null;
$db_name           = null;

// Output header
echo $is_cli ? '' : '<pre>';
print_separator();
print_line("PHPBENCH - PHP Benchmark tool", '', ' ', STR_PAD_BOTH);
print_separator();

// Print environment info
print_environment_info($now);

// Print script config info
print_title('Script config');
print_line('Difficulty multiplier', "{$multiplier}x");

// Setup MySQL if possible
$mysql_enabled = handle_mysql_setup();

// Run all benchmarks
$stopwatch = new StopWatch();
run_all_benchmarks($benchmarks, $stopwatch, $multiplier);

// Cleanup MySQL if enabled
if ($mysql_enabled) {
    print_mysql_query_speeds($extra_lines);
    cleanup_mysql();
}

// Print final stats and close
print_final_stats($stopwatch);
echo $is_cli ? '' : '</pre>';


/**
 * Print environment info such as date, PHP version, server info, and extensions.
 *
 * @param DateTime $now Current time object
 * @return void
 */
function print_environment_info($now)
{
    print_line('Report generated at', $now->format('d/M/Y H:i:s T'));
    print_line('Script version', PHPBENCH_SCRIPT_VERSION);

    print_title('PHP Info');
    print_line('PHP', PHP_VERSION . ' ' . PHP_SAPI);
    print_line('Platform', PHP_OS . ' ' . php_uname('m'));

    if (PHP_SAPI === 'cli') {
        print_line('Server', gethostname());
    } else {
        $name = @$_SERVER['SERVER_NAME'] ?: 'null';
        $addr = @$_SERVER['SERVER_ADDR'] ?: 'null';
        print_line('Server', "{$name}@{$addr}");
    }

    print_line('Max memory usage', ini_get('memory_limit'));

    $op_status = function_exists('opcache_get_status') ? opcache_get_status() : false;
    print_line('OPCache', is_array($op_status) && @$op_status['opcache_enabled'] ? 'enabled' : 'disabled');
    print_line('OPCache JIT', is_array($op_status) && @$op_status['jit']['enabled'] ? 'enabled' : 'disabled/unavailable');
    print_line('PCRE JIT', ini_get('pcre.jit') ? 'enabled' : 'disabled');
    print_line('XDebug', extension_loaded('xdebug') ? 'enabled' : 'disabled');
}


/**
 * Try to setup MySQL connection and environment if possible.
 *
 * @return bool Whether MySQL is enabled or not
 */
function handle_mysql_setup()
{
    global $mysqli, $db_name, $args;

    $mysql_enabled = true;
    try {
        setup_mysql();
    } catch (Exception $e) {
        $mysql_enabled = false;
        if (PHPBENCH_DEBUG) {
            print_line('Mysql Error', $e->getMessage());
        }
    }

    print_line('Mysql', $mysql_enabled ? "enabled v{$mysqli->server_info}" : 'disabled');
    if ($mysql_enabled) {
        print_line('Mysql DB', $db_name);
    }
    print_separator();

    return $mysql_enabled;
}

/**
 * Run all benchmarks in each category using a stopwatch to measure times.
 *
 * @param array     $benchmarks The benchmarks array
 * @param StopWatch $stopwatch  The stopwatch object
 * @param float     $multiplier The multiplier for difficulty
 * @return void
 */
function run_all_benchmarks($benchmarks, $stopwatch, $multiplier)
{
    global $current_benchmark;
    foreach ($benchmarks as $type => $tests) {
        foreach ($tests as $name => $test) {
            $current_benchmark = $name;
            $time = run_benchmark($stopwatch, $test, $multiplier);
            print_line("$type::$name", $time);
        }
    }
}

/**
 * Print MySQL query speed results if any.
 *
 * @param array $extra_lines Additional lines collected during benchmarks
 * @return void
 */
function print_mysql_query_speeds($extra_lines)
{
    if (!empty($extra_lines)) {
        print_line('Mysql query speeds', '', '-', STR_PAD_BOTH);
        foreach ($extra_lines as $line) {
            print_line($line[0], $line[1]);
        }
    }
}

/**
 * Print final stats such as total execution time and peak memory usage.
 *
 * @param StopWatch $stopwatch The stopwatch to read total time from
 * @return void
 */
function print_final_stats($stopwatch)
{
    print_separator();
    print_line('Total time', number_format($stopwatch->total_time, 4) . ' s');
    print_line('Peak memory usage', round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MiB');
    print_separator();
    print_line('Thanks for using this script', '', ' ');
    print_line('@author: Riccardo De Martis <riccardo@demartis.it>', '', ' ');
    print_line('@link  : https://github.com/demartis/phpbench', '', ' ');
}


//------------------------------------
// Classes
//------------------------------------

class StopWatch
{
    public $total_time = 0.0;
    private $start_time;

    /**
     * Start the timer and return start time.
     * @return float
     */
    public function start_timer()
    {
        return $this->start_time = self::get_time();
    }

    /**
     * Stop the timer and return elapsed time.
     * @return float
     */
    public function stop_timer()
    {
        $time = self::get_time() - $this->start_time;
        $this->total_time += $time;
        return $time;
    }

    /**
     * Get current high resolution time.
     * @return float
     */
    public static function get_time()
    {
        return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);
    }
}


//------------------------------------
// Core Functions
//------------------------------------

/**
 * Run a single benchmark with timing from the stopwatch.
 *
 * @param StopWatch $stopwatch
 * @param callable  $benchmark
 * @param float     $multiplier
 * @return string   The formatted benchmark result or an error
 */
function run_benchmark($stopwatch, $benchmark, $multiplier = 1)
{
    $r = null;
    try {
        $stopwatch->start_timer();
        $r = $benchmark($multiplier);
    } catch (Exception $e) {
        return 'ERROR: ' . $e->getMessage();
    } finally {
        $time = $stopwatch->stop_timer();
    }

    if ($r === INF) {
        return 'SKIPPED';
    }

    return number_format($time, 4) . ' s';
}

/**
 * Generate a random string, used for table naming etc.
 *
 * @param int $length
 * @return string
 */
function generate_random_string($length = 10)
{
    return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
}

/**
 * Retrieve arguments from CLI or server environment.
 *
 * @return array
 */
function get_args()
{
    $args = [];

    if (PHP_SAPI === 'cli') {
        $cleaned_args = array_map(function ($arg) {
            return strpos($arg, '--') !== 0 ? null : str_replace('--', '', $arg);
        }, $GLOBALS['argv']);
        parse_str(implode('&', array_filter($cleaned_args)), $args);
    } else {
        // Attempt DB config from environment variables
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, "MYSQLCONNSTR_") !== 0) {
                continue;
            }
            $args['mysql_host']     = preg_replace("/^.*Data Source=(.+?);.*$/", "\\1", $value);
            $args['mysql_database'] = preg_replace("/^.*Database=(.+?);.*$/", "\\1", $value);
            $args['mysql_user']     = preg_replace("/^.*User Id=(.+?);.*$/", "\\1", $value);
            $args['mysql_password'] = preg_replace("/^.*Password=(.+?)$/", "\\1", $value);
        }

        // Query strings override env settings
        if ($_SERVER['QUERY_STRING']) {
            $query_args = [];
            parse_str($_SERVER['QUERY_STRING'], $query_args);
            $args = array_merge($args, $query_args);
        }
    }

    return $args;
}


//------------------------------------
// UI Tools
//------------------------------------

/**
 * Print a line padded according to the given width.
 *
 * @param string $str
 * @param string $end_str
 * @param string $pad
 * @param int    $mode
 * @return void
 */
function print_line($str, $end_str = '', $pad = '.', $mode = STR_PAD_RIGHT)
{
    global $args;
    $line_width = $args['output_width'];
    $lf = PHP_SAPI === 'cli' ? PHP_EOL : '<br/>';

    if (!empty($end_str)) {
        $end_str = " $end_str";
    }
    $length = max(0, $line_width - strlen($end_str));
    echo str_pad($str, $length, $pad, $mode) . $end_str . $lf;
}

/**
 * Print a separator line.
 *
 * @return void
 */
function print_separator()
{
    print_line('', '', '-');
}

/**
 * Print a title line.
 *
 * @param string $str
 * @return void
 */
function print_title($str)
{
    print_line(' ' . $str . ' ', '', '-', STR_PAD_BOTH);
}


//------------------------------------
// Benchmarks: Core, IO, Rand, MySQL
//------------------------------------

function get_benchmarks_core()
{
    return [
        'math' => function ($multiplier = 1, $count = 200000) {
            $x = 0;
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                // Perform various math and function calls to stress CPU
                $x += $i + $i;
                $x += $i * $i;
                $x += $i ** $i;
                $x += $i / (($i + 1) * 2);
                $x += $i % (($i + 1) * 2);
                abs($i);
                acos($i);
                acosh($i);
                asin($i);
                asinh($i);
                atan2($i, $i);
                atan($i);
                atanh($i);
                ceil($i);
                cos($i);
                cosh($i);
                decbin($i);
                dechex($i);
                decoct($i);
                deg2rad($i);
                exp($i);
                expm1($i);
                floor($i);
                fmod($i, $i);
                hypot($i, $i);
                is_infinite($i);
                is_finite($i);
                is_nan($i);
                log10($i);
                log1p($i);
                log($i);
                pi();
                pow($i, $i);
                rad2deg($i);
                sin($i);
                sinh($i);
                sqrt($i);
                tan($i);
                tanh($i);
            }
            return $i;
        },
        'loops' => function ($multiplier = 1, $count = 20000000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; ++$i) {
                // Simple for loop
                $i;
            }
            $i = 0;
            while ($i < $count) {
                ++$i;
            }
            return $i;
        },
        'ifelse' => function ($multiplier = 1, $count = 10000000) {
            $a = 0; $b = 0;
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                $k = $i % 4;
                if ($k === 0) {
                    $i;
                } elseif ($k === 1) {
                    $a = $i;
                } elseif ($k === 2) {
                    $b = $i;
                } else {
                    $i;
                }
            }
            return $a - $b;
        },
        'switch' => function ($multiplier = 1, $count = 10000000) {
            $a = 0; $b = 0;
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                switch ($i % 4) {
                    case 0:
                        $i;
                        break;
                    case 1:
                        $a = $i;
                        break;
                    case 2:
                        $b = $i;
                        break;
                    default:
                        break;
                }
            }
            return $a - $b;
        },
        'string' => function ($multiplier = 1, $count = 50000) {
            $string = '<i>the</i> quick brown fox jumps over the lazy dog  ';
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                // Various string functions
                addslashes($string);
                bin2hex($string);
                chunk_split($string);
                convert_uudecode(convert_uuencode($string));
                count_chars($string);
                explode(' ', $string);
                htmlentities($string);
                md5($string);
                metaphone($string);
                ord($string);
                rtrim($string);
                sha1($string);
                soundex($string);
                str_getcsv($string);
                str_ireplace('fox', 'cat', $string);
                str_pad($string, 50);
                str_repeat($string, 10);
                str_replace('fox', 'cat', $string);
                str_rot13($string);
                str_shuffle($string);
                str_word_count($string);
                strip_tags($string);
                strpos($string, 'fox');
                strlen($string);
                strtolower($string);
                strtoupper($string);
                substr_count($string, 'the');
                trim($string);
                ucfirst($string);
                ucwords($string);
            }
            return $string;
        },
        'array' => function ($multiplier = 1, $count = 20000) {
            $a = range(0, 100);
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                // Array manipulation
                array_keys($a);
                array_values($a);
                array_flip($a);
                array_map(function ($e) {}, $a);
                array_walk($a, function ($e, $i) {});
                array_reverse($a);
                array_sum($a);
                array_merge($a, [101, 102, 103]);
                array_replace($a, [1, 2, 3]);
                array_chunk($a, 2);
            }
            return $a;
        },
        'regex' => function ($multiplier = 1, $count = 1000000) {
            for ($i = 0; $i < $count * $multiplier; $i++) {
                preg_match("#http[s]?://\w+[^\s\[\]\<]+#", 'this is a link to https://google.com');
                preg_replace("#(^|\s)(http[s]?://\w+[^\s\[\]\<]+)#i", '\1<a href="\2">\2</a>', 'this is a link to https://google.com');
            }
            return $i;
        },
        'is_{type}' => function ($multiplier = 1, $count = 2500000) {
            $o = new stdClass();
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                // Type checks
                is_array([1]);
                is_array('1');
                is_int(1);
                is_int('abc');
                is_string('foo');
                is_string(123);
                is_bool(true);
                is_bool(5);
                is_numeric('hi');
                is_numeric('123');
                is_float(1.3);
                is_float(0);
                is_object($o);
                is_object('hi');
            }
            return $o;
        },
        'hash' => function ($multiplier = 1, $count = 10000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                // Hashing functions
                md5($i);
                sha1($i);
                hash('sha256', $i);
                hash('sha512', $i);
                hash('ripemd160', $i);
                hash('crc32', $i);
                hash('crc32b', $i);
                hash('adler32', $i);
                hash('fnv132', $i);
                hash('fnv164', $i);
                hash('joaat', $i);
                hash('haval128,3', $i);
                hash('haval160,3', $i);
                hash('haval192,3', $i);
                hash('haval224,3', $i);
                hash('haval256,3', $i);
                hash('haval128,4', $i);
                hash('haval160,4', $i);
                hash('haval192,4', $i);
                hash('haval224,4', $i);
                hash('haval256,4', $i);
                hash('haval128,5', $i);
                hash('haval160,5', $i);
                hash('haval192,5', $i);
                hash('haval224,5', $i);
                hash('haval256,5', $i);
            }
            return $i;
        },
        'json' => function ($multiplier = 1, $count = 100000) {
            $data = [
                'foo'   => 'bar',
                'bar'   => 'baz',
                'baz'   => 'qux',
                'qux'   => 'quux',
                'quux'  => 'corge',
                'corge' => 'grault',
                'grault'=> 'garply',
                'garply'=> 'waldo',
                'waldo' => 'fred',
                'fred'  => 'plugh',
                'plugh' => 'xyzzy',
                'xyzzy' => 'thud',
                'thud'  => 'end',
            ];
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                json_encode($data);
                json_decode(json_encode($data));
            }
            return $data;
        },
    ];
}

function get_benchmarks_io()
{
    return [
        'file_read' => function ($multiplier = 1, $count = 1000) {
            file_put_contents('test.txt', "test");
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                file_get_contents('test.txt');
            }
            unlink('test.txt');
            return $i;
        },
        'file_write' => function ($multiplier = 1, $count = 1000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                file_put_contents('test.txt', "test $i");
            }
            unlink('test.txt');
            return $i;
        },
        'file_zip' => function ($multiplier = 1, $count = 1000) {
            file_put_contents('test.txt', "test");
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                $zip = new ZipArchive();
                $zip->open('test.zip', ZipArchive::CREATE);
                $zip->addFile('test.txt');
                $zip->close();
            }
            unlink('test.txt');
            unlink('test.zip');
            return $i;
        },
        'file_unzip' => function ($multiplier = 1, $count = 1000) {
            file_put_contents('test.txt', "test");
            $zip = new ZipArchive();
            $zip->open('test.zip', ZipArchive::CREATE);
            $zip->addFile('test.txt');
            $zip->close();
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                $zip = new ZipArchive();
                $zip->open('test.zip');
                $zip->extractTo('test');
                $zip->close();
            }
            unlink('test.txt');
            unlink('test.zip');
            unlink('test/test.txt');
            rmdir('test');
            return $i;
        },
    ];
}

function get_benchmarks_rand()
{
    return [
        'rand' => function ($multiplier = 1, $count = 1000000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                rand(0, $i);
            }
            return $i;
        },
        'mt_rand' => function ($multiplier = 1, $count = 1000000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                mt_rand(0, $i);
            }
            return $i;
        },
        'random_int' => function ($multiplier = 1, $count = 1000000) {
            if (!function_exists('random_int')) {
                return INF;
            }
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                random_int(0, $i);
            }
            return $i;
        },
        'random_bytes' => function ($multiplier = 1, $count = 1000000) {
            if (!function_exists('random_bytes')) {
                return INF;
            }
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                random_bytes(32);
            }
            return $i;
        },
        'openssl_random_pseudo_bytes' => function ($multiplier = 1, $count = 1000000) {
            if (!function_exists('openssl_random_pseudo_bytes')) {
                return INF;
            }
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                openssl_random_pseudo_bytes(32);
            }
            return $i;
        },
    ];
}


//------------------------------------
// MySQL Setup and Cleanup
//------------------------------------

function setup_mysql()
{
    global $args, $mysqli, $initial_row_count, $db_name;

    $table_test_name = $args['mysql_table'];
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('The mysqli extension is not loaded');
    }

    if ($args['mysql_host'] === null || $args['mysql_user'] === null || $args['mysql_password'] === null) {
        throw new RuntimeException('Missing: mysql_host, mysql_user, mysql_password');
    }

    $client_flags = defined('MYSQL_CLIENT_FLAGS') ? MYSQL_CLIENT_FLAGS : 0;

    $mysqli = mysqli_init();
    if (PHPBENCH_DEBUG) {
        mysqli_real_connect($mysqli, $args['mysql_host'], $args['mysql_user'], $args['mysql_password'],
            null, $args['mysql_port'], $args['mysql_socket'], $client_flags);
    } else {
        @mysqli_real_connect($mysqli, $args['mysql_host'], $args['mysql_user'], $args['mysql_password'],
            null, $args['mysql_port'], $args['mysql_socket'], $client_flags);
    }

    if ($mysqli->connect_error) {
        throw new RuntimeException("Mysql Connect Error ({$mysqli->connect_errno}) {$mysqli->connect_error}");
    }

    $db_name = isset($args['mysql_database']) ? $args['mysql_database'] : 'phpbench_test';

    // Check if database exists
    $result = $mysqli->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$db_name'");
    if ($result->num_rows == 0) {
        $mysqli->query("CREATE DATABASE IF NOT EXISTS `$db_name`");
    }

    $mysqli->select_db($db_name);
    $mysqli->query("CREATE TABLE IF NOT EXISTS `$db_name`.`$table_test_name` (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))");

    $values = [];
    for ($i = 0; $i < $initial_row_count; $i++) {
        $values[] = "('test$i')";
    }
    $r = $mysqli->query("INSERT INTO `$db_name`.`$table_test_name` (name) VALUES " . implode(',', $values));
    if (!$r) {
        throw new RuntimeException("Mysql Error ({$mysqli->errno}) {$mysqli->error}");
    }
}

function cleanup_mysql()
{
    global $mysqli, $db_name, $args;
    if ($mysqli === null) {
        return;
    }
    $table_test_name = $args['mysql_table'];
    $mysqli->query("DROP TABLE IF EXISTS `$db_name`.`$table_test_name`");
    $mysqli->close();
}

function extra_stat($unit, $value)
{
    global $extra_lines, $current_benchmark;
    $extra_lines[] = [$current_benchmark, "$value $unit"];
}

/**
 * Helper function to run a MySQL benchmark that repeats an action $count times,
 * measures the time, and reports q/s or another metric.
 *
 * @param int      $multiplier Multiplying factor for iteration count
 * @param int      $count      Base count of operations
 * @param callable $callback   Function to run in each iteration
 * @param string   $stat_unit  Unit for extra_stat
 * @return int     Last iteration count
 */
function run_mysql_iterations($multiplier, $count, $callback, $stat_unit = 'q/s')
{
    $count = $count * $multiplier;
    $time = StopWatch::get_time();
    for ($i = 0; $i < $count; $i++) {
        $callback($i);
    }
    extra_stat($stat_unit, round($count / (StopWatch::get_time() - $time)));
    return $i;
}

function get_benchmarks_mysql()
{
    global $args, $mysqli;
    $table_test_name = $args['mysql_table'];

    return [
        'ping' => function () use (&$mysqli) {
            if ($mysqli === null) {
                return INF;
            }
            $mysqli->ping();
            return 1;
        },

        'select_version' => function ($multiplier = 1, $count = 1000) use (&$mysqli) {
            if ($mysqli === null) {
                return INF;
            }
            return run_mysql_iterations($multiplier, $count, function() use ($mysqli) {
                $mysqli->query("SELECT VERSION()");
            }, 'q/s');
        },

        'select_all' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            return run_mysql_iterations($multiplier, $count, function() use ($mysqli, $table_test_name) {
                $mysqli->query("SELECT * FROM `$table_test_name`");
            }, 'q/s');
        },

        'select_cursor' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                $result = $mysqli->query("SELECT * FROM `$table_test_name`");
                while ($row = $result->fetch_assoc()) {
                    // reading rows
                }
                $result->close();
            }
            return $i;
        },

        'seq_insert' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            return run_mysql_iterations($multiplier, $count, function() use ($mysqli, $table_test_name) {
                $mysqli->query("INSERT INTO `$table_test_name` (name) VALUES ('test')");
            }, 'q/s');
        },

        'bulk_insert' => function ($multiplier = 1, $count = 100000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            $count = $count * $multiplier;
            $values = [];
            for ($i = 0; $i < $count; $i++) {
                $values[] = "('test$i')";
            }
            $mysqli->query("INSERT INTO `$table_test_name` (name) VALUES " . implode(',', $values));
            return $i;
        },

        'update' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            return run_mysql_iterations($multiplier, $count, function($i) use ($mysqli, $table_test_name) {
                $mysqli->query("UPDATE `$table_test_name` SET name = 'test' WHERE id = '$i'");
            }, 'q/s');
        },

        'update_with_index' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            $mysqli->query("CREATE INDEX idx ON `$table_test_name` (id)");
            $r = run_mysql_iterations($multiplier, $count, function($i) use ($mysqli, $table_test_name) {
                $mysqli->query("UPDATE `$table_test_name` SET name = 'test' WHERE id = '$i'");
            }, 'q/s');
            $mysqli->query("DROP INDEX idx ON `$table_test_name`");
            return $r;
        },

        'transaction_insert' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            $count = $count * $multiplier;
            $time = StopWatch::get_time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->begin_transaction();
                $mysqli->query("INSERT INTO `$table_test_name` (name) VALUES ('test')");
                $mysqli->commit();
            }
            extra_stat('t/s', round($count / (StopWatch::get_time() - $time)));
            return $i;
        },

        'aes_encrypt' => function ($multiplier = 1, $count = 1000) use (&$mysqli) {
            if ($mysqli === null) {
                return INF;
            }
            $data = '';
            $stmt = $mysqli->prepare("SELECT AES_ENCRYPT(?, 'key')");
            $stmt->bind_param('s', $data);
            $data = str_repeat('a', 16);

            $count = $count * $multiplier;
            $time = StopWatch::get_time();
            for ($i = 0; $i < $count; $i++) {
                $stmt->execute();
                $stmt->get_result()->fetch_assoc();
            }
            extra_stat('q/s', round($count / (StopWatch::get_time() - $time)));
            $stmt->close();
            return $i;
        },

        'aes_decrypt' => function ($multiplier = 1, $count = 1000) use (&$mysqli) {
            if ($mysqli === null) {
                return INF;
            }
            $data = '';
            $stmt = $mysqli->prepare("SELECT AES_DECRYPT(?, 'key')");
            $stmt->bind_param('s', $data);
            $data = str_repeat('a', 16);

            $count = $count * $multiplier;
            $time = StopWatch::get_time();
            for ($i = 0; $i < $count; $i++) {
                $stmt->execute();
                $stmt->get_result()->fetch_assoc();
            }
            extra_stat('q/s', round($count / (StopWatch::get_time() - $time)));
            $stmt->close();
            return $i;
        },

        'indexes' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            // Just create and drop an index, no timing or q/s reporting here as it's a single operation
            $mysqli->query("CREATE INDEX idx_name ON `$table_test_name` (name)");
            $mysqli->query("DROP INDEX idx_name ON `$table_test_name`");
            return 1;
        },

        'delete' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            return run_mysql_iterations($multiplier, $count, function($i) use ($mysqli, $table_test_name) {
                $mysqli->query("DELETE FROM `$table_test_name` WHERE id = '$i'");
            }, 'q/s');
        },
    ];
}
