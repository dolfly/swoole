--TEST--
swoole_curl/multi: Bug #76675 (Segfault with H2 server push write/writeheader handlers)
--SKIPIF--
<?php 
require __DIR__ . '/../../include/skipif.inc';
skip_if_no_database();
if (getenv("SKIP_ONLINE_TESTS")) {
    die("skip online test");
}
$curl_version = curl_version();
if ($curl_version['version_number'] < 0x073d00) {
    exit("skip: test may crash with curl < 7.61.0");
}
?>
--FILE--
<?php
require __DIR__ . '/../../include/bootstrap.php';
use Swoole\Runtime;

use function Swoole\Coroutine\run;

$fn = function() {
    $transfers = 1;
    $callback = function($parent, $passed) use (&$transfers) {
        curl_setopt($passed, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo "Received ".strlen($data);
            return strlen($data);
        });
        $transfers++;
        return CURL_PUSH_OK;
    };
    $mh = curl_multi_init();
    curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
    curl_multi_setopt($mh, CURLMOPT_PUSHFUNCTION, $callback);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, TEST_HTTP2_SERVERPUSH_URL);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_multi_add_handle($mh, $ch);
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        phpt_echo("active=$active, status=$status\n");
        do {
            $info = curl_multi_info_read($mh);
            phpt_echo($info);
            if (false !== $info && $info['msg'] == CURLMSG_DONE) {
                $handle = $info['handle'];
                if ($handle !== null) {
                    $transfers--;
                    curl_multi_remove_handle($mh, $handle);
                    curl_close($handle);
                }
            }
        } while ($info);
        curl_multi_select($mh);
    } while ($transfers);
    curl_multi_close($mh);
};

if (swoole_array_default_value($argv, 1) == 'ori') {
    $fn();
} else {
    Runtime::enableCoroutine(SWOOLE_HOOK_NATIVE_CURL);
    run($fn);
}
?>
--EXPECTREGEX--
(Received \d+)+
