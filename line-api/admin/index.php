<?php
/** 釣果管理画面（管理者専用）：確認・編集・承認（掲載）・非公開・削除 */
declare(strict_types=1);
define('WSC_APP', true);
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/auth.php';

wsc_send_security_headers(true);
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

if (!wsc_is_configured()) { http_response_code(503); echo '<!doctype html><meta charset="utf-8"><p>初期設定が完了していません。</p>'; exit; }
wsc_session_start();

$self = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: './';
function wsc_redirect(string $query = '', string $flash = ''): void
{
    global $self;
    if ($flash !== '') $_SESSION['wsc_flash'] = $flash;
    header('Location: ' . $self . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

$labels = ['published' => '掲載中', 'pending' => '承認待ち', 'rejected' => '非公開', 'draft' => '送信前'];

// ---- POST 処理 ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login') {
        if (!wsc_csrf_ok()) wsc_redirect('', '画面の有効期限が切れました。もう一度ログインしてください。');
        if (wsc_try_login((string)($_POST['password'] ?? ''))) wsc_redirect('', 'ログインしました。');
        wsc_redirect('', wsc_login_locked((string)($_SERVER['REMOTE_ADDR'] ?? '')) ? 'ログインに続けて失敗したため、15分間ロックしています。' : 'パスワードが違います。');
    }
    if (!wsc_admin_logged_in()) wsc_redirect('', 'ログインしてください。');
    if (!wsc_csrf_ok()) wsc_redirect('', '画面の有効期限が切れました。もう一度操作してください。');
    if ($action === 'logout') { wsc_logout(); header('Location: ' . $self, true, 303); exit; }

    if ($action === 'set_passphrase') {
        $p1 = (string)($_POST['passphrase'] ?? '');
        if (mb_strlen(trim($p1)) < 4 || preg_match('/\s/u', trim($p1))) wsc_redirect('tab=settings', '合言葉は空白なしの4文字以上にしてください。');
        wsc_state(function (array &$s) use ($p1) { $s['settings']['passphrase_hash'] = password_hash(wsc_normalize_passphrase($p1), PASSWORD_DEFAULT); $s['settings']['passphrase_updated'] = wsc_now(); });
        wsc_redirect('tab=settings', '合言葉を設定しました。会員に案内してください（登録済みの会員はそのまま投稿できます）。');
    }
    if ($action === 'tick_token') {
        // 定期実行（GitHub Actions）用トークンを発行。サーバーにはハッシュのみ保存し、平文はこの画面で1回だけ表示。
        $token = bin2hex(random_bytes(32));
        $file = wsc_path('tick.key');
        if (file_put_contents($file, hash('sha256', $token) . "\n", LOCK_EX) === false) wsc_redirect('tab=settings', 'トークンを保存できませんでした。');
        @chmod($file, 0600);
        $_SESSION['wsc_tick_token_once'] = $token;
        wsc_redirect('tab=settings', '定期実行トークンを発行しました。下の値をGitHubのSecret「WSC_TICK_TOKEN」に登録してください（この表示は1回だけです）。');
    }
    if ($action === 'auto_publish') {
        $on = ($_POST['value'] ?? '') === '1';
        wsc_state(function (array &$s) use ($on) { $s['settings']['auto_publish'] = $on; });
        wsc_redirect('tab=settings', $on ? '自動掲載を再開しました。' : '自動掲載を停止しました。以後の投稿は「承認待ち」に入ります。');
    }
    if ($action === 'member_block' || $action === 'member_unblock' || $action === 'member_name') {
        $key = (string)($_POST['key'] ?? '');
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) wsc_redirect('tab=members', '会員が見つかりません。');
        $ok = wsc_state(function (array &$s) use ($key, $action) {
            if (!isset($s['members'][$key])) return false;
            if ($action === 'member_name') $s['members'][$key]['name'] = wsc_clip((string)($_POST['name'] ?? ''), 30);
            else $s['members'][$key]['blocked'] = $action === 'member_block';
            return true;
        });
        wsc_redirect('tab=members', !$ok ? '会員が見つかりません。' : ['member_block' => '投稿を停止しました。', 'member_unblock' => '投稿を再開しました。', 'member_name' => '名前を保存しました。'][$action]);
    }

    $id = (string)($_POST['id'] ?? '');
    if (!wsc_valid_id($id)) wsc_redirect('', '投稿が見つかりません。');
    if ($action === 'delete') {
        if (($_POST['confirm'] ?? '') !== 'yes') wsc_redirect('id=' . $id . '&delete=1', '削除の確認にチェックを入れてください。');
        $ok = wsc_state(function (array &$s) use ($id) { return wsc_delete_post($s, $id); });
        wsc_redirect('', $ok ? '投稿を削除しました（写真も削除済み）。' : '投稿が見つかりません。');
    }
    if (in_array($action, ['save', 'publish', 'reject', 'pending'], true)) {
        $result = wsc_state(function (array &$s) use ($id, $action) {
            if (!isset($s['posts'][$id])) return null;
            $p = &$s['posts'][$id];
            $p['fish'] = wsc_clip((string)($_POST['fish'] ?? ''), 60);
            $date = (string)($_POST['caught_on'] ?? '');
            $p['caught_on'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
            $p['comment'] = wsc_clip(str_replace("\r\n", "\n", (string)($_POST['comment'] ?? '')), 600);
            $p['author'] = wsc_clip((string)($_POST['author'] ?? ''), 30);
            $hidden = array_map('intval', (array)($_POST['hide'] ?? []));
            foreach ($p['photos'] as $i => &$ph) $ph['hidden'] = in_array($i, $hidden, true);
            unset($ph);
            if ($p['status'] === 'draft' && $action !== 'save') {
                foreach ($s['drafts'] as $k => $d) if ($d === $id) unset($s['drafts'][$k]);
                unset($p['_line_user']);
                $p['submitted_at'] = $p['submitted_at'] ?? wsc_now();
            }
            if ($action === 'publish') { $p['status'] = 'published'; $p['published_at'] = $p['published_at'] ?? wsc_now(); }
            if ($action === 'reject') $p['status'] = 'rejected';
            if ($action === 'pending') $p['status'] = 'pending';
            $p['updated_at'] = wsc_now();
            return $p['status'];
        });
        if ($result === null) wsc_redirect('', '投稿が見つかりません。');
        $msg = ['save' => '保存しました。', 'publish' => 'ホームページに掲載しました。', 'reject' => '非公開にしました（ホームページから外しました）。', 'pending' => '承認待ちに戻しました。'][$action];
        wsc_redirect($action === 'save' ? 'id=' . $id : 'tab=' . $result, $msg);
    }
    wsc_redirect('', '不明な操作です。');
}

$flash = (string)($_SESSION['wsc_flash'] ?? '');
unset($_SESSION['wsc_flash']);
$csrf = wsc_csrf_token();
$logged = wsc_admin_logged_in();

function wsc_page_start(string $title): void
{
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="robots" content="noindex,nofollow"><title>' . wsc_h($title) . '｜和歌山雑賀クラブ 釣果管理</title><style>';
    echo ':root{--navy:#0c1720;--ink:#12232e;--orange:#fa7916;--line:#d9e1e5}*{box-sizing:border-box}body{margin:0;font-family:"Hiragino Kaku Gothic ProN","Noto Sans JP","Yu Gothic",Meiryo,sans-serif;color:var(--ink);background:#f2f4f3;font-size:16px;line-height:1.7}';
    echo 'header{background:var(--navy);color:#fff;padding:12px 18px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}header b{font-size:17px}header small{color:var(--orange);letter-spacing:.15em;font:800 10px Arial,sans-serif;display:block}header form{margin-left:auto}';
    echo 'main{max-width:1080px;margin:0 auto;padding:18px}a{color:#0b5cad}.flash{background:#fff4e8;border-left:4px solid var(--orange);padding:10px 14px;margin:0 0 16px}';
    echo '.tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 16px}.tabs a{padding:8px 14px;background:#fff;border:1px solid var(--line);text-decoration:none;color:var(--ink);border-radius:3px}.tabs a.on{background:var(--navy);color:#fff;border-color:var(--navy)}';
    echo '.list{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}.item{background:#fff;border:1px solid var(--line);border-radius:4px;overflow:hidden;text-decoration:none;color:inherit;display:block}.item img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:#dfe6ea}.item .noimg{aspect-ratio:4/3;display:grid;place-items:center;background:#e7ecef;color:#6b7a83}.item div{padding:10px 12px}.item p{margin:2px 0;font-size:14px;overflow-wrap:anywhere}.muted{color:#64727b;font-size:13px}';
    echo '.badge{display:inline-block;font-size:12px;padding:1px 8px;border-radius:10px;background:#e8edf0}.badge.pending{background:#fff0dc;color:#9a4c00}.badge.published{background:#e1f4e6;color:#16612b}.badge.rejected{background:#f2e3e3;color:#8a2020}';
    echo '.photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px}.photos figure{margin:0;background:#fff;border:1px solid var(--line);padding:6px}.photos img{width:100%;height:200px;object-fit:contain;background:#eef2f4;display:block}.photos label{font-size:14px;display:block;margin-top:4px}';
    echo 'form.edit{background:#fff;border:1px solid var(--line);padding:16px;margin-top:16px}.field{margin:0 0 14px}.field label{display:block;font-weight:700;margin-bottom:4px}.field input[type=text],.field input[type=date],.field textarea,.login input{width:100%;padding:10px;border:1px solid #b9c5cc;border-radius:3px;font:inherit}.field textarea{min-height:120px}';
    echo '.btns{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px}button{font:inherit;font-weight:700;padding:11px 18px;border-radius:3px;border:1px solid #9fb0ba;background:#fff;cursor:pointer;min-height:46px}button.primary{background:var(--orange);border-color:var(--orange);color:#0c1720}button.dark{background:var(--navy);color:#fff;border-color:var(--navy)}button.danger{background:#b42318;border-color:#b42318;color:#fff}';
    echo '.raw{background:#f6f8f9;border:1px dashed var(--line);padding:10px;white-space:pre-wrap;font-size:14px}.login{max-width:380px;margin:40px auto;background:#fff;border:1px solid var(--line);padding:22px}</style></head><body>';
}

if (!$logged) {
    wsc_page_start('ログイン');
    echo '<header><div><small>WAKAYAMA SAIKA CLUB</small><b>釣果管理画面</b></div></header><main>';
    if ($flash) echo '<p class="flash">' . wsc_h($flash) . '</p>';
    echo '<form class="login" method="post"><input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="action" value="login">';
    echo '<p><label>管理者パスワード<br><input type="password" name="password" autocomplete="current-password" required></label></p><button class="primary" type="submit">ログイン</button></form></main></body></html>';
    exit;
}

// 放置下書きの自動確定（通知はプッシュ）
$notify = wsc_state(function (array &$s) { return wsc_sweep_idle_drafts($s); });
wsc_notify_submitted($notify);

$state = wsc_read_state();
$base = wsc_base_url();
$photoUrl = function (string $id, int $i, string $s = 'm') use ($base) { return $base . 'photo.php?p=' . rawurlencode($id) . '&i=' . $i . '&s=' . $s; };
$config = wsc_config();

wsc_page_start('釣果管理');
echo '<header><div><small>WAKAYAMA SAIKA CLUB</small><b>釣果管理画面</b></div>';
echo '<a style="color:#fff" href="' . wsc_h((string)$config['site_catch_url']) . '" target="_blank" rel="noopener">釣果情報ページを見る ↗</a>';
echo '<form method="post"><input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="action" value="logout"><button type="submit">ログアウト</button></form></header><main>';
if ($flash) echo '<p class="flash" role="status">' . wsc_h($flash) . '</p>';

$id = (string)($_GET['id'] ?? '');
if ($id !== '' && wsc_valid_id($id) && isset($state['posts'][$id])) {
    $p = $state['posts'][$id];
    if ($p['status'] === 'draft') foreach (wsc_parse_texts($p['texts'] ?? []) as $k => $v) if (trim((string)($p[$k] ?? '')) === '') $p[$k] = $v;
    echo '<p><a href="?tab=' . wsc_h($p['status']) . '">← 一覧に戻る</a></p>';
    if (!empty($_GET['delete'])) {
        echo '<form class="edit" method="post"><h2>この投稿を削除しますか？</h2><p>写真も含めて完全に削除され、元に戻せません。ホームページから外すだけなら「非公開にする」を使ってください。</p>';
        echo '<input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . wsc_h($id) . '">';
        echo '<p><label><input type="checkbox" name="confirm" value="yes" required> 削除してよいことを確認しました</label></p>';
        echo '<div class="btns"><button class="danger" type="submit">完全に削除する</button> <a href="?id=' . wsc_h($id) . '">やめる</a></div></form></main></body></html>';
        exit;
    }
    echo '<h1 style="font-size:22px;margin:0 0 6px">投稿の確認 <span class="badge ' . wsc_h($p['status']) . '">' . wsc_h($labels[$p['status']] ?? $p['status']) . '</span></h1>';
    echo '<p class="muted">受信：' . wsc_h(date('Y年n月j日 H:i', strtotime((string)($p['submitted_at'] ?? $p['created_at'])))) . '　写真 ' . count($p['photos']) . '枚';
    if (!empty($p['published_at'])) echo '　掲載：' . wsc_h(date('Y年n月j日 H:i', strtotime((string)$p['published_at'])));
    echo '</p>';
    echo '<form class="edit" method="post"><input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="id" value="' . wsc_h($id) . '">';
    if ($p['photos']) {
        echo '<div class="photos">';
        foreach ($p['photos'] as $i => $ph) {
            echo '<figure><a href="' . wsc_h($photoUrl($id, $i, 'l')) . '" target="_blank" rel="noopener"><img src="' . wsc_h($photoUrl($id, $i)) . '" alt="写真' . ($i + 1) . '"></a>';
            echo '<label><input type="checkbox" name="hide[]" value="' . $i . '"' . (!empty($ph['hidden']) ? ' checked' : '') . '> この写真は掲載しない</label></figure>';
        }
        echo '</div>';
    } else {
        echo '<p class="muted">写真なし（文章のみの投稿）</p>';
    }
    if (!empty($p['texts'])) echo '<div class="field"><label>LINEで届いた文章（原文）</label><div class="raw">' . wsc_h(implode("\n―――\n", $p['texts'])) . '</div></div>';
    echo '<div class="field"><label for="fish">魚種・釣果</label><input type="text" id="fish" name="fish" maxlength="60" value="' . wsc_h($p['fish'] ?? '') . '" placeholder="例：マダイ 3匹"></div>';
    echo '<div class="field"><label for="caught_on">釣行日</label><input type="date" id="caught_on" name="caught_on" value="' . wsc_h($p['caught_on'] ?? '') . '"></div>';
    echo '<div class="field"><label for="comment">コメント</label><textarea id="comment" name="comment" maxlength="600">' . wsc_h($p['comment'] ?? '') . '</textarea></div>';
    echo '<div class="field"><label for="author">投稿者名（空欄なら表示しません）</label><input type="text" id="author" name="author" maxlength="30" value="' . wsc_h($p['author'] ?? '') . '"></div>';
    echo '<div class="btns">';
    echo '<button class="primary" name="action" value="publish" type="submit">' . ($p['status'] === 'published' ? '修正を保存して掲載を続ける' : '承認してホームページに掲載') . '</button>';
    echo '<button class="dark" name="action" value="save" type="submit">保存だけする</button>';
    if ($p['status'] !== 'rejected') echo '<button name="action" value="reject" type="submit">非公開にする</button>';
    if ($p['status'] === 'rejected' || $p['status'] === 'published') echo '<button name="action" value="pending" type="submit">承認待ちに戻す</button>';
    echo '</div></form>';
    echo '<p style="margin-top:22px"><a style="color:#b42318" href="?id=' . wsc_h($id) . '&delete=1">この投稿を削除する…</a></p>';
    echo '</main></body></html>';
    exit;
}

$tab = (string)($_GET['tab'] ?? 'published');
if (!isset($labels[$tab]) && !in_array($tab, ['members', 'settings'], true)) $tab = 'published';
$counts = array_fill_keys(array_keys($labels), 0);
foreach ($state['posts'] as $p) if (isset($counts[$p['status'] ?? ''])) $counts[$p['status']]++;
$autoOn = (bool)wsc_setting($state, 'auto_publish', true);
$passSet = wsc_passphrase_set($state);
echo '<nav class="tabs" aria-label="状態">';
foreach (['published', 'pending', 'rejected', 'draft'] as $k) echo '<a class="' . ($k === $tab ? 'on' : '') . '" href="?tab=' . $k . '">' . wsc_h($labels[$k]) . '（' . $counts[$k] . '）</a>';
echo '<a class="' . ($tab === 'members' ? 'on' : '') . '" href="?tab=members">会員（' . count($state['members']) . '）</a>';
echo '<a class="' . ($tab === 'settings' ? 'on' : '') . '" href="?tab=settings">設定</a>';
echo '</nav>';
if (!$passSet) echo '<p class="flash">合言葉が未設定のため、まだ誰も会員登録・投稿できません。「設定」タブで合言葉を決めてください。</p>';
if (!$autoOn) echo '<p class="flash">自動掲載を停止中です。新しい投稿は「承認待ち」に入ります。</p>';
if ($tab === 'settings') {
    echo '<form class="edit" method="post"><h2 style="margin-top:0">会員登録の合言葉</h2><p>LINEで「登録 合言葉」と送った人だけが投稿できます。' . ($passSet ? '現在：<b>設定済み</b>（' . wsc_h(date('Y年n月j日', strtotime((string)wsc_setting($state, 'passphrase_updated', 'now')))) . '）' : '現在：<b>未設定</b>') . '</p>';
    echo '<input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="action" value="set_passphrase">';
    echo '<div class="field"><label for="pp">新しい合言葉（空白なし・4文字以上）</label><input type="text" id="pp" name="passphrase" autocomplete="off" required></div>';
    echo '<p class="muted">合言葉を変えても、登録済みの会員はそのまま投稿できます。外部に漏れたときは変更し、不審な会員は「会員」タブで停止してください。</p><div class="btns"><button class="primary" type="submit">合言葉を保存</button></div></form>';
    $once = (string)($_SESSION['wsc_tick_token_once'] ?? '');
    unset($_SESSION['wsc_tick_token_once']);
    $tickSet = is_file(wsc_path('tick.key'));
    echo '<form class="edit" method="post"><h2 style="margin-top:0">定期実行トークン（送信忘れの自動確定用）</h2>';
    echo '<p>現在：<b>' . ($tickSet ? '発行済み' : '未発行') . '</b>。GitHub Actions が15分ごとにこのトークンを付けて自動確定を呼び出します。</p>';
    if ($once !== '') echo '<p class="flash">GitHubのSecret名 <b>WSC_TICK_TOKEN</b> に、次の値を登録してください（再表示できません）：<br><code style="word-break:break-all;font-size:15px">' . wsc_h($once) . '</code></p>';
    echo '<input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="action" value="tick_token">';
    echo '<p class="muted">再発行すると古いトークンは使えなくなります（GitHubのSecretも登録し直してください）。</p><div class="btns"><button type="submit">' . ($tickSet ? 'トークンを再発行' : 'トークンを発行') . '</button></div></form>';
    echo '<form class="edit" method="post"><h2 style="margin-top:0">自動掲載</h2><p>現在：<b>' . ($autoOn ? 'ON（「送信」ですぐ掲載）' : 'OFF（承認待ちに入る）') . '</b></p>';
    echo '<input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="action" value="auto_publish"><input type="hidden" name="value" value="' . ($autoOn ? '0' : '1') . '">';
    echo '<p class="muted">荒らし投稿が続くときはOFFにすると、新しい投稿はすべて承認待ちになります。1日' . WSC_SITE_DAILY_PUBLISH . '件を超えた分も自動で承認待ちになります。</p>';
    echo '<div class="btns"><button class="' . ($autoOn ? 'danger' : 'primary') . '" type="submit">' . ($autoOn ? '自動掲載を停止する' : '自動掲載を再開する') . '</button></div></form></main></body></html>';
    exit;
}
if ($tab === 'members') {
    echo '<p class="muted">LINEで合言葉を送って登録した人の一覧です。LINEの表示名やIDは保存していません（識別番号のみ）。</p>';
    if (!$state['members']) echo '<p>登録済みの会員はいません。</p>';
    $postCount = [];
    foreach ($state['posts'] as $p) { $k = (string)($p['user_key'] ?? ''); $postCount[$k] = ($postCount[$k] ?? 0) + 1; }
    echo '<div class="list">';
    foreach ($state['members'] as $key => $m) {
        echo '<div class="item"><div><p><b>' . wsc_h((string)($m['name'] ?? '') !== '' ? (string)$m['name'] : '（名前なし）') . '</b> <span class="badge ' . (!empty($m['blocked']) ? 'rejected' : 'published') . '">' . (!empty($m['blocked']) ? '停止中' : '投稿可') . '</span></p>';
        echo '<p class="muted">番号 ' . wsc_h(substr((string)$key, 0, 6)) . '・登録 ' . wsc_h(date('Y/n/j', strtotime((string)$m['registered_at']))) . '・投稿 ' . (int)($postCount[$key] ?? 0) . '件</p>';
        echo '<form method="post" style="display:flex;gap:6px;margin-top:6px"><input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="key" value="' . wsc_h((string)$key) . '"><input type="hidden" name="action" value="member_name"><input type="text" name="name" value="' . wsc_h((string)($m['name'] ?? '')) . '" placeholder="管理用メモ（例：山田さん）" maxlength="30" style="flex:1;padding:8px;border:1px solid #b9c5cc"><button type="submit">保存</button></form>';
        echo '<form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="' . wsc_h($csrf) . '"><input type="hidden" name="key" value="' . wsc_h((string)$key) . '"><input type="hidden" name="action" value="' . (!empty($m['blocked']) ? 'member_unblock' : 'member_block') . '"><button type="submit" class="' . (!empty($m['blocked']) ? '' : 'danger') . '">' . (!empty($m['blocked']) ? '投稿を再開' : '投稿を停止') . '</button></form></div></div>';
    }
    echo '</div></main></body></html>';
    exit;
}
if ($tab === 'draft') echo '<p class="muted">「送信」を押す前の投稿です。最後の操作から1時間たつと自動で確定（掲載）されます。</p>';
$items = array_filter($state['posts'], function ($p) use ($tab) { return ($p['status'] ?? '') === $tab; });
uasort($items, function ($a, $b) { return strcmp((string)($b['submitted_at'] ?? $b['created_at']), (string)($a['submitted_at'] ?? $a['created_at'])); });
if (!$items) echo '<p>' . wsc_h($labels[$tab]) . 'の投稿はありません。</p>';
echo '<div class="list">';
foreach ($items as $pid => $p) {
    echo '<a class="item" href="?id=' . wsc_h((string)$pid) . '">';
    echo $p['photos'] ? '<img src="' . wsc_h($photoUrl((string)$pid, 0)) . '" alt="" loading="lazy">' : '<span class="noimg">写真なし</span>';
    echo '<div><p class="muted">' . wsc_h(date('n月j日 H:i', strtotime((string)($p['submitted_at'] ?? $p['created_at'])))) . '・写真' . count($p['photos']) . '枚</p>';
    $fish = trim((string)($p['fish'] ?? '')); $comment = trim((string)($p['comment'] ?? ''));
    if ($tab === 'draft') $comment = trim(implode(' ', $p['texts'] ?? []));
    if ($fish !== '') echo '<p><b>' . wsc_h($fish) . '</b></p>';
    if ($comment !== '') echo '<p>' . wsc_h(mb_strimwidth($comment, 0, 80, '…')) . '</p>';
    if (trim((string)($p['author'] ?? '')) !== '') echo '<p class="muted">投稿者：' . wsc_h((string)$p['author']) . '</p>';
    echo '</div></a>';
}
echo '</div></main></body></html>';
