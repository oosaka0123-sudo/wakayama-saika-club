#!/usr/bin/env python3
"""釣果投稿APIの結合テスト。line-api を一時フォルダへコピーし、PHP内蔵サーバー＋LINE API偽装で一連の流れを確認する。
実行: python3 -I tests/test_line_api.py
"""
import base64, hashlib, hmac, http.cookiejar, io, json, os, re, shutil, socket, subprocess, sys, tempfile, time, urllib.parse, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SECRET = "0123456789abcdef0123456789abcdef"  # テスト用の値
PASSWORD = "test-admin-password-123"
UA = "U" + "a" * 32
UB = "U" + "b" * 32
passed = 0


def check(cond, label):
    global passed
    if not cond:
        print("FAIL:", label)
        sys.exit(1)
    passed += 1
    print("ok  ", label)


def make_jpeg(path, w, h, color, gps=True):
    from PIL import Image
    img = Image.new("RGB", (w, h), color)
    exif = Image.Exif()
    exif[0x010F] = "TestCam"  # Make
    if gps:
        exif[0x8825] = {1: "N", 2: (34.0, 13.0, 0.0), 3: "E", 4: (135.0, 10.0, 0.0)}
    img.save(path, "JPEG", exif=exif.tobytes())


def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p


tmp = tempfile.mkdtemp(prefix="wsc-test-")
app = os.path.join(tmp, "line-api")
shutil.copytree(os.path.join(ROOT, "line-api"), app)
fixtures = os.path.join(tmp, "fixtures"); os.makedirs(fixtures)
make_jpeg(os.path.join(fixtures, "1001.jpg"), 1200, 900, (200, 40, 40))
make_jpeg(os.path.join(fixtures, "1002.jpg"), 900, 1600, (40, 120, 200))
make_jpeg(os.path.join(fixtures, "2001.jpg"), 3000, 2000, (40, 160, 60))
for i in range(3001, 3007):
    make_jpeg(os.path.join(fixtures, f"{i}.jpg"), 400, 300, (i % 255, 80, 80))
open(os.path.join(fixtures, "9999.jpg"), "wb").write(b"<?php echo 'x'; ?>" + b"0" * 200)  # 偽画像
key = "k" * 40
open(os.path.join(app, "private", "setup.key"), "w").write(key)
httplog = os.path.join(tmp, "http.log")
env = dict(os.environ, WSC_TEST_HTTP_STUB="1", WSC_TEST_HTTP_LOG=httplog, WSC_TEST_FIXTURES=fixtures)
port = free_port()
server = subprocess.Popen(["php", "-d", "auto_prepend_file=" + os.path.join(ROOT, "tests", "http-stub.php"),
                           "-S", f"127.0.0.1:{port}", "-t", tmp], env=env,
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
BASE = f"http://127.0.0.1:{port}/line-api/"
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None


def req(path, data=None, headers=None, method=None, follow=True):
    url = BASE + path
    body = None
    if isinstance(data, dict):
        body = urllib.parse.urlencode(data, doseq=True).encode()
    elif isinstance(data, (bytes, str)):
        body = data.encode() if isinstance(data, str) else data
    r = urllib.request.Request(url, data=body, headers=headers or {}, method=method)
    try:
        resp = opener.open(r, timeout=20)
        return resp.status, resp.read(), dict(resp.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read(), dict(e.headers)


def webhook(events, secret=SECRET, sig=None):
    body = json.dumps({"destination": "Uxxxx", "events": events}, ensure_ascii=False).encode()
    s = sig if sig is not None else base64.b64encode(hmac.new(secret.encode(), body, hashlib.sha256).digest()).decode()
    return req("webhook.php", body, {"Content-Type": "application/json", "X-Line-Signature": s})


n = [0]


def ev(user, kind, **kw):
    n[0] += 1
    e = {"webhookEventId": kw.pop("eid", f"01TESTEVENT{n[0]:06d}"), "timestamp": int(time.time() * 1000),
         "source": {"type": "user", "userId": user}, "replyToken": f"rt{n[0]}", "mode": "active",
         "deliveryContext": {"isRedelivery": kw.pop("redelivery", False)}}
    if kind == "follow":
        e["type"] = "follow"
    elif kind == "text":
        e.update(type="message", message={"type": "text", "id": str(5000 + n[0]), "text": kw["text"]})
    elif kind == "image":
        e.update(type="message", message={"type": "image", "id": kw["mid"], "contentProvider": {"type": "line"}})
    return e


def replies():
    out = []
    if os.path.exists(httplog):
        for line in open(httplog, encoding="utf-8"):
            d = json.loads(line)
            if d["u"].endswith("/message/reply"):
                b = json.loads(d["b"]); out.append((b["replyToken"], [m["text"] for m in b["messages"]]))
    return out


def store():
    return json.load(open(os.path.join(app, "private", "data", "store.json"), encoding="utf-8"))


try:
    for _ in range(50):
        try:
            socket.create_connection(("127.0.0.1", port), timeout=0.2).close(); break
        except OSError:
            time.sleep(0.1)

    # --- 初期設定前 ---
    st, body, _ = webhook([])
    check(st == 503, "未設定のWebhookは503")
    st, body, _ = req("feed.php")
    check(st == 200 and json.loads(body)["posts"] == [], "未設定でも公開APIは空配列")
    st, _, _ = req("setup.php?key=wrong")
    check(st == 404, "setupは鍵が違えば404")

    # --- 初期設定 ---
    st, body, _ = req("setup.php", {"key": key, "channel_id": "2001234567", "channel_secret": SECRET,
                                    "channel_access_token": "", "password": PASSWORD, "password2": PASSWORD})
    check(st == 200 and "接続を確認しました".encode() in body, "setupで設定保存・LINE接続確認")
    check(not os.path.exists(os.path.join(app, "private", "setup.key")), "setup.keyは保存後に削除")
    st, _, _ = req(f"setup.php?key={key}")
    check(st == 404, "設定後はsetup無効")
    cfg = open(os.path.join(app, "private", "config.local.php"), encoding="utf-8").read()
    check(PASSWORD not in cfg and "password_hash" in cfg, "管理パスワードはハッシュで保存")

    # --- 署名 ---
    st, _, _ = webhook([ev(UA, "text", text="x")], sig="invalid")
    check(st == 401, "不正署名は401で拒否")
    st, _, _ = webhook([ev(UA, "text", text="x")], secret="f" * 32)
    check(st == 401, "別シークレットの署名も拒否")
    st, _, _ = webhook([])
    check(st == 200, "検証用の空イベントは200")

    # --- 友だち追加 ---
    webhook([ev(UA, "follow")])
    check(any("ようこそ" in t for _, ts in replies() for t in ts), "友だち追加で案内を返信")

    # --- 投稿A（文章→写真2枚→送信）と投稿B（写真のみ）を交互に ---
    textA = "11月20日\nマダイ 3匹\n和歌山沖\n今日は朝からよく釣れました！\n投稿者：山田"
    st, _, _ = webhook([ev(UA, "text", text=textA)])
    check(st == 200, "テキスト受信")
    imgA1 = ev(UA, "image", mid="1001")
    webhook([imgA1, ev(UB, "image", mid="2001")])
    st, _, _ = webhook([imgA1], )  # 同一イベントの再送
    check(st == 200, "重複イベントも200で受理")
    webhook([ev(UA, "image", mid="1002", redelivery=True)])
    s = store()
    da, db = s["drafts"], s["drafts"]
    check(len(s["drafts"]) == 2, "2人の下書きが別々に存在")
    posts = list(s["posts"].values())
    pa = [p for p in posts if len(p["photos"]) == 2]
    pb = [p for p in posts if len(p["photos"]) == 1]
    check(len(pa) == 1 and len(pb) == 1, "再送された写真を二重登録せず、AとBが混ざらない")
    check(all(p["status"] == "draft" for p in posts), "送信前は draft")
    starts = [ts for _, ts in replies() if any("続けて写真" in t for t in ts)]
    check(len(starts) == 2, "下書き開始の案内は1人1回だけ")
    check(UA not in json.dumps(s) or all(UA == p.get("_line_user") for p in posts if UA in json.dumps(p)), "LINE IDは下書きの一時項目以外に残さない")

    webhook([ev(UA, "text", text="送信")])
    webhook([ev(UA, "text", text="送信")])
    s = store()
    pa = s["posts"][pa[0]["id"]]
    check(pa["status"] == "pending", "送信で pending に確定")
    check(pa["fish"] == "マダイ 3匹" and pa["author"] == "山田" and pa["caught_on"].endswith("-11-20"), "魚種・投稿者・釣行日を抽出")
    check("和歌山沖" in pa["comment"] and "投稿者" not in pa["comment"], "コメント抽出")
    check("_line_user" not in pa, "確定後はLINE IDを保持しない")
    rec = [ts for _, ts in replies() if any("受け付けました" in t for t in ts)]
    check(len(rec) == 1, "受付メッセージは1回だけ")
    check(any("送信する内容がまだありません" in t for _, ts in replies() for t in ts), "2回目の送信は案内のみ")

    time.sleep(1.1)
    webhook([ev(UB, "text", text="完了")])
    pb = store()["posts"][pb[0]["id"]]
    check(pb["status"] == "pending" and pb["author"] == "" and pb["comment"] == "", "投稿者名なし・写真のみでも受付")

    # --- 6枚目は拒否・偽画像は拒否・文章のみ投稿 ---
    for i in range(3001, 3007):
        webhook([ev(UB, "image", mid=str(i))])
    s = store(); d = s["posts"][s["drafts"][UB and list(s["drafts"].keys())[0]]]
    check(len(d["photos"]) == 5, "写真は1投稿5枚まで")
    check(any("5枚まで" in t for _, ts in replies() for t in ts), "6枚目で上限案内")
    webhook([ev(UB, "text", text="取消")])
    check(not store()["drafts"], "取消で下書き削除")
    webhook([ev(UB, "image", mid="9999")])
    check(any("保存できませんでした" in t for _, ts in replies() for t in ts) and not store()["drafts"], "画像でないファイルは拒否")
    webhook([ev(UB, "text", text="アジ 20匹\n夕まずめに入れ食い"), ev(UB, "text", text="送信")])
    textonly = [p for p in store()["posts"].values() if p["status"] == "pending" and not p["photos"]]
    check(len(textonly) == 1 and textonly[0]["fish"] == "アジ 20匹", "文章のみの投稿も受付")

    # --- 未承認は非公開 ---
    st, body, _ = req("feed.php")
    check(json.loads(body)["posts"] == [], "未承認は公開APIに出ない")
    st, _, _ = req(f"photo.php?p={pa['id']}&i=0&s=m")
    check(st == 404, "未承認写真はURL直打ちでも見えない")
    st, body, _ = req("admin/")
    check("ログイン".encode() in body and "承認待ち".encode() not in body, "管理画面はログイン必須")

    # --- 管理画面 ---
    csrf = re.search(rb'name="csrf" value="([0-9a-f]+)"', body).group(1).decode()
    st, body, _ = req("admin/", {"csrf": csrf, "action": "login", "password": "wrong"})
    check("パスワードが違います".encode() in body, "誤パスワードは拒否")
    st, body, _ = req("admin/")
    csrf = re.search(rb'name="csrf" value="([0-9a-f]+)"', body).group(1).decode()
    st, body, _ = req("admin/", {"csrf": csrf, "action": "login", "password": PASSWORD})
    check("承認待ち（3）".encode() in body, "ログイン後に承認待ち一覧")
    csrf = re.search(rb'name="csrf" value="([0-9a-f]+)"', body).group(1).decode()
    st, _, _ = req(f"photo.php?p={pa['id']}&i=0&s=m")
    check(st == 200, "管理者は未承認写真をプレビュー可")
    st, body, _ = req("admin/", {"csrf": "bad", "action": "publish", "id": pa["id"]})
    check(store()["posts"][pa["id"]]["status"] == "pending", "CSRFトークン不一致は拒否")
    form = {"csrf": csrf, "action": "publish", "id": pa["id"], "fish": "マダイ 3匹", "caught_on": pa["caught_on"],
            "comment": "和歌山沖\n<script>alert(1)</script>", "author": "山田"}
    req("admin/", form)
    req("admin/", {"csrf": csrf, "action": "publish", "id": pb["id"], "fish": "", "caught_on": "", "comment": "", "author": ""})
    st, body, hdr = req("feed.php", headers={"Origin": "https://oosaka0123-sudo.github.io"})
    feed = json.loads(body)
    check(len(feed["posts"]) == 2, "承認した2件だけ公開")
    check(hdr.get("Access-Control-Allow-Origin") == "https://oosaka0123-sudo.github.io", "CORSは公式サイトのみ許可")
    st, body2, hdr2 = req("feed.php", headers={"Origin": "https://evil.example"})
    check("Access-Control-Allow-Origin" not in hdr2, "他サイトにはCORS許可を出さない")
    raw = json.dumps(feed)
    check(UA not in raw and UB not in raw and "user_key" not in raw and "texts" not in raw, "公開APIにLINE IDや内部情報を出さない")
    fa = [p for p in feed["posts"] if p["id"] == pa["id"]][0]
    fb = [p for p in feed["posts"] if p["id"] == pb["id"]][0]
    check(len(fa["photos"]) == 2 and fa["author"] == "山田", "複数写真と投稿者名")
    check("author" not in fb and "comment" not in fb and "fish" not in fb, "未入力項目は出力しない")
    check(feed["posts"][0]["id"] == pb["id"], "新しい釣果が先頭（釣行日なしは受付日）")
    st, img, hdr = req(fa["photos"][0]["l"].replace(f"http://127.0.0.1:{port}/line-api/", ""))
    check(st == 200 and hdr.get("Content-Type") == "image/jpeg", "公開写真を配信")
    from PIL import Image
    im = Image.open(io.BytesIO(img))
    check(len(im.getexif()) == 0 and b"Exif" not in img[:2000], "公開写真にEXIF/GPSなし")
    st, img, _ = req(fb["photos"][0]["l"].replace(f"http://127.0.0.1:{port}/line-api/", ""))
    check(max(Image.open(io.BytesIO(img)).size) == 2400, "大きな写真は長辺2400pxに調整")

    # 写真を1枚だけ非掲載
    form.update(action="publish", hide=["1"])
    req("admin/", form)
    fa = [p for p in json.loads(req("feed.php")[1])["posts"] if p["id"] == pa["id"]][0]
    check(len(fa["photos"]) == 1, "写真ごとの非掲載")
    # 非ログインの別クライアントで非掲載写真を確認
    try:
        urllib.request.urlopen(BASE + f"photo.php?p={pa['id']}&i=1&s=m", timeout=5); hidden_status = 200
    except urllib.error.HTTPError as e:
        hidden_status = e.code
    check(hidden_status == 404, "非掲載にした写真は一般に見えない")

    # 非公開・削除
    req("admin/", {"csrf": csrf, "action": "reject", "id": pb["id"], "fish": "", "caught_on": "", "comment": "", "author": ""})
    check(len(json.loads(req("feed.php")[1])["posts"]) == 1, "非公開にすると公開APIから消える")
    files_before = os.listdir(os.path.join(app, "private", "photos"))
    req("admin/", {"csrf": csrf, "action": "delete", "id": pb["id"]})
    check(pb["id"] in store()["posts"], "確認チェックなしでは削除しない")
    req("admin/", {"csrf": csrf, "action": "delete", "id": pb["id"], "confirm": "yes"})
    check(pb["id"] not in store()["posts"], "確認後に削除")
    check(len(os.listdir(os.path.join(app, "private", "photos"))) == len(files_before) - 2, "削除で写真ファイルも消える")

    # 放置下書きの自動確定
    webhook([ev(UB, "image", mid="1001")])
    s = store(); did = s["drafts"][list(s["drafts"].keys())[0]]
    path = os.path.join(app, "private", "data", "store.json")
    s["posts"][did]["last_activity"] -= 4000
    json.dump(s, open(path, "w", encoding="utf-8"), ensure_ascii=False)
    os.utime(os.path.join(app, "private", "data", "tick.stamp"), (0, 0))
    st, body, _ = req("tick.php")
    check(st == 200 and json.loads(body) == {"ok": True}, "tick.phpは情報を返さない")
    check(store()["posts"][did]["status"] == "pending" and not store()["drafts"], "Webhook・管理画面なしでも1時間放置の下書きは承認待ちへ（tick）")
    pushes = [l for l in open(httplog, encoding="utf-8") if json.loads(l)["u"].endswith("/message/push")]
    check(len(pushes) == 1, "自動確定時のみプッシュで受付通知")

    # 59分では確定しない／feed表示時にも確定される
    webhook([ev(UB, "image", mid="1002")])
    s = store(); did2 = s["drafts"][list(s["drafts"].keys())[0]]
    s["posts"][did2]["last_activity"] -= 3540
    json.dump(s, open(path, "w", encoding="utf-8"), ensure_ascii=False)
    os.utime(os.path.join(app, "private", "data", "tick.stamp"), (0, 0))
    req("tick.php")
    check(store()["posts"][did2]["status"] == "draft", "59分では自動確定しない")
    s = store(); s["posts"][did2]["last_activity"] -= 120
    json.dump(s, open(path, "w", encoding="utf-8"), ensure_ascii=False)
    os.utime(os.path.join(app, "private", "data", "tick.stamp"), (0, 0))
    req("feed.php")
    check(store()["posts"][did2]["status"] == "pending", "公開API表示時にも自動確定（予備）")
    try:
        urllib.request.urlopen(BASE + f"photo.php?p={did2}&i=0&s=l", timeout=5); anon = 200
    except urllib.error.HTTPError as e:
        anon = e.code
    check(anon == 404, "自動確定後も未承認写真は外部から見えない")

    # XSS
    st, body, _ = req(f"admin/?id={pa['id']}")
    check(b"<script>alert(1)</script>" not in body and b"&lt;script&gt;" in body, "管理画面でエスケープ")
    st, _, _ = req("lib/core.php")
    check(st == 403, "lib直接実行は403")
    print(f"\nALL {passed} CHECKS PASSED")
finally:
    server.terminate()
    if not os.environ.get("KEEP"): shutil.rmtree(tmp, ignore_errors=True)
    else: print("TMP", tmp)
