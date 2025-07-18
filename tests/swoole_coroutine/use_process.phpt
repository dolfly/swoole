--TEST--
swoole_coroutine: user process
--SKIPIF--
<?php
require __DIR__ . '/../include/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';

use Swoole\Client;
use Swoole\Process;
use Swoole\Server;
use SwooleTest\ProcessManager;

$pm = new ProcessManager();

const SIZE = 8192 * 5;
const TIMES = 10;

$pm->parentFunc = function () use ($pm) {
    $client = new Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_SYNC);
    $client->set([
        'open_eof_check' => true,
        'package_eof' => "\r\n\r\n",
    ]);
    $r = $client->connect('127.0.0.1', $pm->getFreePort(), -1);
    if ($r === false) {
        echo 'ERROR';
        exit;
    }
    $client->send('SUCCESS');
    for ($i = 0; $i < TIMES; $i++) {
        $ret = $client->recv();
        Assert::same(strlen($ret), SIZE + 4);
    }
    $client->close();
    $pm->kill();
};

$pm->childFunc = function () use ($pm) {
    $serv = new Server('127.0.0.1', $pm->getFreePort(), SWOOLE_PROCESS);
    $serv->set([
        'worker_num' => 1,
        'log_file' => '/dev/null',
    ]);

    $proc = new Process(function ($process) use ($serv) {
        $data = json_decode($process->read(), true);
        for ($i = 0; $i < TIMES / 2; $i++) {
            go(function () use ($serv, $data, $i) {
                // echo "user sleep start\n";
                co::sleep(0.01);
                // echo "user sleep end\n";
                $serv->send($data['fd'], str_repeat('A', SIZE) . "\r\n\r\n");
                // echo "user process $i send ok\n";
            });
        }
    }, false, true);

    $serv->addProcess($proc);
    $serv->on('WorkerStart', function (Server $serv) use ($pm) {
        $pm->wakeup();
    });
    $serv->on('Receive', function (Server $serv, $fd, $reactorId, $data) use ($proc) {
        $proc->write(json_encode([
            'fd' => $fd,
        ]));
        for ($i = 0; $i < TIMES / 2; $i++) {
            go(function () use ($serv, $fd, $i) {
                // echo "worker sleep start\n";
                co::sleep(0.01);
                // echo "worker sleep end\n";
                $serv->send($fd, str_repeat('A', SIZE) . "\r\n\r\n");
                // echo "worker send $i ok\n";
            });
        }
    });
    $serv->start();
};

$pm->childFirst();
$pm->run();
?>
--EXPECT--
