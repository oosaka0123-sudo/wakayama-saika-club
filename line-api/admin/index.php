<?php
declare(strict_types=1);
define('WSC_API_INTERNAL', true);
require dirname(__DIR__).'/lib.php';
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    http_response_code(403);
    exit('HTTPS is required for the admin page.');
}
try {
    $config = wsc_config();
    $secret = (string)($config['admin_token'] ?? '');
    if (strlen($secret) < 32) throw new RuntimeException('An admin token of 32+ characters must be configured.');
} catch (Throwable $error) {
    http_response_code(503);
    exit('The administration page has not been configured.');
}
session_name('WSC_ADMIN');
session_set_cookie_params([
    'lifetime'=>0, 'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/line-api/admin/'), '/').'/',
    'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict'
]);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        $input = (string)($_POST['password'] ?? '');
        if (hash_equals($secret, $input)) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
            header('Location: ./', true, 303); exit;
        }
        // Deliberately generic response: do not leak the configured token.
        $error = 'ログインできませんでした。';
    } elseif (!empty($_SESSION['admin']) && hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: ./', true, 303); exit;
        }
        $id = (string)($_POST['id'] ?? '');
        if ($action === 'approve' && preg_match('/^U[0-9a-f]{32}$/', $id)) {
            wsc_state(function (array &$state) use ($id) {
                if (!isset($state['candidates'][$id])) return;
                if (!isset($state['allowed']) || !is_array($state['allowed'])) $state['allowed'] = [];
                $state['allowed'][$id] = time();
                unset($state['candidates'][$id]);
            });
        } elseif ($action === 'revoke' && preg_match('/^U[0-9a-f]{32}$/', $id)) {
            wsc_state(function (array &$state) use ($id) {
                if (!isset($state['allowed']) || !is_array($state['allowed'])) $state['allowed'] = [];
                unset($state['allowed'][$id]);
            });
        } elseif ($action === 'delete' && preg_match('/^[a-f0-9]{20}$/', $id)) {
            $image = wsc_state(function (array &$state) use ($id) {
                foreach ($state['posts'] as $i=>$post) {
                    if (($post['id'] ?? '') !== $id) continue;
                    $path = (string)($post['image'] ?? '');
                    array_splice($state['posts'], $i, 1);
                    return $path;
                }
                return '';
            });
            if (preg_match('/^uploads\/[a-f0-9]{24}\.jpg$/', $image)) {
                @unlink(dirname(__DIR__).'/'.$image);
            }
        }
        header('Location: ./', true, 303); exit;
    } else {
        $error = 'セッションが無効です。再ログインしてください。';
    }
}
$isAdmin = !empty($_SESSION['admin']);
$state = ['candidates'=>[], 'allowed'=>[], 'posts'=>[]];
if ($isAdmin) {
    $state = wsc_state(function (array &$state) { return $state; });
}
function csrf(): void { echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>釣果情報 管理画面｜和歌山雑賀クラブ</title>
<style>
:root{color-scheme:dark;font-family:system-ui,"Noto Sans JP",sans-serif}
*{box-sizing:border-box}body{background:#0c1720;color:#edf3f7;margin:0;line-height:1.6}
header,main{max-width:1000px;margin:auto;padding:24px}header{border-bottom:1px solid #384752}
h1{font-size:clamp(24px,5vw,38px);margin:0}h2{font-size:20px;margin:24px 0 12px}
small,aside{color:#b4c0c8}.eyebrow{color:#ff9d42;font-size:11px;letter-spacing:.14em}
section{background:#172733;border:1px solid #314453;border-radius:10px;padding:20px;margin-bottom:20px}
input,button{font:inherit;border-radius:5px;padding:9px 11px;min-height:42px}
input{background:#fff;color:#18222c;border:1px solid #d8e2e6;width:min(420px,100%)}
button{cursor:pointer;border:1px solid #ff9d42;background:#ff9d42;color:#101921;font-weight:750}
button.danger{color:#fff;background:#923b2b;border-color:#923b2b}
button.subtle{background:transparent;color:#ffb167}
button:focus-visible,input:focus-visible{outline:3px solid #fff;outline-offset:2px}
form{display:inline-flex;align-items:center;gap:12px;flex-wrap:wrap}
.entry{padding:14px 0;border-bottom:1px solid #394955;display:flex;gap:16px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.entry:last-child{border:0}.entry p{margin:2px 0}
code{font-size:11px;overflow-wrap:anywhere;color:#a9c0d1}
img.thumb{width:130px;max-height:120px;object-fit:cover;border-radius:5px}
.muted{font-size:12px;color:#b5c2cb}.error{color:#ffc5b5}
</style>
</head>
<body>
<header>
  <p class="eyebrow">WAKAYAMA SAIKA CLUB / ADMIN</p>
  <h1>釣果情報 管理画面</h1>
  <p class="muted">会員登録は最初に1回。登録後の釣果投稿は承認不要で自動公開されます。</p>
</header>
<main>
<?php if (!$isAdmin): ?>
  <section>
    <h2>管理者ログイン</h2>
    <?php if ($error): ?><p class="error"><?=e($error)?></p><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="login">
      <label for="password">管理キー</label>
      <input id="password" type="password" name="password" required autocomplete="off">
      <button type="submit">ログイン</button>
    </form>
  </section>
<?php else: ?>
  <form method="post">
    <?php csrf(); ?>
    <input type="hidden" name="action" value="logout">
    <button type="submit" class="subtle">ログアウト</button>
  </form>
  <section>
    <h2>登録待ち（<?=count($state['candidates'])?>件）</h2>
    <p class="muted">会員は専用LINEへ「登録：名前」を送信。本人を確認できた人だけ初回登録してください。</p>
    <?php foreach ($state['candidates'] as $user=>$record): ?>
      <div class="entry">
        <div>
          <strong><?=e(is_array($record) ? (string)($record['name'] ?? '名前未入力') : '名前未入力')?></strong>
          <p><code><?=e((string)$user)?></code></p>
        </div>
        <form method="post">
          <?php csrf(); ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?=e((string)$user)?>">
          <button type="submit">会員登録</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$state['candidates']): ?><p class="muted">登録待ちはありません。</p><?php endif; ?>
  </section>
  <section>
    <h2>登録済みの投稿者（<?=count($state['allowed'] ?? [])?>件）</h2>
    <?php foreach (($state['allowed'] ?? []) as $user=>$registeredAt): ?>
      <div class="entry">
        <code><?=e((string)$user)?></code>
        <form method="post">
          <?php csrf(); ?>
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?=e((string)$user)?>">
          <button type="submit" class="danger">登録解除</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (empty($state['allowed'])): ?><p class="muted">登録済みの投稿者はいません。</p><?php endif; ?>
  </section>
  <section>
    <h2>公開中の釣果（<?=count($state['posts'])?>件）</h2>
    <?php foreach (array_slice($state['posts'], 0, 100) as $post): ?>
      <div class="entry">
        <div>
          <?php if (preg_match('/^uploads\/[a-f0-9]{24}\.jpg$/',(string)($post['image'] ?? ''))): ?>
            <img class="thumb" src="../<?=e($post['image'])?>" alt="投稿釣果の写真">
          <?php endif; ?>
          <p><?=e((string)($post['date'] ?? ''))?> <?=e((string)($post['author'] ?? ''))?></p>
          <p><?=e((string)($post['comment'] ?? ''))?></p>
        </div>
        <form method="post" onsubmit="return confirm('この釣果を削除しますか？');">
          <?php csrf(); ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?=e((string)($post['id'] ?? ''))?>">
          <button class="danger" type="submit">削除</button>
        </form>
      </div>
    <?php endforeach; ?>
    <?php if (!$state['posts']): ?><p class="muted">まだ釣果投稿はありません。</p><?php endif; ?>
  </section>
<?php endif; ?>
</main>
</body>
</html>