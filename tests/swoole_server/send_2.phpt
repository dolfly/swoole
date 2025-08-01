--TEST--
swoole_server: send big packet [2]
--SKIPIF--
<?php
require __DIR__ . '/../include/skipif.inc';
?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';

const VERBOSE = FALSE;
const SEND_N = 32;
const CONCURRENCY = 16;
const PKT_MIN_SIZE = 256 * 1024;
const PKT_MAX_SIZE = 1024 * 1024;

$pm = new SwooleTest\ProcessManager;

$pm->parentFunc = function ($pid) use ($pm) {
    $total = 0;
    for ($i = 0; $i < CONCURRENCY; $i++) {
        go(function () use ($pm, $i, &$total) {
            $cli = new Co\Client(SWOOLE_SOCK_TCP);
            $cli->set([
                'open_length_check' => true,
                'package_max_length' => 4 * 1024 * 1024,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
            ]);
            if ($cli->connect('127.0.0.1', $pm->getFreePort(), 100) == false) {
                echo "ERROR\n";
                return;
            }
            $n = SEND_N;
            while ($n--) {
                $data = $cli->recv();
                Assert::assert($data);
                $char = chr(ord('A') + $n % 10);
                $info = unpack('Nlen', substr($data, 0, 4));
                if (VERBOSE) {
                    echo "[Client] c=$i, n=$n, len={$info['len']}\n---------------------------------------------------------------------\n";
                }
                Assert::same($info['len'], strlen($data) - 4);
                Assert::same(str_repeat($char, 1024), substr($data, rand(4, $info['len'] - 1024 - 4), 1024));
                $total += strlen($data);
            }
        });
    }
    Swoole\Event::wait();
    echo $total . " bytes\n";
    $pm->kill();
};

$pm->childFunc = function () use ($pm) {
    $serv = new Swoole\Server('127.0.0.1', $pm->getFreePort(), SWOOLE_PROCESS);
    $serv->set(array(
        "worker_num" => 1,
        'log_level' => SWOOLE_LOG_ERROR,
        'open_length_check' => true,
        'package_max_length' => 4 * 1024 * 1024,
        'package_length_type' => 'N',
        'package_length_offset' => 0,
        'package_body_offset' => 4,
        'send_yield' => true,
    ));
    $serv->on("WorkerStart", function (Swoole\Server $serv) use ($pm) {
        $pm->wakeup();
    });
    $serv->on('connect', function (Swoole\Server $serv, $fd, $rid) {
        if (VERBOSE) {
            echo "new client, fd=$fd\n";
        }
        $n = SEND_N;
        while ($n--) {
            $len = rand(PKT_MIN_SIZE, PKT_MAX_SIZE);
            $send_data = str_repeat(chr(ord('A') + $n % 10), $len);
            if (VERBOSE) {
                echo "[Server] c=$fd, n=$n, len=" . (strlen($send_data) + 4) . "\n---------------------------------------------------------------------\n";
            }
            $retval = $serv->send($fd, pack('N', $len) . $send_data);
            if ($retval === false) {
                echo "send error, code=" . swoole_last_error() . "\n";
            }
        }
    });
    $serv->on('receive', function (Swoole\Server $serv, $fd, $rid, $data) {
    });
    $serv->start();
};

$pm->childFirst();
$pm->run();
?>
--EXPECTF--
%d bytes
