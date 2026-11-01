<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../private/php/core/class-users.php';
require __DIR__ . '/../private/php/core/class-audit.php';
function verify($ok, $message) { if (!$ok) { throw new RuntimeException($message); } echo "OK $message\n"; }
function clearAuditFixture($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $file) { clearAuditFixture($path . '/' . $file); }
        rmdir($path);
    } else { unlink($path); }
}
$root = sys_get_temp_dir() . '/h5ai-audit-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
putenv('H5AI_USERS_DIR=' . $root);
$_SESSION = ['access' => ['user' => 'admin']];
$failed = false;
try {
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
    putenv('H5AI_TRUSTED_PROXIES=');
    verify(Audit::clientAddress() === ['ip' => '192.0.2.10', 'source' => 'network'], 'Untrusted forwarded address ignored');
    putenv('H5AI_TRUSTED_PROXIES=192.0.2.10/32');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 203.0.113.99';
    verify(Audit::clientAddress()['ip'] === '203.0.113.99', 'Trusted proxy chain ignores spoofed left prefix');
    putenv('H5AI_TRUSTED_PROXIES=192.0.2.0/24,198.51.100.0/24');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99, 198.51.100.8';
    verify(Audit::clientAddress()['ip'] === '203.0.113.99', 'Multiple trusted proxy networks supported');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'bad-address';
    verify(Audit::clientAddress()['source'] === 'network', 'Malformed proxy header falls back to network address');
    $_SERVER['REMOTE_ADDR'] = '2001:db8::10';
    putenv('H5AI_TRUSTED_PROXIES=2001:db8::/64');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:db9::5';
    verify(Audit::clientAddress()['ip'] === '2001:db9::5', 'IPv6 trusted proxy prefix supported');
    $_SERVER['REMOTE_ADDR'] = '2001:db8:0:1::10';
    verify(Audit::clientAddress()['source'] === 'network', 'IPv6 trust does not extend beyond configured prefix');
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    putenv('H5AI_TRUSTED_PROXIES=');
    $id = Audit::record('user.updated', 'success', "alice\nforged", ['password' => 'NEVER-LOG-SECRET', 'cookie' => 'NEVER-LOG-SECRET',
        'csrf' => 'NEVER-LOG-SECRET', 'fields' => ['password'], 'path' => '/safe']);
    verify(is_string($id), 'Audit record appended');
    $file = Audit::directory() . '/' . gmdate('Y-m-d') . '.00.jsonl';
    $raw = file_get_contents($file);
    verify(strpos($raw, 'NEVER-LOG-SECRET') === false && count(explode("\n", trim($raw))) === 1, 'Only whitelisted metadata stored, with control characters neutralized');
    $children = [];
    $code = 'require ' . var_export(realpath(__DIR__ . '/../private/php/core/class-users.php'), true) . '; require '
        . var_export(realpath(__DIR__ . '/../private/php/core/class-audit.php'), true)
        . '; $_SERVER["REMOTE_ADDR"]="127.0.0.1"; for($i=0;$i<40;$i++){ if(!Audit::record("user.updated","success","parallel")){exit(1);} }';
    for ($index = 0; $index < 4; $index++) {
        $children[] = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']], $pipes);
    }
    foreach ($children as $child) { verify(is_resource($child) && proc_close($child) === 0, 'Concurrent audit writer completed'); }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    verify(count($lines) === 161 && count(array_filter($lines, function ($line) { return is_array(json_decode($line, true)); })) === 161, 'Concurrent appends preserve all valid JSON records');
    $before = Audit::record('audit.view');
    $filter = ['from' => time() - 86400, 'to' => time() + 86400, 'page' => 1, 'before' => $before, 'event' => '', 'user' => '', 'result' => ''];
    $first = Audit::query($filter);
    $filter['page'] = 2;
    $second = Audit::query($filter);
    verify(count($first['rows']) === 50 && count($second['rows']) === 50 && !array_intersect(array_column($first['rows'], 'id'), array_column($second['rows'], 'id')), 'Pages have no duplicate events');
    Audit::record('user.created', 'success', 'newer');
    $filter['page'] = 1;
    verify(array_column(Audit::query($filter)['rows'], 'id') === array_column($first['rows'], 'id'), 'Snapshot pagination stays stable when new events arrive');
    $filter['user'] = 'alice forged';
    verify(count(Audit::query($filter)['rows']) === 1, 'User filter matches historical target');
    $filter['user'] = '';
    $bad = ['id' => '00000000000000000001-12345678', 'time' => time(), 'event' => [], 'result' => 'success'];
    file_put_contents($file, json_encode($bad) . "\n", FILE_APPEND);
    verify(Audit::query($filter)['warnings'] === true, 'Malformed stored records are ignored safely');
    $expired = Audit::directory() . '/' . gmdate('Y-m-d', time() - (Audit::RETENTION + 1) * 86400) . '.00.jsonl';
    $recent = Audit::directory() . '/' . gmdate('Y-m-d', time() - (Audit::RETENTION - 1) * 86400) . '.00.jsonl';
    file_put_contents($expired, "{}\n"); file_put_contents($recent, "{}\n");
    file_put_contents(Audit::directory() . '/maintenance', 'old');
    Audit::record('audit.view');
    verify(!is_file($expired) && is_file($recent), 'Retention removes expired daily files and preserves recent files');
    $handle = fopen($file, 'c+'); ftruncate($handle, Audit::SEGMENT_BYTES); fclose($handle);
    verify(Audit::record('audit.view') !== false && is_file(str_replace('.00.jsonl', '.01.jsonl', $file)), 'Full segment rotates to the next daily file');
    $secondFile = str_replace('.00.jsonl', '.01.jsonl', $file);
    $handle = fopen($secondFile, 'c+'); ftruncate($handle, Audit::SEGMENT_BYTES); fclose($handle);
    verify(Audit::record('audit.view') === false && is_file(Audit::directory() . '/' . gmdate('Y-m-d') . '.limit'), 'Daily volume is bounded and quota exhaustion is marked');
    file_put_contents($root . '/blocked', 'not a directory');
    putenv('H5AI_USERS_DIR=' . $root . '/blocked');
    verify(Audit::record('audit.view') === false, 'Unavailable audit storage never throws into account operations');
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); $failed = true; }
finally { clearAuditFixture($root); }
exit($failed ? 1 : 0);
