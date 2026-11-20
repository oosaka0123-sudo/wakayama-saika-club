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
UC = "U" + "c" * 32
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
TICK = "t" * 48
open(os.path.join(app, "private", "tick.key"), "w").write(hashlib.sha256(TICK.encode()).hexdigest())
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
    check(any("ようこそ" in t and "合言葉を、そのまま" in t for _, ts in replies() for t in ts), "友だち追加で登録方法を案内")

    def admin_csrf():
        st, body, _ = req("admin/")
        return re.search(rb'name="csrf" value="([0-9a-f]+)"', body).group(1).decode(), body

    # --- 会員登録前は投稿できない（友だち追加だけで公開されない） ---
    webhook([ev(UA, "text", text="umihiko")])
    check(any("準備中" in t for _, ts in replies() for t in ts) and not store()["members"], "合言葉未設定のあいだは誰も登録できない")
    webhook([ev(UC, "image", mid="1001"), ev(UC, "text", text="荒らし"), ev(UC, "text", text="送信")])
    s = store()
    check(not s["posts"] and not s["drafts"], "未登録ユーザーの写真・文章は保存しない")
    check(not os.listdir(os.path.join(app, "private", "photos")), "未登録ユーザーの写真ファイルも残らない")
    check(any("会員登録が必要" in t for _, ts in replies() for t in ts), "未登録ユーザーには登録方法を返信")

    # --- 管理画面ログイン・合言葉設定 ---
    st, body, _ = req("admin/")
    check("ログイン".encode() in body and "掲載中".encode() not in body, "管理画面はログイン必須")
    csrf, _ = admin_csrf()
    st, body, _ = req("admin/", {"csrf": csrf, "action": "login", "password": "wrong"})
    check("パスワードが違います".encode() in body, "誤パスワードは拒否")
    csrf, _ = admin_csrf()
    st, body, _ = req("admin/", {"csrf": csrf, "action": "login", "password": PASSWORD})
    check("合言葉が未設定".encode() in body, "ログイン後、合言葉未設定を警告")
    csrf, _ = admin_csrf()
    req("admin/", {"csrf": "bad", "action": "set_passphrase", "passphrase": "x1234"})
    check("passphrase_hash" not in store()["settings"], "CSRFトークン不一致は拒否")
    req("admin/", {"csrf": csrf, "action": "set_passphrase", "passphrase": "Saika1981"})
    check(store()["settings"].get("passphrase_hash", "").startswith("$"), "合言葉はハッシュで保存")
    check("Saika1981" not in open(os.path.join(app, "private", "data", "store.json"), encoding="utf-8").read(), "合言葉の平文は保存しない")

    # --- 登録（誤り回数制限つき） ---
    webhook([ev(UC, "text", text="wrong")])
    check(not store()["members"] and any("合言葉が違います" in t for _, ts in replies() for t in ts), "誤った合言葉は登録しない")
    for _ in range(5):
        webhook([ev(UC, "text", text="wrong")])
    webhook([ev(UC, "text", text="Saika1981")])
    check(not store()["members"] and any("本日は登録できません" in t for _, ts in replies() for t in ts), "失敗5回で当日は正しい合言葉でも登録不可")
    webhook([ev(UA, "text", text="ＳＡＩＫＡ１９８１")])
    webhook([ev(UB, "text", text="登録：saika1981 山田")])
    s = store()
    check(len(s["members"]) == 2, "合言葉だけで登録でき、旧方式も使える")
    check(any(m.get("name") == "山田" for m in s["members"].values()), "登録時に任意の名前を記録")
    check(UA not in json.dumps(s["members"]) and UB not in json.dumps(s["members"]), "会員一覧にLINE IDを保存しない")

    # --- 投稿A（文章→写真2枚→送信）と投稿B（写真のみ）を交互に ---
    textA = "11月20日\nマダイ 3匹\n和歌山沖\n今日は朝からよく釣れました！\n投稿者：山田"
    st, _, _ = webhook([ev(UA, "text", text=textA)])
    check(st == 200, "テキスト受信")
    imgA1 = ev(UA, "image", mid="1001")
    webhook([imgA1, ev(UB, "image", mid="2001")])
    st, _, _ = webhook([imgA1])
    check(st == 200, "重複イベントも200で受理")
    webhook([ev(UA, "image", mid="1002", redelivery=True)])
    s = store()
    check(len(s["drafts"]) == 2, "2人の下書きが別々に存在")
    posts = list(s["posts"].values())
    pa = [p for p in posts if len(p["photos"]) == 2]
    pb = [p for p in posts if len(p["photos"]) == 1]
    check(len(pa) == 1 and len(pb) == 1, "再送された写真を二重登録せず、AとBが混ざらない")
    check(all(p["status"] == "draft" for p in posts), "送信前は draft（非公開）")
    st, body, _ = req("feed.php")
    check(json.loads(body)["posts"] == [], "送信前は公開APIに出ない")
    try:
        urllib.request.urlopen(BASE + f"photo.php?p={pa[0]['id']}&i=0&s=m", timeout=5); anon = 200
    except urllib.error.HTTPError as e:
        anon = e.code
    check(anon == 404, "送信前の写真はURL直打ちでも見えない")
    starts = [ts for _, ts in replies() if any("続けて写真" in t for t in ts)]
    check(len(starts) == 2, "下書き開始の案内は1人1回だけ")

    webhook([ev(UA, "text", text="送信")])
    webhook([ev(UA, "text", text="送信")])
    s = store()
    pa = s["posts"][pa[0]["id"]]
    check(pa["status"] == "published" and pa.get("published_at"), "送信ですぐ published（自動掲載）")
    check(pa["fish"] == "マダイ 3匹" and pa["author"] == "山田" and pa["caught_on"].endswith("-11-20"), "魚種・投稿者・釣行日を抽出")
    check("和歌山沖" in pa["comment"] and "投稿者" not in pa["comment"], "コメント抽出")
    check("_line_user" not in pa, "確定後はLINE IDを保持しない")
    rec = [ts for _, ts in replies() if any("掲載しました" in t for t in ts)]
    check(len(rec) == 1, "掲載メッセージは1回だけ")
    check(any("送信する内容がまだありません" in t for _, ts in replies() for t in ts), "2回目の送信は案内のみ")
    time.sleep(1.1)
    webhook([ev(UB, "text", text="完了")])
    pb = store()["posts"][pb[0]["id"]]
    check(pb["status"] == "published" and pb["author"] == "" and pb["comment"] == "", "投稿者名なし・写真のみでも自動掲載")

    st, body, hdr = req("feed.php", headers={"Origin": "https://oosaka0123-sudo.github.io"})
    feed = json.loads(body)
    check(len(feed["posts"]) == 2, "送信した2件が公開APIに出る")
    check(hdr.get("Access-Control-Allow-Origin") == "https://oosaka0123-sudo.github.io", "CORSは公式サイトのみ許可")
    st, body2, hdr2 = req("feed.php", headers={"Origin": "https://evil.example"})
    check("Access-Control-Allow-Origin" not in hdr2, "他サイトにはCORS許可を出さない")
    raw = json.dumps(feed)
    check(UA not in raw and UB not in raw and "user_key" not in raw and "texts" not in raw, "公開APIにLINE IDや内部情報を出さない")
    fa = [p for p in feed["posts"] if p["id"] == pa["id"]][0]
    fb = [p for p in feed["posts"] if p["id"] == pb["id"]][0]
    check(len(fa["photos"]) == 2 and fa["author"] == "山田", "複数写真と投稿者名")
    check("author" not in fb and "comment" not in fb and "fish" not in fb, "未入力項目は出力しない")
    check(feed["posts"][0]["id"] == pb["id"], "新しい釣果が先頭")
    st, img, hdr = req(fa["photos"][0]["l"].replace(f"http://127.0.0.1:{port}/line-api/", ""))
    check(st == 200 and hdr.get("Content-Type") == "image/jpeg", "公開写真を配信")
    from PIL import Image
    im = Image.open(io.BytesIO(img))
    check(len(im.getexif()) == 0 and b"Exif" not in img[:2000], "公開写真にEXIF/GPSなし")
    st, img, _ = req(fb["photos"][0]["l"].replace(f"http://127.0.0.1:{port}/line-api/", ""))
    check(max(Image.open(io.BytesIO(img)).size) == 2400, "大きな写真は長辺2400pxに調整")

    # --- 6枚目は拒否・偽画像は拒否・文章のみ投稿・取消 ---
    for i in range(3001, 3007):
        webhook([ev(UB, "image", mid=str(i))])
    s = store(); d = s["posts"][list(s["drafts"].values())[0]]
    check(len(d["photos"]) == 5, "写真は1投稿5枚まで")
    check(any("5枚まで" in t for _, ts in replies() for t in ts), "6枚目で上限案内")
    webhook([ev(UB, "text", text="取消")])
    check(not store()["drafts"], "送信前の取消で下書き削除")
    webhook([ev(UB, "image", mid="9999")])
    check(any("保存できませんでした" in t for _, ts in replies() for t in ts) and not store()["drafts"], "画像でない危険なファイルは拒否")
    webhook([ev(UB, "text", text="アジ 20匹\n夕まずめに入れ食い"), ev(UB, "text", text="送信")])
    textonly = [p for p in store()["posts"].values() if not p["photos"] and p["status"] == "published"]
    check(len(textonly) == 1 and textonly[0]["fish"] == "アジ 20匹", "文章のみの投稿も掲載")

    # --- 管理画面：編集・写真ごとの非掲載・非公開・削除 ---
    csrf, body = admin_csrf()
    check("掲載中（3）".encode() in body, "管理画面に掲載中一覧")
    form = {"csrf": csrf, "action": "publish", "id": pa["id"], "fish": "マダイ 3匹", "caught_on": pa["caught_on"],
            "comment": "和歌山沖\n<script>alert(1)</script>", "author": "山田", "hide": ["1"]}
    req("admin/", form)
    fa = [p for p in json.loads(req("feed.php")[1])["posts"] if p["id"] == pa["id"]][0]
    check(len(fa["photos"]) == 1 and "<script>" in fa["comment"], "管理画面で修正・写真ごとの非掲載")
    try:
        urllib.request.urlopen(BASE + f"photo.php?p={pa['id']}&i=1&s=m", timeout=5); hidden_status = 200
    except urllib.error.HTTPError as e:
        hidden_status = e.code
    check(hidden_status == 404, "非掲載にした写真は一般に見えない")
    st, body, _ = req(f"admin/?id={pa['id']}")
    check(b"<script>alert(1)</script>" not in body and b"&lt;script&gt;" in body, "管理画面でエスケープ")
    req("admin/", {"csrf": csrf, "action": "reject", "id": pb["id"], "fish": "", "caught_on": "", "comment": "", "author": ""})
    check(pb["id"] not in [p["id"] for p in json.loads(req("feed.php")[1])["posts"]], "非公開にすると公開APIから消える")
    try:
        urllib.request.urlopen(BASE + f"photo.php?p={pb['id']}&i=0&s=m", timeout=5); rej = 200
    except urllib.error.HTTPError as e:
        rej = e.code
    check(rej == 404, "非公開にした写真は一般に見えない")
    files_before = os.listdir(os.path.join(app, "private", "photos"))
    req("admin/", {"csrf": csrf, "action": "delete", "id": pb["id"]})
    check(pb["id"] in store()["posts"], "確認チェックなしでは削除しない")
    req("admin/", {"csrf": csrf, "action": "delete", "id": pb["id"], "confirm": "yes"})
    check(pb["id"] not in store()["posts"], "確認後に削除")
    check(len(os.listdir(os.path.join(app, "private", "photos"))) == len(files_before) - 2, "削除で写真ファイルも消える")

    # --- 1時間後の自動確定（Webhook・管理画面なし）→ 自動掲載 ---
    path = os.path.join(app, "private", "data", "store.json")
    stamp = os.path.join(app, "private", "data", "tick.stamp")
    def age(pid, secs):
        s = store(); s["posts"][pid]["last_activity"] -= secs
        json.dump(s, open(path, "w", encoding="utf-8"), ensure_ascii=False)
        if os.path.exists(stamp): os.utime(stamp, (0, 0))
    webhook([ev(UB, "image", mid="1001")])
    did = list(store()["drafts"].values())[0]
    age(did, 3540)
    req("tick.php", headers={"X-WSC-Tick-Token": TICK})
    check(store()["posts"][did]["status"] == "draft", "59分では自動確定しない")
    age(did, 120)
    for hdrs in ({}, {"X-WSC-Tick-Token": "wrong"}):
        st, body, _ = req("tick.php", headers=hdrs)
        check(st == 403 and store()["posts"][did]["status"] == "draft", "tick.phpはトークンなし・誤りでは動かない(403)")
        if os.path.exists(stamp): os.utime(stamp, (0, 0))
    st, body, _ = req("tick.php", headers={"X-WSC-Tick-Token": TICK})
    check(st == 200 and json.loads(body) == {"ok": True}, "tick.phpは情報を返さない")
    check(store()["posts"][did]["status"] == "published" and not store()["drafts"], "Webhookなしでも1時間後に自動確定→掲載")
    check(did in [p["id"] for p in json.loads(req("feed.php")[1])["posts"]], "自動確定した投稿がホームページ用APIに出る")
    pushes = [json.loads(l) for l in open(httplog, encoding="utf-8") if json.loads(l)["u"].endswith("/message/push")]
    check(len(pushes) == 1 and "掲載しました" in pushes[0]["b"], "自動確定時のみプッシュで掲載通知")
    webhook([ev(UB, "image", mid="1002")])
    did2 = list(store()["drafts"].values())[0]
    age(did2, 3700)
    req("feed.php")
    check(store()["posts"][did2]["status"] == "published", "公開API表示時にも自動確定（予備）")

    # --- 管理画面でトークン再発行：旧トークンは無効、新トークンは画面に1回だけ表示 ---
    csrf, _ = admin_csrf()
    st, body, _ = req("admin/", {"csrf": csrf, "action": "tick_token"})
    m = re.search(rb'<code[^>]*>([0-9a-f]{64})</code>', body)
    check(m is not None, "管理画面でトークンを発行し1回表示")
    newtok = m.group(1).decode()
    st, body, _ = req("admin/?tab=settings")
    check(newtok.encode() not in body, "トークンは再表示されない")
    check(newtok not in open(os.path.join(app, "private", "tick.key")).read(), "サーバーにトークン平文を保存しない")
    if os.path.exists(stamp): os.utime(stamp, (0, 0))
    check(req("tick.php", headers={"X-WSC-Tick-Token": TICK})[0] == 403, "再発行後は旧トークン無効")
    if os.path.exists(stamp): os.utime(stamp, (0, 0))
    check(req("tick.php", headers={"X-WSC-Tick-Token": newtok})[0] == 200, "新トークンで実行可")

    # --- 会員停止・自動掲載停止・連続投稿対策 ---
    keyB = [k for k, m in store()["members"].items() if m.get("name") == "山田"][0]
    csrf, _ = admin_csrf()
    req("admin/", {"csrf": csrf, "action": "member_block", "key": keyB})
    webhook([ev(UB, "image", mid="1001"), ev(UB, "text", text="送信")])
    check(not store()["drafts"] and any("停止されています" in t for _, ts in replies() for t in ts), "停止した会員は投稿できない")
    req("admin/", {"csrf": csrf, "action": "member_unblock", "key": keyB})
    req("admin/", {"csrf": csrf, "action": "auto_publish", "value": "0"})
    webhook([ev(UA, "text", text="自動掲載停止中のテスト"), ev(UA, "text", text="送信")])
    held = [p for p in store()["posts"].values() if "停止中" in p.get("comment", "")]
    check(len(held) == 1 and held[0]["status"] == "pending", "自動掲載OFF中は承認待ちに入る")
    check(held[0]["id"] not in [p["id"] for p in json.loads(req("feed.php")[1])["posts"]], "承認待ちは公開されない")
    req("admin/", {"csrf": csrf, "action": "auto_publish", "value": "1"})
    check(store()["posts"][held[0]["id"]]["status"] == "pending", "自動掲載を再開しても既存の承認待ちは勝手に公開しない")
    before = len(store()["posts"])
    webhook([ev(UA, "text", text=f"連投{i}") for i in range(25)])
    s = store()
    d = s["posts"][s["drafts"][list(s["drafts"].keys())[0]]] if s["drafts"] else {"texts": []}
    check(len(d["texts"]) <= 20 and any("しばらく受付を停止" in t for _, ts in replies() for t in ts), "1分間の操作数を超えた分は受け付けない")
    webhook([ev(UA, "text", text="取消")])

    st, _, _ = req("lib/core.php")
    check(st == 403, "lib直接実行は403")
    print(f"\nALL {passed} CHECKS PASSED")
finally:
    server.terminate()
    if not os.environ.get("KEEP"): shutil.rmtree(tmp, ignore_errors=True)
    else: print("TMP", tmp)
