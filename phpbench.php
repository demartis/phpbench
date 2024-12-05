<?php

define('PHPBENCH_SCRIPT_VERSION', "1.0");
define('PHPBENCH_DEBUG', true);

if (PHP_MAJOR_VERSION < 5 || (PHP_MAJOR_VERSION === 5 && PHP_MINOR_VERSION < 6)) {
    echo 'This script requires PHP 5.6 or higher.';
    exit(1);
}

$defaultArgs = [
    // Increase the multiplier if you want to benchmark longer
    'multiplier' => 1.0,

    // default mysql args
    'mysql_host' => '127.0.0.1',
    'mysql_user' => null,
    'mysql_password' => null,
    'mysql_port' => 3306,
    'mysql_database' => 'phpbench',
    'mysql_table' => '_phpbench_test_' . generateRandomString(6),

    // default styles
    'output_width' => 55,
];

$args = array_merge($defaultArgs, get_args());

$benchmarks = [
    'core'  => get_benchmarks_core(),
    'io'    => get_benchmarks_io(),
    'rand'  => get_benchmarks_rand(),
    'mysql' => get_benchmarks_mysql(),
];

$isCli = PHP_SAPI === 'cli';
$multiplier = $args['multiplier'];
$extraLines = [];
$currentBenchmark = null;
$mtime = microtime(true);
// workaround for https://www.php.net/manual/en/datetime.createfromformat.php#128901
if (fmod($mtime, 1) === .0000) {
    $mtime += .0001;
}
$now = DateTime::createFromFormat('U.u', $mtime);

// mysql params
$initialRowCount = 1000;
$mysqli = null;
$dbName = null;


echo $isCli ? '' : '<pre>';
printSeparator();
printLine("PHPBENCH - PHP Benchmark tool", '', ' ',  STR_PAD_BOTH);
//printLine(sprintf("PHP BENCHMARK SCRIPT v.%s by @DeMartis", PHPBENCH_SCRIPT_VERSION), '', ' ',  STR_PAD_BOTH);
printSeparator();

printLine('Report generated at', $now->format('d/M/Y H:i:s T'));
printLine('Script version', PHPBENCH_SCRIPT_VERSION);

printTitle('PHP Info');
printLine('PHP', PHP_VERSION.' '. PHP_SAPI);
printLine('Platform', PHP_OS.' '.php_uname('m'));
if ($isCli) {
    printLine('Server', gethostname());
} else {
    $name = @$_SERVER['SERVER_NAME'] ?: 'null';
    $addr = @$_SERVER['SERVER_ADDR'] ?: 'null';
    printLine('Server', "{$name}@{$addr}");
}

printLine('Max memory usage',   ini_get('memory_limit'));
$opStatus = function_exists('opcache_get_status') ? opcache_get_status() : false;
printLine('OPCache',            is_array($opStatus) && @$opStatus['opcache_enabled'] ? 'enabled' : 'disabled');
printLine('OPCache JIT',        is_array($opStatus) && @$opStatus['jit']['enabled'] ? 'enabled' : 'disabled/unavailable');
printLine('PCRE JIT',           ini_get('pcre.jit') ? 'enabled' : 'disabled');
printLine('XDebug',             extension_loaded('xdebug') ? 'enabled' : 'disabled');


printTitle('Script config');
printLine('Difficulty multiplier', "{$multiplier}x");

// prepare mysql
$mysql_enabled = true;
try {
    setup_mysql();
}catch (Exception $e){
    $mysql_enabled = false;
    if (PHPBENCH_DEBUG){
        printLine ('Mysql Error', $e->getMessage());
    }
}
printLine('Mysql',                 $mysql_enabled ? "enabled v{$mysqli -> server_info}" : 'disabled');
if($mysql_enabled){
    printLine('Mysql DB',          $dbName);
}
printSeparator();

// run benchmarks
$stopwatch = new StopWatch();
foreach ($benchmarks as $type => $tests) {

    //printTitle($type);
    foreach ($tests as $name => $test) {
       $currentBenchmark = $name;
       $time = runBenchmark($stopwatch, $test, $multiplier);
       printLine("$type::$name", $time);

    }
}

// cleanup
if($mysql_enabled) {

    if (!empty($extraLines)) {
        printLine('Mysql query speeds', '', '-', STR_PAD_BOTH);
        foreach ($extraLines as $line) {
            printLine($line[0], $line[1]);
        }
    }
    cleanup_mysql();
}

printSeparator();
printLine('Total time', number_format($stopwatch->totalTime, 4) . ' s');
printLine('Peak memory usage', round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MiB');

printSeparator();

printLine('Thanks for using this script','',' ');
printLine('@author: Riccardo De Martis <riccardo@demartis.it>','',' ');
printLine('@link  : https://github.com/demartis/phpbench','',' ');
echo $isCli ? '' : '</pre>';


// classes

class StopWatch
{
    /**
     * @var float
     */
    public $totalTime = .0;

    private $start;


    /**
     * @return float
     */
    public function start()
    {
        return $this->start = self::time();
    }

    /**
     * @return float
     */
    public function stop()
    {
        $time = self::time() - $this->start;
        $this->totalTime += $time;

        return $time;
    }

    /**
     * @return float
     */
    public static function time()
    {
        return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);
    }
}

// functions

function runBenchmark($stopwatch, $benchmark, $multiplier = 1)
{
    $r = null;
    try {
        $stopwatch->start();
        $r = $benchmark($multiplier);
    } catch (Exception $e) {
        return 'ERROR: ' . $e->getMessage();
    } finally {
        $time = $stopwatch->stop();
    }

    if ($r === INF) {
        return 'SKIPPED';
    }

    return number_format($time, 4) . ' s';
}


function generateRandomString($length = 10) {
    return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
}

function get_args()
{
    $args = [];

    if (PHP_SAPI === 'cli') {
        $cleanedArgs = array_map(function ($arg) {
            return strpos($arg, '--') !== 0 ? null : str_replace('--', '', $arg);
        }, $GLOBALS['argv']);

        parse_str(implode('&', array_filter($cleanedArgs)), $args);
    } else {

        $args = [];
        // check if there are any DB config as Wordpress server env style
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, "MYSQLCONNSTR_") !== 0) {
                continue;
            }

            $args['mysql_host']     = preg_replace("/^.*Data Source=(.+?);.*$/", "\\1", $value);
            $args['mysql_database'] = preg_replace("/^.*Database=(.+?);.*$/", "\\1", $value);
            $args['mysql_user']     = preg_replace("/^.*User Id=(.+?);.*$/", "\\1", $value);
            $args['mysql_password'] = preg_replace("/^.*Password=(.+?)$/", "\\1", $value);
        }

        // any further Query string will override them
        if($_SERVER['QUERY_STRING']){

            $query_args = [];
            parse_str($_SERVER['QUERY_STRING'], $query_args);

            $args = array_merge($args, $query_args);
        }
    }

    return $args;
}

// ui tools

function printLine($str, $endStr = '', $pad = '.', $mode = STR_PAD_RIGHT)
{
    global $args;
    $lineWidth = $args['output_width'];

    $LF = PHP_SAPI === 'cli' ? PHP_EOL : '<br/>';

    if (!empty($endStr)) {
        $endStr = " $endStr";
    }
    $length = max(0, $lineWidth - strlen($endStr));
    echo str_pad($str, $length, $pad, $mode) . $endStr . $LF;
}


function printSeparator(){
    printLine('', '', '-');
}

function printTitle($str){

    printLine(' '.$str.' ', '', '-', STR_PAD_BOTH);
}

// tests
// PHP CORE benchmarks

function get_benchmarks_core(){

    /** @var array<string, callable> */
    return [
        'math' => function ($multiplier = 1, $count = 200000) {
            $x = 0;
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
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
                $i;
            }
            $i = 0;
            while ($i < $count) {
                ++$i;
            }
            return $i;
        },
        'ifelse' => function ($multiplier = 1, $count = 10000000) {
            $a = 0;
            $b = 0;
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
            $a = 0;
            $b = 0;
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
                array_keys($a);
                array_values($a);
                array_flip($a);
                array_map(function ($e) {
                }, $a);
                array_walk($a, function ($e, $i) {
                });
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
                preg_match("#http[s]?://\w+[^\s\[\]\<]+#",
                    'this is a link to https://google.com which is a really popular site');
                preg_replace("#(^|\s)(http[s]?://\w+[^\s\[\]\<]+)#i", '\1<a href="\2">\2</a>',
                    'this is a link to https://google.com which is a really popular site');
            }
            return $i;
        },
        'is_{type}' => function ($multiplier = 1, $count = 2500000) {
            $o = new stdClass();
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
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
                'foo' => 'bar',
                'bar' => 'baz',
                'baz' => 'qux',
                'qux' => 'quux',
                'quux' => 'corge',
                'corge' => 'grault',
                'grault' => 'garply',
                'garply' => 'waldo',
                'waldo' => 'fred',
                'fred' => 'plugh',
                'plugh' => 'xyzzy',
                'xyzzy' => 'thud',
                'thud' => 'end',
            ];
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                json_encode($data);
                json_decode(json_encode($data));
            }
            return $data;
        },
    ];
    return $benchmarks;
}

// IO
function get_benchmarks_io(){

    /** @var array<string, callable> */
    return [
        'file_read' => function($multiplier = 1, $count = 1000) {
            file_put_contents('test.txt', "test");
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                file_get_contents('test.txt');
            }
            unlink('test.txt');
            return $i;
        },
        'file_write' => function($multiplier = 1, $count = 1000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                file_put_contents('test.txt', "test $i");
            }
            unlink('test.txt');
            return $i;
        },
        'file_zip' => function($multiplier = 1, $count = 1000) {
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
        'file_unzip' => function($multiplier = 1, $count = 1000) {
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

// rand
function get_benchmarks_rand(){

    /** @var array<string, callable> */
    return [
        'rand' => function($multiplier = 1, $count = 1000000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                rand(0, $i);
            }
            return $i;
        },
        'mt_rand' => function($multiplier = 1, $count = 1000000) {
            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                mt_rand(0, $i);
            }
            return $i;
        },
        'random_int' => function($multiplier = 1, $count = 1000000) {
            if (!function_exists('random_int')) {
                return INF;
            }

            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                random_int(0, $i);
            }
            return $i;
        },
        'random_bytes' => function($multiplier = 1, $count = 1000000) {
            if (!function_exists('random_bytes')) {
                return INF;
            }

            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                random_bytes(32);
            }
            return $i;
        },
        'openssl_random_pseudo_bytes' => function($multiplier = 1, $count = 1000000) {
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

// mysql
function setup_mysql() {
    global $args, $mysqli, $initialRowCount, $dbName;

    $table_test_name = $args['mysql_table'];
    if (!extension_loaded('mysqli'))
        throw new RuntimeException('The mysqli extension is not loaded');

    if ($args['mysql_host'] === null || $args['mysql_user'] === null || $args['mysql_password'] === null)
        throw new RuntimeException('Missing: mysql_host, mysql_user, mysql_password');

    $mysqli = new mysqli($args['mysql_host'], $args['mysql_user'], $args['mysql_password'], null,
        isset($args['mysql_port']) ? $args['mysql_port'] : 3306);

    if ($mysqli->connect_error)
        throw new RuntimeException("Mysql Connect Error ({$mysqli->connect_errno}) {$mysqli->connect_error}");

    $dbName = isset($args['mysql_database']) ? $args['mysql_database'] : 'phpbench_test';

    // check if database exists
    $result = $mysqli->query("SELECT schema_name FROM information_schema.schemata WHERE schema_name = '$dbName'");

    // check if DB exists, otherwise try to create it
    if ($result->num_rows == 0) {
        $mysqli->query("CREATE DATABASE IF NOT EXISTS `$dbName`");
    }

    $mysqli->select_db($dbName);
    $mysqli->query("CREATE TABLE IF NOT EXISTS `$dbName`.`$table_test_name` (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255))");

    for ($i = 0; $i < $initialRowCount; $i++) {
        $values[] = "('test$i')";
    }
    $r = $mysqli->query("INSERT INTO `$dbName`.`$table_test_name` (name) VALUES " . implode(',', $values));
    if (!$r) {
        throw new RuntimeException("Mysql Error ({$mysqli->errno}) {$mysqli->error}" );
    }
}

function cleanup_mysql() {
    global $mysqli, $dbName, $args;
    if ($mysqli === null) {
        return;
    }
    $table_test_name = $args['mysql_table'];
    $mysqli->query("DROP TABLE IF EXISTS `$dbName`.`$table_test_name`");
    $mysqli->close();
};


function extraStat($unit, $value)
{
    global $extraLines, $currentBenchmark;
    $extraLines[] = [$currentBenchmark, "$value $unit"];
}


function get_benchmarks_mysql(){
    global $args, $mysqli;
    $table_test_name= $args['mysql_table'];

    /** @var array<string, callable> */
    return [
        'ping' => function () use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }
            $mysqli->ping();
            return 1;
        },
        'select_version' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->query("SELECT VERSION()");
            }

            extraStat('q/s', round($count / (StopWatch::time() - $time)));

            return $i;
        },
        'select_all' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->query("SELECT * FROM `$table_test_name`");
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));
            return $i;
        },
        'select_cursor' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $count = $count * $multiplier;
            for ($i = 0; $i < $count; $i++) {
                $result = $mysqli->query("SELECT * FROM `$table_test_name`");
                while ($row = $result->fetch_assoc()) {
                }
                $result->close();
            }
            return $i;
        },
        'seq_insert' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->query("INSERT INTO `$table_test_name` (name) VALUES ('test')");
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));
            return $i;
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

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->query("UPDATE `$table_test_name` SET name = 'test' WHERE id = '$i'");
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));
            return $i;
        },
        'update_with_index' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $mysqli->query("CREATE INDEX idx ON `$table_test_name` (id)");

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->query("UPDATE `$table_test_name` SET name = 'test' WHERE id = '$i'");
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));

            $mysqli->query("DROP INDEX idx ON `$table_test_name`");
            return $i;
        },
        'transaction_insert' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->begin_transaction();
                $mysqli->query("INSERT INTO `$table_test_name` (name) VALUES ('test')");
                $mysqli->commit();
            }
            extraStat('t/s', round($count / (StopWatch::time() - $time)));
            return $i;
        },
        'aes_encrypt' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $data = '';
            $stmt = $mysqli->prepare("SELECT AES_ENCRYPT(?, 'key')");
            $stmt->bind_param('s', $data);

            $data = str_repeat('a', 16);
            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $stmt->execute();
                $stmt->get_result()->fetch_assoc();
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));
            $stmt->close();
            return $i;
        },
        'aes_decrypt' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $data = '';
            $stmt = $mysqli->prepare("SELECT AES_DECRYPT(?, 'key')");
            $stmt->bind_param('s', $data);

            $data = str_repeat('a', 16);
            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $stmt->execute();
                $stmt->get_result()->fetch_assoc();
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));
            $stmt->close();
            return $i;
        },
        'indexes' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $mysqli->query("CREATE INDEX idx_name ON `$table_test_name` (name)");
            $mysqli->query("DROP INDEX idx_name ON `$table_test_name`");
            return 1;
        },
        'delete' => function ($multiplier = 1, $count = 1000) use (&$mysqli, $table_test_name) {
            if ($mysqli === null) {
                return INF;
            }

            $count = $count * $multiplier;
            $time = StopWatch::time();
            for ($i = 0; $i < $count; $i++) {
                $mysqli->query("DELETE FROM `$table_test_name` WHERE id = '$i'");
            }
            extraStat('q/s', round($count / (StopWatch::time() - $time)));
            return $i;
        },
    ];
}

