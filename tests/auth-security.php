<?php
/** Standalone HTTP regression tests. No production data is touched. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    echo "OK $message\n";
}
function removeTree($path) {
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) { removeTree($path . '/' . $name); }
        rmdir($path);
    } else { unlink($path); }
}
function copyTree($from, $to) {
    mkdir($to, 0700, true);
    foreach (array_diff(scandir($from), ['.', '..']) as $name) {
        if (is_dir($from . '/' . $name)) { copyTree($from . '/' . $name, $to . '/' . $name); }
        else { copy($from . '/' . $name, $to . '/' . $name); }
    }
}
function request($path, $data = null, $extra = [], $method = null) {
    global $base, $cookie, $rememberCookie, $root, $provisionEstablishedFixtures;
    $submitted = is_array($data) ? $data : null;
    $headers = $extra;
    $cookies = array_filter([$cookie, $rememberCookie]);
    if ($cookies) { $headers[] = 'Cookie: ' . implode('; ', $cookies); }
    if (is_array($data)) { $data = http_build_query($data); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    $context = stream_context_create(['http' => [
        'method' => $method ?: ($data === null ? 'GET' : 'POST'),
        'header' => implode("\r\n", $headers), 'content' => $data ?? '',
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10
    ]]);
    $body = file_get_contents($base . $path, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (get_defined_vars()['http_response_header'] ?? []);
    foreach ($responseHeaders as $header) {
        if (preg_match('/^Set-Cookie: (H5AI_SESSION=[^;]*)/', $header, $match)) { $cookie = $match[1]; }
        if (preg_match('/^Set-Cookie: (H5AI_REMEMBER=([^;]*))/', $header, $match)) {
            $rememberCookie = $match[2] === '' || $match[2] === 'deleted' ? '' : $match[1];
        }
    }
    preg_match('/ (\d{3}) /', $responseHeaders[0], $match);
    // Older regression cases exercise already-established accounts with known
    // credentials. Provision those fixtures directly in the isolated temp store.
    // The first-login tests below disable this and use the complete HTTP flow.
    if ($provisionEstablishedFixtures && (int) $match[1] === 303 && $path === '/administration'
        && ($submitted['operation'] ?? '') === 'create' && isset($submitted['password'])) {
        $file = $root . '/managed/users.json';
        $before = file_get_contents($file);
        $store = json_decode($before, true);
        $store['users'][$submitted['username']]['hash'] = password_hash($submitted['password'], PASSWORD_BCRYPT);
        $store['users'][$submitted['username']]['must_change_password'] = false;
        $after = json_encode($store);
        file_put_contents($file, $after);
    }
    return ['status' => (int) $match[1], 'body' => $body, 'headers' => implode("\n", $responseHeaders)];
}
function csrf($body) {
    preg_match('/(?:name="_csrf" value|name="h5ai-csrf" content)="([a-f0-9]+)"/', $body, $match);
    if (!isset($match[1])) { throw new RuntimeException('Missing CSRF token: ' . substr($body, 0, 300)); }
    return $match[1];
}

function auditEvents($root) {
    $events = [];
    foreach (glob($root . '/managed/audit/*.jsonl') ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $event = json_decode($line, true);
            if (!is_array($event)) { throw new RuntimeException('Invalid audit JSON'); }
            $events[] = $event;
        }
    }
    return $events;
}

function uploadProfile($fields, $filename, $bytes, $type) {
    $boundary = 'h5ai-' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $key => $value) {
        $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $key . "\"\r\n\r\n" . $value . "\r\n";
    }
    $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"avatar\"; filename=\"" . $filename . "\"\r\nContent-Type: " . $type . "\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
    return request('/mon-compte', $body, ['Content-Type: multipart/form-data; boundary=' . $boundary]);
}

$root = sys_get_temp_dir() . '/h5ai-auth-' . bin2hex(random_bytes(6));
$process = null;
$cookie = '';
$rememberCookie = '';
$provisionEstablishedFixtures = true;
try {
    require_once __DIR__ . '/../private/php/core/class-users.php';
    foreach (['shortAa!', 'alllowercasepassword!', 'ALLUPPERCASEPASSWORD!', 'NoSpecialCharacters', 'ÉÉÉÉÉÉééé!', str_repeat('Aa!', 25)] as $badPassword) {
        $rejected = false;
        try { Users::validatePassword($badPassword); } catch (InvalidArgumentException $error) { $rejected = true; }
        check($rejected, 'Password policy rejects: ' . (strlen($badPassword) > 72 ? 'over 72 bytes' : $badPassword));
    }
    Users::validatePassword('LongPassword!');
    check(true, 'Password policy accepts requested complexity without requiring a digit');
    mkdir($root, 0700);
    copyTree(__DIR__ . '/../private', $root . '/_h5ai/private');
    copyTree(__DIR__ . '/../public', $root . '/_h5ai/public');
    $optionsFile = $root . '/_h5ai/private/conf/options.json';
    file_put_contents($optionsFile, preg_replace('/("search":\s*\{\s*"enabled":\s*)false/', '${1}true', file_get_contents($optionsFile)));
    file_put_contents($root . '/users.htpasswd', 'tester:' . password_hash('Test-Password-2026!', PASSWORD_BCRYPT) . "\n");
    file_put_contents($root . '/document.txt', 'PRIVATE-0123456789');
    file_put_contents($root . '/empty.txt', '');
    file_put_contents($root . '/payload.html', '<script>alert(1)</script>');
    file_put_contents($root . '/shared.php', '<?php echo "SECRET-PHP";');
    file_put_contents($root . '/.secret', 'DO-NOT-EXPOSE');
    file_put_contents($root . '/_h5ai/public/cache/test.jpg', 'PRIVATE-THUMB');
    file_put_contents($root . '/_h5ai/public/cache/test.jpg.source.json', json_encode($root . '/document.txt'));
    mkdir($root . '/folder');
    mkdir($root . "/Films D'animation");
    file_put_contents($root . "/Films D'animation/test.mp4", 'PRIVATE-VIDEO');
    mkdir($root . '/folder/allowed', 0700, true);
    mkdir($root . '/folder/denied', 0700, true);
    file_put_contents($root . '/folder/allowed/report.txt', 'ALLOWED-REPORT');
    file_put_contents($root . '/folder/denied/report.txt', 'DENIED-REPORT');
    file_put_contents($root . '/folder/other.txt', 'DENIED-SIBLING');
    file_put_contents($root . '/_h5ai/public/cache/allowed.jpg', 'ALLOWED-THUMB');
    file_put_contents($root . '/_h5ai/public/cache/allowed.jpg.source.json', json_encode($root . '/folder/allowed/report.txt'));
    // Router emulates only Apache's routing, never claims to validate Apache.
    file_put_contents($root . '/router.php', '<?php $_SERVER["SCRIPT_NAME"]="/index.php"; $_SERVER["SERVER_SOFTWARE"]="Apache/2.4"; $_SERVER["HTTP_USER_AGENT"]=$_SERVER["HTTP_USER_AGENT"]??"test"; require __DIR__."/_h5ai/public/index.php";');
    putenv('H5AI_AUTH_FILE=' . $root . '/users.htpasswd');
    putenv('H5AI_AUTH_STATE=' . $root . '/auth-state');
    putenv('H5AI_USERS_DIR=' . $root . '/managed');
    putenv('H5AI_ADMIN_USER=tester');
    putenv('H5AI_TRUSTED_PROXIES=');
    putenv('H5AI_HTTPS=0');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $root, '-d', 'display_errors=0', '-S', $address, $root . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Server failed to start'); }
    fclose($pipes[0]);
    for ($i = 0; $i < 50; $i++) {
        $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($ready) { fclose($ready); break; }
        usleep(100000);
    }
    $r = request('/document.txt');
    check($r['status'] === 200 && strpos($r['body'], 'PRIVATE-0123456789') === false, 'Direct file anonymous: login only');
    check(strpos($r['headers'], 'HttpOnly') !== false && strpos($r['headers'], 'SameSite=Lax') !== false, 'Protected session cookie');
    $token = csrf($r['body']);
    check(request('/folder/', ['action' => 'get'])['status'] === 401, 'Anonymous API denied');
    check(request('/', ['h5ai_auth' => 'login', 'username' => 'tester', 'password' => 'Test-Password-2026!'])['status'] === 403, 'Login CSRF denied');
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => "' OR 1=1 --", 'password' => 'x'])['status'] === 401, 'SQL-looking username does not authenticate');
    $oldCookie = $cookie;
    $r = request('/document.txt', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'tester', 'password' => 'Test-Password-2026!']);
    check($r['status'] === 303 && $cookie !== $oldCookie, 'Login rotates session');
    foreach (['/_h5ai/public/index.php', '/_h5ai/public/index.php?admin=1', '/_h5ai/public/index.php?account=1', '/_h5ai/public/', '/_h5ai/public/%69ndex.php?admin=1'] as $oldUrl) {
        $entryResponse = request($oldUrl);
        check($entryResponse['status'] === 404 && strpos($entryResponse['headers'], 'Location:') === false, 'Old page URL is disabled: ' . $oldUrl);
    }
    check(request('/_h5ai/public/index.php?admin=1', ['operation' => 'create', '_csrf' => $token, 'username' => 'old-route'])['status'] === 404, 'Old POST route is disabled');
    $r = request('/document.txt');
    check($r['body'] === 'PRIVATE-0123456789', 'Authenticated direct file');
    check(request('/Films%20D%27animation/test.mp4')['body'] === 'PRIVATE-VIDEO', 'Mounted folder names with spaces and apostrophes');
    check(strpos($r['headers'], 'no-store') !== false, 'Private files never cached');
    $r = request('/document.txt', null, ['Range: bytes=8-11']);
    check($r['status'] === 206 && $r['body'] === '0123', 'Byte range');
    $r = request('/document.txt', null, ['Range: bytes=-4']);
    check($r['status'] === 206 && $r['body'] === '6789', 'Suffix range');
    check(request('/document.txt', null, ['Range: bytes=-0'])['status'] === 416, 'Zero suffix rejected');
    check(request('/document.txt', null, ['Range: bytes=999-'])['status'] === 416, 'Invalid range rejected');
    check(request('/document.txt', null, [], 'HEAD')['body'] === '', 'HEAD has no body');
    check(request('/empty.txt')['body'] === '', 'Empty file supported');
    $r = request('/payload.html');
    check(strpos($r['headers'], 'attachment;') !== false && strpos($r['headers'], 'application/octet-stream') !== false, 'HTML cannot execute in site origin');
    foreach (['/.secret', '/shared.php', '/users.htpasswd', '/managed/users.json', '/auth-state', '/_h5ai/private/conf/options.json', '/folder/../.secret', '/%2e%2e/secret.txt'] as $path) {
        check(request($path)['status'] === 404, 'Denied: ' . $path);
    }
    check(request('/_h5ai/public/cache/test.jpg')['body'] === 'PRIVATE-THUMB', 'Authenticated thumbnail');
    $r = request('/folder/');
    check($r['status'] === 200, 'Directory view');
    check(strpos($r['body'], 'href="/_h5ai/public/css/styles.css"') !== false
        && strpos($r['body'], 'src="/_h5ai/public/js/scripts.js') !== false, 'Asset URLs survive rewritten SCRIPT_NAME');
    check(strpos($r['body'], 'action="/deconnexion"') !== false
        && strpos($r['body'], 'href="/administration"') !== false, 'Logout and admin URLs survive rewritten SCRIPT_NAME');
    $token = csrf($r['body']);
    check(request('/folder/', ['action' => 'get', 'setup' => 'true'])['status'] === 403, 'API CSRF denied');
    $r = request('/folder/', json_encode(['action' => 'get', '_csrf' => $token, 'setup' => true, 'options' => true]), ['Content-Type: application/json']);
    check(isset(json_decode($r['body'], true)['setup']), 'Authenticated JSON API');
    $options = json_decode($r['body'], true)['options'];
    check($options['custom']['enabled'] === false && $options['preview-txt']['styles']['txt-md'] === 1, 'Untrusted HTML and Markdown rendering disabled');
    $r = request('/folder/', ['action' => 'login', '_csrf' => $token, 'pass' => '']);
    check(json_decode($r['body'], true)['asAdmin'] === false, 'Default empty administrator password rejected');
    $r = request('/', ['action' => 'download', '_csrf' => $token, 'type' => 'php-tar', 'as' => "archive\r\nX-Injected: yes", 'baseHref' => '/', 'hrefs' => ['/document.txt', '/.secret', '/_h5ai/private/conf/options.json']]);
    check(strpos($r['body'], 'PRIVATE-0123456789') !== false && strpos($r['body'], 'DO-NOT-EXPOSE') === false, 'Archive excludes hidden and private files');
    check(strpos($r['headers'], "\nX-Injected:") === false, 'Download name cannot inject headers');
    $changedStore = json_decode(file_get_contents($root . '/managed/users.json'), true);
    $changedStore['users']['tester']['auth_version'] = bin2hex(random_bytes(16));
    file_put_contents($root . '/managed/users.json', json_encode($changedStore));
    check(strpos(request('/document.txt')['body'], 'PRIVATE-0123456789') === false, 'Account file change invalidates sessions');
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'tester', 'password' => 'Test-Password-2026!']);
    $sessionFile = $root . '/sess_' . substr($cookie, strlen('H5AI_SESSION='));
    $state = file_get_contents($sessionFile);
    file_put_contents($sessionFile, preg_replace('/"last";i:\d+;/', '"last";i:' . (time() - 1801) . ';', $state));
    check(strpos(request('/document.txt')['body'], 'PRIVATE-0123456789') === false, 'Idle session expires');
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'tester', 'password' => 'Test-Password-2026!']);
    $token = csrf(request('/folder/')['body']);
    // Administration and ACL regression checks, through real HTTP requests.
    $adminUrl = '/administration';
    $r = request($adminUrl);
    check($r['status'] === 200 && strpos($r['body'], 'Nouvel utilisateur') !== false, 'Administrator page');
    check(strpos($r['body'], 'href="/journaux"') !== false, 'Administrator menu includes Journaux');
    check(strpos($r['body'], '$2y$') === false, 'Password hashes absent from administrator HTML');
    check(request($adminUrl, ['operation' => 'create', 'username' => 'forged'])['status'] === 403, 'Administrator mutations require CSRF');
    $createReader = ['operation' => 'create', '_csrf' => $token, 'username' => 'reader', 'password' => 'Reader-Password-2026!', 'role' => 'user', 'folders' => ['/folder/allowed']];
    check(request($adminUrl, $createReader)['status'] === 303, 'Create user with a subfolder grant');
    check(request($adminUrl, $createReader)['status'] === 400, 'Duplicate account rejected');
    check(request($adminUrl, ['operation' => 'delete', '_csrf' => $token, 'username' => 'tester'])['status'] === 400, 'Cannot delete own administrator account');
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $token, 'username' => 'tester', 'role' => 'user'])['status'] === 400, 'Cannot remove own administrator role');
    $badGrant = $createReader;
    $badGrant['username'] = 'invalid-grant';
    $badGrant['folders'] = ['/folder/../folder/allowed'];
    check(request($adminUrl, $badGrant)['status'] === 400, 'Traversal grant rejected');
    $badGrant['folders'] = ['/_h5ai/private'];
    check(request($adminUrl, $badGrant)['status'] === 400, 'Application-private grant rejected');
    $noAccess = $createReader;
    $noAccess['username'] = 'noaccess';
    $noAccess['folders'] = [];
    check(request($adminUrl, $noAccess)['status'] === 303, 'Create user with no grants');
    $stored = json_decode(file_get_contents($root . '/managed/users.json'), true);
    check(password_verify('Reader-Password-2026!', $stored['users']['reader']['hash']), 'New password stored as a bcrypt hash');
    $adminCookie = $cookie;
    $adminToken = $token;
    $cookie = '';
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'reader', 'password' => 'Reader-Password-2026!'])['status'] === 303, 'New account can sign in');
    $token = csrf(request('/')['body']);
    check(request($adminUrl)['status'] === 403, 'Normal user cannot view administration');
    check(strpos(request('/')['body'], 'href="/journaux"') === false, 'Normal user menu omits Journaux');
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $token, 'username' => 'escalated', 'role' => 'admin', 'password' => 'Attack-Password-2026!'])['status'] === 403, 'Normal user cannot create an administrator');
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'Granted subfolder file readable');
    foreach (['/document.txt', '/folder/other.txt', '/folder/denied/report.txt', '/folder/allowed/../denied/report.txt', '/Films%20D%27animation/test.mp4'] as $denied) {
        check(request($denied)['status'] === 404, 'ACL denies direct link: ' . $denied);
    }
    check(request('/folder/')['status'] === 200, 'Ancestor directory usable for navigation');
    $r = request('/', json_encode(['action' => 'get', '_csrf' => $token, 'items' => ['href' => '/folder/', 'what' => 1]]), ['Content-Type: application/json']);
    $listed = json_encode(json_decode($r['body'], true), JSON_UNESCAPED_SLASHES);
    check(strpos($listed, '/folder/allowed') !== false && strpos($listed, '/folder/denied') === false && strpos($listed, '/document.txt') === false, 'Directory API filters unauthorized entries');
    $r = request('/', json_encode(['action' => 'get', '_csrf' => $token, 'search' => ['href' => '/', 'pattern' => 'report', 'ignorecase' => false]]), ['Content-Type: application/json']);
    check(strpos($r['body'], 'allowed') !== false && strpos($r['body'], 'denied') === false, 'Search filters unauthorized folders');
    $r = request('/', ['action' => 'download', '_csrf' => $token, 'type' => 'php-tar', 'as' => 'acl.tar', 'baseHref' => '/', 'hrefs' => ['/folder', '/document.txt']]);
    check(strpos($r['body'], 'ALLOWED-REPORT') !== false && strpos($r['body'], 'DENIED-REPORT') === false && strpos($r['body'], 'DENIED-SIBLING') === false && strpos($r['body'], 'PRIVATE-0123456789') === false, 'Archives filter unauthorized content');
    check(request('/_h5ai/public/cache/allowed.jpg')['body'] === 'ALLOWED-THUMB', 'Thumbnail of allowed source readable');
    check(request('/_h5ai/public/cache/test.jpg')['status'] === 404, 'Thumbnail of denied source blocked');
    check(request('/_h5ai/public/cache/allowed.jpg.source.json')['status'] === 404, 'Thumbnail source metadata private');
    $readerCookie = $cookie;
    $cookie = $adminCookie;
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $adminToken, 'username' => 'reader', 'role' => 'user', 'password' => '', 'folders' => []])['status'] === 303, 'Revoke folder access without changing password');
    $cookie = $readerCookie;
    check(strpos(request('/folder/allowed/report.txt')['body'], 'ALLOWED-REPORT') === false, 'Permission change revokes existing user session');
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'reader', 'password' => 'Reader-Password-2026!'])['status'] === 303, 'Blank password update preserves password');
    check(request('/_h5ai/public/cache/allowed.jpg')['status'] === 404, 'Previously cached thumbnail denied after grant revocation');
    $r = request('/');
    check($r['status'] === 200 && strpos($r['body'], 'report.txt') === false, 'No-grant account has an empty root');
    $readerCookie = $cookie;
    $cookie = $adminCookie;
    check(request($adminUrl, ['operation' => 'reset_password', '_csrf' => $adminToken, 'username' => 'reader'])['status'] === 303, 'Administrator generates a reset password');
    $resetPage = request($adminUrl . '?user=reader');
    preg_match('/id="temporary-password" type="text" value="([^"]+)"/', $resetPage['body'], $resetMatch);
    $readerReset = html_entity_decode($resetMatch[1], ENT_QUOTES, 'UTF-8');
    $cookie = '';
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'reader', 'password' => 'Reader-Password-2026!'])['status'] === 401, 'Old password rejected after reset');
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'reader', 'password' => $readerReset])['status'] === 303, 'Generated reset password accepted');
    $readerCookie = $cookie;
    $cookie = $adminCookie;
    check(request($adminUrl, ['operation' => 'delete', '_csrf' => $adminToken, 'username' => 'reader'])['status'] === 303, 'Administrator deletes another account');
    $cookie = $readerCookie;
    check(strpos(request('/folder/allowed/report.txt')['body'], 'ALLOWED-REPORT') === false, 'Deleted account session revoked');
    $cookie = $adminCookie;
    $token = $adminToken;
    $accountUrl = '/mon-compte';
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $token, 'username' => 'tester', 'role' => 'admin', 'password' => 'onlylowercase!'])['status'] === 400, 'Manual admin password replacement rejected');
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $token, 'username' => 'profile', 'role' => 'user', 'password' => 'Profile-Password2026!', 'folders' => ['/folder/allowed']])['status'] === 303, 'Create profile test account');
    $adminCookie = $cookie;
    $cookie = '';
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'profile', 'password' => 'Profile-Password2026!']);
    $r = request($accountUrl);
    check($r['status'] === 200 && strpos($r['body'], 'Mon compte') !== false, 'Normal user can view own account');
    check(strpos($r['body'], 'id="avatar-canvas" width="512" height="512"') !== false
        && strpos($r['body'], '/js/avatar-crop.js?v=1') !== false, 'Profile includes the 512px crop editor');
    check(strpos($r['headers'], "img-src 'self' blob:") !== false, 'Profile policy allows local image previews');
    $token = csrf($r['body']);
    check(request($accountUrl, ['username' => 'forged'])['status'] === 403, 'Profile mutations require CSRF');
    $profileFields = ['_csrf' => $token, 'username' => 'profile', 'current_password' => 'Profile-Password2026!', 'new_password' => '', 'confirm_password' => ''];
    $invalid = $profileFields; $invalid['current_password'] = 'Wrong-Password!';
    check(request($accountUrl, $invalid)['status'] === 400, 'Profile changes require current password');
    $invalid = $profileFields; $invalid['new_password'] = 'New-Password2026!';
    check(request($accountUrl, $invalid)['status'] === 400, 'Password confirmation required');
    $invalid = $profileFields; $invalid['new_password'] = $invalid['confirm_password'] = 'lowercaseonly!';
    check(request($accountUrl, $invalid)['status'] === 400, 'Profile enforces password complexity');
    $invalid = $profileFields; $invalid['username'] = 'tester';
    check(request($accountUrl, $invalid)['status'] === 400, 'Profile cannot claim another username');
    check(uploadProfile($profileFields, 'avatar.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png')['status'] === 400, 'SVG disguised as PNG rejected');
    check(uploadProfile($profileFields, 'oversized.png', str_repeat('x', 524289), 'image/png')['status'] === 400, 'Oversized photo rejected');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aZioAAAAASUVORK5CYII=');
    $profileFields['username'] = 'renamed';
    $profileFields['new_password'] = $profileFields['confirm_password'] = 'Renamed-Profile!';
    $profileFields['role'] = 'admin'; // Must be ignored by the profile controller.
    $oldCookie = $cookie;
    check(uploadProfile($profileFields, 'photo.png', $png, 'image/png')['status'] === 303 && $cookie !== $oldCookie, 'Rename, password change and photo upload rotate session');
    $r = request($accountUrl);
    check(strpos($r['body'], 'value="renamed"') !== false && strpos($r['body'], '$2y$') === false, 'Profile shows new username without password hash');
    $token = csrf($r['body']);
    $r = request($accountUrl . '/photo');
    check($r['status'] === 200 && $r['body'] === $png && strpos($r['headers'], 'Content-Type: image/png') !== false, 'Own uploaded photo served as image');
    check(strpos($r['headers'], 'nosniff') !== false && strpos($r['headers'], 'sandbox') !== false, 'Profile image has restrictive response headers');
    $stored = json_decode(file_get_contents($root . '/managed/users.json'), true);
    check($stored['users']['renamed']['role'] === 'user' && $stored['users']['renamed']['folders'] === ['/folder/allowed'] && !isset($stored['users']['profile']), 'Rename preserves permissions and ignores role injection');
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT' && request('/document.txt')['status'] === 404, 'Renamed account retains folder restrictions');
    check(request('/managed/avatars/' . $stored['users']['renamed']['avatar']['file'])['status'] === 404, 'Avatar storage cannot be downloaded directly');
    request('/', ['h5ai_auth' => 'logout', '_csrf' => $token]);
    check(strpos(request($accountUrl . '/photo')['body'], $png) === false, 'Photo requires authentication');
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'profile', 'password' => 'Profile-Password2026!'])['status'] === 401, 'Old username rejected');
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'renamed', 'password' => 'Profile-Password2026!'])['status'] === 401, 'Old profile password rejected');
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'renamed', 'password' => 'Renamed-Profile!'])['status'] === 303, 'New profile credentials accepted');
    $token = csrf(request($accountUrl)['body']);
    check(request($accountUrl, ['_csrf' => $token, 'username' => 'renamed', 'current_password' => 'Renamed-Profile!', 'new_password' => '', 'confirm_password' => '', 'remove_avatar' => '1'])['status'] === 303, 'User can remove own photo');
    check(request($accountUrl . '/photo')['status'] === 404 && !is_file($root . '/managed/avatars/' . $stored['users']['renamed']['avatar']['file']), 'Removed photo deleted from private storage');
    $cookie = $adminCookie;
    $token = csrf(request('/')['body']);
    check(request($adminUrl)['status'] === 200, 'Unrelated administrator stays connected during profile edits');
    $token = csrf(request('/folder/')['body']);
    // Persistent cookies survive the loss of the PHP session, not revocation.
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $token, 'username' => 'persistent', 'role' => 'user', 'password' => 'Persistent-Password!', 'folders' => ['/folder/allowed']])['status'] === 303, 'Create persistent-login account');
    $adminCookie = $cookie;
    $adminToken = $token;
    $cookie = '';
    $r = request('/');
    check(strpos($r['body'], 'name="remember" value="1"') !== false, 'Login offers an opt-in remember checkbox');
    $token = csrf($r['body']);
    $r = request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'persistent', 'password' => 'Persistent-Password!', 'remember' => '1']);
    check($r['status'] === 303 && $rememberCookie !== '', 'Opt-in login issues a persistent cookie');
    preg_match_all('/Max-Age=(\d+)/', $r['headers'], $ages);
    $lifetime = $ages[1] ? max(array_map('intval', $ages[1])) : 0;
    check($lifetime >= 2591990 && $lifetime <= 2592000 && strpos($r['headers'], 'HttpOnly') !== false && strpos($r['headers'], 'SameSite=Lax') !== false, 'Persistent cookie has a 30-day lifetime and security flags');
    $originalRemember = $rememberCookie;
    $parts = explode(':', rawurldecode(substr($rememberCookie, strlen('H5AI_REMEMBER='))));
    $tokenFile = $root . '/managed/remember/' . $parts[0] . '.json';
    $record = json_decode(file_get_contents($tokenFile), true);
    check($record['hash'] === hash('sha256', $parts[1]) && strpos(file_get_contents($tokenFile), $parts[1]) === false, 'Persistent secret is only stored as a hash');
    $cookie = '';
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT' && $cookie !== '' && $rememberCookie !== $originalRemember, 'Closing browser session restores login and rotates persistent secret');
    check(request('/document.txt')['status'] === 404, 'Restored session retains folder restrictions');
    $currentRemember = $rememberCookie;
    $record = json_decode(file_get_contents($tokenFile), true);
    $record['previous_until'] = 0;
    file_put_contents($tokenFile, json_encode($record));
    $cookie = '';
    $rememberCookie = $originalRemember;
    check(strpos(request('/folder/allowed/report.txt')['body'], 'ALLOWED-REPORT') === false && $rememberCookie === '', 'Replayed old secret is rejected outside the parallel-request grace period');
    $cookie = '';
    $rememberCookie = $currentRemember;
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'Invalid replay cannot revoke the valid current secret');
    $record = json_decode(file_get_contents($tokenFile), true);
    $record['expires'] = time() - 1;
    file_put_contents($tokenFile, json_encode($record));
    $cookie = '';
    check(strpos(request('/folder/allowed/report.txt')['body'], 'ALLOWED-REPORT') === false && $rememberCookie === '', 'Expired persistent login is refused');
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'persistent', 'password' => 'Persistent-Password!', 'remember' => '1']);
    $logoutRemember = $rememberCookie;
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'logout', '_csrf' => $token])['status'] === 303 && $rememberCookie === '', 'Logout clears persistent cookie');
    $cookie = '';
    $rememberCookie = $logoutRemember;
    check(strpos(request('/folder/allowed/report.txt')['body'], 'ALLOWED-REPORT') === false, 'Logout revokes the server token even if an old cookie is replayed');
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'persistent', 'password' => 'Persistent-Password!', 'remember' => '1']);
    $beforePermissionChange = $rememberCookie;
    $cookie = $adminCookie;
    $rememberCookie = '';
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $adminToken, 'username' => 'persistent', 'role' => 'user', 'password' => '', 'folders' => []])['status'] === 303, 'Change remembered account permissions');
    $cookie = '';
    $rememberCookie = $beforePermissionChange;
    check(strpos(request('/folder/allowed/report.txt')['body'], 'ALLOWED-REPORT') === false && $rememberCookie === '', 'Permission change invalidates persistent credentials');
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'persistent', 'password' => 'Persistent-Password!']);
    check($rememberCookie === '', 'Unchecked remember does not create a persistent cookie');
    $cookie = '';
    check(strpos(request('/')['body'], '<h1>Connexion</h1>') !== false, 'Without opt-in a closed browser session stays logged out');
    $rememberCookie = 'H5AI_REMEMBER=invalid-cookie';
    check(strpos(request('/')['body'], '<h1>Connexion</h1>') !== false && $rememberCookie === '', 'Malformed persistent cookie is safely cleared');
    $cookie = $adminCookie;
    $token = $adminToken;
    $provisionEstablishedFixtures = false;
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $token, 'username' => 'onboarding', 'password' => 'Ignored-Manual-Password!', 'role' => 'user', 'folders' => ['/folder/allowed']])['status'] === 303, 'Administrator creates a generated temporary credential');
    $r = request($adminUrl . '?user=onboarding');
    preg_match('/id="temporary-password" type="text" value="([^"]+)"/', $r['body'], $temporaryMatch);
    $temporary = html_entity_decode($temporaryMatch[1] ?? '', ENT_QUOTES, 'UTF-8');
    Users::validatePassword($temporary);
    check(strlen($temporary) === 20 && $temporary !== 'Ignored-Manual-Password!', 'Generated password is strong and ignores submitted manual creation password');
    check(strpos($r['headers'], 'no-store') !== false && strpos(request($adminUrl . '?user=onboarding')['body'], 'id="temporary-password"') === false, 'Temporary credential is shown once with caching disabled');
    $store = json_decode(file_get_contents($root . '/managed/users.json'), true);
    check($store['users']['onboarding']['must_change_password'] === true && password_verify($temporary, $store['users']['onboarding']['hash'])
        && strpos(file_get_contents($root . '/managed/users.json'), $temporary) === false, 'Only temporary password hash and first-login flag are stored');
    $adminCookie = $cookie;
    $cookie = '';
    $token = csrf(request('/')['body']);
    $r = request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'onboarding', 'password' => $temporary, 'remember' => '1']);
    check($r['status'] === 303 && strpos($r['headers'], 'Location: /mon-compte') !== false && $rememberCookie === '', 'First login redirects to password change without issuing remember cookie');
    foreach (['/', '/folder/allowed/report.txt', '/administration', '/_h5ai/public/cache/allowed.jpg'] as $blockedPath) {
        $r = request($blockedPath);
        check($r['status'] === 303 && strpos($r['headers'], 'Location: /mon-compte') !== false && strpos($r['body'], 'ALLOWED-REPORT') === false, 'Pending account cannot access: ' . $blockedPath);
    }
    $r = request($accountUrl);
    check(strpos($r['body'], 'Changement de mot de passe obligatoire') !== false && strpos($r['body'], 'id="avatar"') === false, 'First-login page focuses on mandatory password change');
    $token = csrf($r['body']);
    check(request('/', ['action' => 'get', '_csrf' => $token, 'items' => ['href' => '/', 'what' => 1]])['status'] === 403, 'Pending account cannot use JSON API');
    check(request('/', ['action' => 'download', '_csrf' => $token, 'type' => 'php-tar', 'as' => 'blocked.tar', 'baseHref' => '/folder/allowed'])['status'] === 403, 'Pending account cannot download archives');
    $change = ['_csrf' => $token, 'username' => 'onboarding', 'current_password' => $temporary, 'new_password' => '', 'confirm_password' => ''];
    check(request($accountUrl, $change)['status'] === 400, 'Empty password cannot bypass first-login requirement');
    $change['new_password'] = $change['confirm_password'] = $temporary;
    check(request($accountUrl, $change)['status'] === 400, 'Temporary password cannot be reused as permanent password');
    $change['new_password'] = $change['confirm_password'] = 'Chosen-Passphrase!';
    $oldCookie = $cookie;
    $r = request($accountUrl, $change);
    check($r['status'] === 303 && strpos($r['headers'], 'Location: /' . "\n") !== false && $cookie !== $oldCookie, 'Choosing personal password completes onboarding and rotates session');
    check($rememberCookie !== '', 'Requested persistent login starts only after personal password is chosen');
    $store = json_decode(file_get_contents($root . '/managed/users.json'), true);
    check($store['users']['onboarding']['must_change_password'] === false && !password_verify($temporary, $store['users']['onboarding']['hash']), 'Temporary credential is invalid after onboarding');
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT' && request('/document.txt')['status'] === 404, 'Completed account gets only its assigned folder access');
    $token = csrf(request($accountUrl)['body']);
    request('/', ['h5ai_auth' => 'logout', '_csrf' => $token]);
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'onboarding', 'password' => $temporary])['status'] === 401, 'Temporary credential cannot sign in again');
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'onboarding', 'password' => 'Chosen-Passphrase!'])['status'] === 303, 'Personal credential can sign in normally');
    $token = csrf(request($accountUrl)['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'onboarding', 'password' => 'Chosen-Passphrase!', 'remember' => '1']);
    $victimSession = $cookie;
    $victimRemember = $rememberCookie;
    $cookie = $adminCookie;
    $rememberCookie = '';
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'tester', 'password' => 'Test-Password-2026!']);
    $token = csrf(request('/folder/')['body']);
    $r = request($adminUrl . '?user=onboarding');
    check(strpos($r['body'], 'name="password"') === false && strpos($r['body'], 'Générer un nouveau mot de passe temporaire') !== false, 'Account editor replaces manual password field with generation action');
    check(request($adminUrl, ['operation' => 'reset_password', 'username' => 'onboarding'])['status'] === 403, 'Reset generation requires CSRF');
    check(request($adminUrl, ['operation' => 'reset_password', '_csrf' => $token, 'username' => 'tester'])['status'] === 400, 'Administrator changes own password through Mon compte only');
    $beforeReset = json_decode(file_get_contents($root . '/managed/users.json'), true)['users']['onboarding'];
    check(request($adminUrl, ['operation' => 'reset_password', '_csrf' => $token, 'username' => 'onboarding'])['status'] === 303, 'Generate a fresh temporary password for an existing account');
    $r = request($adminUrl . '?user=onboarding');
    preg_match('/id="temporary-password" type="text" value="([^"]+)"/', $r['body'], $resetMatch);
    $resetPassword = html_entity_decode($resetMatch[1], ENT_QUOTES, 'UTF-8');
    Users::validatePassword($resetPassword);
    $afterReset = json_decode(file_get_contents($root . '/managed/users.json'), true)['users']['onboarding'];
    check($afterReset['must_change_password'] === true && $afterReset['role'] === $beforeReset['role'] && $afterReset['folders'] === $beforeReset['folders'], 'Generated reset preserves permissions and requires another password change');
    check(password_verify($resetPassword, $afterReset['hash']) && !password_verify('Chosen-Passphrase!', $afterReset['hash']), 'Generated reset immediately replaces old password');
    check(strpos(request($adminUrl . '?user=onboarding')['body'], 'id="temporary-password"') === false, 'Reset password is displayed once');
    $adminCookie = $cookie;
    $adminToken = $token;
    $cookie = $victimSession;
    $rememberCookie = $victimRemember;
    $r = request('/folder/allowed/report.txt');
    check(strpos($r['body'], '<h1>Connexion</h1>') !== false && $rememberCookie === '', 'Reset revokes previous sessions and persistent login');
    $token = csrf($r['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'onboarding', 'password' => 'Chosen-Passphrase!'])['status'] === 401, 'Old password cannot sign in after generated reset');
    $r = request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'onboarding', 'password' => $resetPassword]);
    check($r['status'] === 303 && strpos($r['headers'], 'Location: /mon-compte') !== false, 'Generated reset password sends the user to mandatory password change');
    check(request('/folder/allowed/report.txt')['status'] === 303, 'Previously established user cannot skip the reset password change');
    $token = csrf(request($accountUrl)['body']);
    request('/', ['h5ai_auth' => 'logout', '_csrf' => $token]);
    $cookie = $adminCookie;
    $token = $adminToken;
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $token, 'username' => 'pending-admin', 'role' => 'admin'])['status'] === 303, 'New administrators also receive temporary passwords');
    $r = request($adminUrl . '?user=pending-admin');
    preg_match('/id="temporary-password" type="text" value="([^"]+)"/', $r['body'], $temporaryMatch);
    $adminTemporary = html_entity_decode($temporaryMatch[1], ENT_QUOTES, 'UTF-8');
    $adminCookie = $cookie;
    $adminToken = $token;
    $cookie = '';
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'pending-admin', 'password' => $adminTemporary]);
    check(request($adminUrl)['status'] === 303, 'Pending administrator cannot open administration');
    $token = csrf(request($accountUrl)['body']);
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $token, 'username' => 'bypass-admin', 'role' => 'admin'])['status'] === 403, 'Pending administrator cannot bypass password change with an admin POST');
    check(request('/', ['h5ai_auth' => 'logout', '_csrf' => $token])['status'] === 303, 'Pending account can still log out');
    $cookie = $adminCookie;
    $token = $adminToken;
    // Multiple independent browsers: editing A must preserve B and the admin.
    $isolatedAdminCookie = $cookie;
    $isolatedAdminToken = $token;
    $cookie = $rememberCookie = '';
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'renamed', 'password' => 'Renamed-Profile!', 'remember' => '1']);
    $bSessionOne = $cookie;
    $cookie = '';
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'User B starts another remembered session');
    $bSessionTwo = $cookie;
    $bRemember = $rememberCookie;
    $cookie = $rememberCookie = '';
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'persistent', 'password' => 'Persistent-Password!', 'remember' => '1']);
    $aSessionOne = $cookie;
    $cookie = '';
    check(request($accountUrl)['status'] === 200, 'User A starts another remembered session');
    $aSessionTwo = $cookie;
    $aRemember = $rememberCookie;
    $cookie = $isolatedAdminCookie;
    $rememberCookie = '';
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $isolatedAdminToken, 'username' => 'persistent', 'role' => 'user', 'folders' => ['/folder/allowed']])['status'] === 303, 'Administrator changes only user A permissions');
    $r = request($adminUrl . '?user=persistent');
    check($r['status'] === 200 && strpos($r['body'], 'Utilisateur enregistré.') !== false && strpos($r['body'], 'Les autres sessions devront') === false, 'Administrator stays connected and confirmation no longer announces global logout');
    foreach ([$bSessionOne, $bSessionTwo] as $session) {
        $cookie = $session;
        check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'User B session survives changes to user A');
    }
    foreach ([$aSessionOne, $aSessionTwo] as $session) {
        $cookie = $session;
        check(strpos(request($accountUrl)['body'], '<h1>Connexion</h1>') !== false, 'Each user A session is revoked by its own account change');
    }
    $cookie = '';
    $rememberCookie = $aRemember;
    check(strpos(request($accountUrl)['body'], '<h1>Connexion</h1>') !== false && $rememberCookie === '', 'User A persistent token is revoked');
    $cookie = '';
    $rememberCookie = $bRemember;
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'User B persistent token survives changes to user A');
    $bRemember = $rememberCookie;
    $cookie = $isolatedAdminCookie;
    $rememberCookie = '';
    check(request($adminUrl, ['operation' => 'create', '_csrf' => $isolatedAdminToken, 'username' => 'unrelated-new-account', 'role' => 'user'])['status'] === 303, 'Create unrelated account during active sessions');
    $cookie = $bSessionOne;
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'Creating another account preserves existing sessions');
    $cookie = $isolatedAdminCookie;
    check(request($adminUrl, ['operation' => 'delete', '_csrf' => $isolatedAdminToken, 'username' => 'unrelated-new-account'])['status'] === 303, 'Delete unrelated account during active sessions');
    $cookie = '';
    $rememberCookie = $bRemember;
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'Deleting another account preserves remembered login');
    $bRemember = $rememberCookie;
    $cookie = $rememberCookie = '';
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'persistent', 'password' => 'Persistent-Password!', 'remember' => '1']);
    $aRemember = $rememberCookie;
    $aBeforeDelete = json_decode(file_get_contents($root . '/managed/users.json'), true)['users']['persistent'];
    $cookie = $isolatedAdminCookie;
    $rememberCookie = '';
    request($adminUrl, ['operation' => 'delete', '_csrf' => $isolatedAdminToken, 'username' => 'persistent']);
    $cookie = '';
    $rememberCookie = $aRemember;
    check(strpos(request($accountUrl)['body'], '<h1>Connexion</h1>') !== false, 'Deleting A revokes its persistent login');
    $cookie = $isolatedAdminCookie;
    $rememberCookie = '';
    request($adminUrl, ['operation' => 'create', '_csrf' => $isolatedAdminToken, 'username' => 'persistent', 'role' => 'user', 'folders' => ['/folder/allowed']]);
    // Even identical credentials/ACLs cannot revive old tokens after recreation.
    $recreatedStore = json_decode(file_get_contents($root . '/managed/users.json'), true);
    $newEpoch = $recreatedStore['users']['persistent']['auth_version'];
    $recreatedStore['users']['persistent'] = $aBeforeDelete;
    $recreatedStore['users']['persistent']['auth_version'] = $newEpoch;
    file_put_contents($root . '/managed/users.json', json_encode($recreatedStore));
    $cookie = '';
    $rememberCookie = $aRemember;
    check(strpos(request($accountUrl)['body'], '<h1>Connexion</h1>') !== false, 'Recreating the same username cannot revive revoked tokens');
    $cookie = '';
    $rememberCookie = $bRemember;
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'User B stays remembered through A deletion and recreation');
    $cookie = $isolatedAdminCookie;
    $rememberCookie = '';
    $token = $isolatedAdminToken;
    check(request($adminUrl)['status'] === 200, 'Administrator session survives all unrelated account changes');
    // Read-only audit page, including historical accounts and escaped data.
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $token, 'username' => 'noaccess', 'role' => 'admin'])['status'] === 303, 'Audit role promotion scenario');
    check(request($adminUrl, ['operation' => 'save', '_csrf' => $token, 'username' => 'noaccess', 'role' => 'user'])['status'] === 303, 'Audit role demotion scenario');
    $auditAdminCookie = $cookie;
    $auditAdminToken = $token;
    $cookie = $rememberCookie = '';
    check(strpos(request('/journaux')['body'], '<h1>Connexion</h1>') !== false, 'Anonymous visitor cannot read audit history');
    $token = csrf(request('/')['body']);
    request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => '<img src=x onerror=alert(1)>', 'password' => 'Audit-Secret-Candidate!']);
    $cookie = $auditAdminCookie;
    $r = request('/journaux', null, ['X-Forwarded-For: 203.0.113.123']);
    check($r['status'] === 200 && strpos($r['body'], 'Historique de sécurité') !== false && strpos($r['headers'], 'no-store') !== false, 'Administrator sees private audit page');
    check(strpos($r['body'], '&lt;img src=x onerror=alert(1)&gt;') !== false && strpos($r['body'], '<img src=x onerror=alert(1)>') === false, 'Untrusted historical username is escaped');
    $audit = auditEvents($root);
    $last = end($audit);
    check($last['event'] === 'audit.view' && $last['actor'] === 'tester' && $last['ip'] === '127.0.0.1', 'Journal consultation is logged and untrusted forwarded IP ignored');
    preg_match_all('/data-event-id="([^"]+)"/', $r['body'], $pageOne);
    preg_match('/rel="next" href="([^"]+)"/', $r['body'], $nextPage);
    check(count($pageOne[1]) === 50 && isset($nextPage[1]), 'Audit page is limited to 50 events');
    $nextResponse = request(html_entity_decode($nextPage[1], ENT_QUOTES, 'UTF-8'));
    preg_match_all('/data-event-id="([^"]+)"/', $nextResponse['body'], $pageTwo);
    check($nextResponse['status'] === 200 && count($pageTwo[1]) > 0 && !array_intersect($pageOne[1], $pageTwo[1]), 'HTTP pagination remains stable while consultations generate events');
    $r = request('/journaux?event=password.reset&user=onboarding');
    preg_match('/<tbody>(.*?)<\/tbody>/s', $r['body'], $bodyRows);
    check($r['status'] === 200 && strpos($bodyRows[1], 'Mot de passe réinitialisé') !== false && strpos($bodyRows[1], 'Connexion réussie') === false, 'Event and user filters combine correctly');
    $r = request('/journaux?event=user.deleted&user=reader');
    preg_match('/<tbody>(.*?)<\/tbody>/s', $r['body'], $bodyRows);
    check(strpos($bodyRows[1], 'Compte supprimé') !== false, 'Deleted accounts remain searchable in history');
    $future = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Paris')))->format('Y-m-d');
    $r = request('/journaux?from=' . $future . '&to=' . $future);
    check(strpos($r['body'], 'Aucun événement pour ces filtres.') !== false, 'Date filter excludes events outside the selected days');
    foreach (['?from=2026-99-99', '?user[]=bad', '?event=unknown', '?page=1001', '?before=../secret'] as $invalidFilter) {
        check(request('/journaux' . $invalidFilter)['status'] === 400, 'Invalid audit filter rejected: ' . $invalidFilter);
    }
    check(request('/journaux', [])['status'] === 405, 'Audit page offers no POST mutation endpoint');
    unlink($root . '/managed/audit/audit.lock');
    mkdir($root . '/managed/audit/audit.lock');
    $r = request('/journaux');
    check($r['status'] === 200 && strpos($r['body'], 'L’enregistrement de nouveaux événements est indisponible.') !== false, 'Audit write outage is shown while existing history remains readable');
    check(request('/document.txt')['body'] === 'PRIVATE-0123456789', 'Audit outage does not block authorized file access');
    rmdir($root . '/managed/audit/audit.lock');
    check(request('/managed/audit/' . gmdate('Y-m-d') . '.00.jsonl')['status'] === 404, 'Raw audit files are private even for administrators');
    $cookie = $bSessionOne;
    $r = request('/journaux');
    check($r['status'] === 403 && strpos($r['body'], '<table>') === false, 'Normal user cannot read the audit page');
    check(request('/folder/allowed/report.txt')['body'] === 'ALLOWED-REPORT', 'Audit activity does not invalidate unrelated sessions');
    $cookie = $auditAdminCookie;
    $token = $auditAdminToken;
    check(request('/', ['h5ai_auth' => 'logout', '_csrf' => $token])['status'] === 303, 'Logout');
    check(strpos(request('/_h5ai/public/cache/test.jpg')['body'], 'PRIVATE-THUMB') === false, 'Thumbnail denied after logout');
    $r = request('/'); $token = csrf($r['body']);
    for ($i = 0; $i < 10; $i++) {
        $r = request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'blocked', 'password' => 'wrong']);
    }
    $cookie = '';
    $token = csrf(request('/')['body']);
    check(request('/', ['h5ai_auth' => 'login', '_csrf' => $token, 'username' => 'blocked', 'password' => 'wrong'])['status'] === 429, 'Rate limit survives new session');
    unlink($root . '/users.htpasswd');
    unlink($root . '/managed/users.json');
    check(request('/')['status'] === 503, 'Missing credentials fail closed');
    $audit = auditEvents($root);
    $eventNames = array_column($audit, 'event');
    foreach (['auth.login', 'auth.failed', 'auth.remember', 'auth.remember_failed', 'auth.logout', 'auth.expired', 'auth.revoked',
        'security.rate_limited', 'security.csrf', 'access.denied', 'user.created', 'user.updated', 'user.deleted',
        'user.renamed', 'user.role', 'user.folders', 'user.photo', 'password.changed', 'password.initial', 'password.reset', 'audit.view'] as $event) {
        check(in_array($event, $eventNames, true), 'Audit records event: ' . $event);
    }
    $serializedAudit = json_encode($audit);
    $secrets = ['Audit-Secret-Candidate!', 'Test-Password-2026!', 'Reader-Password-2026!', 'Renamed-Profile!',
        'Persistent-Password!', 'Chosen-Passphrase!', $temporary, $adminTemporary, $readerReset, $resetPassword, $token];
    $safe = true;
    foreach ($secrets as $secret) { if (strpos($serializedAudit, $secret) !== false) { $safe = false; } }
    check($safe && strpos($serializedAudit, 'H5AI_SESSION=') === false && strpos($serializedAudit, '$2y$') === false, 'Passwords, hashes, session cookies and CSRF tokens are absent from audit history');
    echo "All security checks passed. Apache routing must also be checked in Docker.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    if (is_file($root . '/server.log')) { fwrite(STDERR, file_get_contents($root . '/server.log')); }
    $failed = true;
} finally {
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if (is_dir($root)) { removeTree($root); }
}
exit(isset($failed) ? 1 : 0);
