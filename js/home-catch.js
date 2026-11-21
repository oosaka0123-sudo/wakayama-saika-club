/* Render exactly one latest public LINE catch on the homepage. */
(function () {
  "use strict";
  var slot = document.getElementById("home-latest-catch-content");
  if (!slot) return;
  var base = String(window.WSC_CATCH_API || "");
  var origin;
  try {
    var root = new URL(base, document.baseURI);
    if (root.protocol !== "https:") throw new Error("HTTPS required");
    origin = root.origin;
    base = root.href;
  } catch (_) {
    slot.textContent = "釣果情報を取得できませんでした。釣果一覧ページをご覧ください。";
    return;
  }
  function node(tag, klass, value) {
    var el = document.createElement(tag);
    if (klass) el.className = klass;
    if (value) el.textContent = value;
    return el;
  }
  function sameOriginPhoto(value) {
    try {
      var url = new URL(value, base);
      return url.protocol === "https:" && url.origin === origin ? url.href : "";
    } catch (_) { return ""; }
  }
  function displayDate(value) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ""));
    return m ? (+m[1]) + "年" + (+m[2]) + "月" + (+m[3]) + "日" : "";
  }
  function render(post) {
    var card = node("article", "hlc-card");
    var photoLink = node("a", "hlc-media");
    photoLink.href = "catch/";
    photoLink.setAttribute("aria-label", "最新釣果の詳細を見る");
    var first = Array.isArray(post.photos) ? post.photos[0] : null;
    var imageUrl = first && sameOriginPhoto(first.m || first.l || "");
    if (imageUrl) {
      var img = document.createElement("img");
      img.src = imageUrl;
      img.alt = "最新の釣果写真";
      img.loading = "lazy";
      img.decoding = "async";
      photoLink.appendChild(img);
    } else {
      photoLink.appendChild(node("span", "hlc-no-photo", "釣果写真"));
    }
    card.appendChild(photoLink);
    var body = node("div", "hlc-body");
    var date = displayDate(post.caught_on || post.posted_at);
    if (date) body.appendChild(node("time", "hlc-date", date));
    body.appendChild(node("h3", "", String(post.fish || "会員からの釣果報告").slice(0, 60)));
    if (post.comment) body.appendChild(node("p", "hlc-comment", String(post.comment).slice(0, 240)));
    if (post.author) body.appendChild(node("p", "hlc-author", "投稿者：" + String(post.author).slice(0, 30)));
    var link = node("a", "hlc-detail", "釣果を見る →");
    link.href = "catch/";
    body.appendChild(link);
    card.appendChild(body);
    slot.replaceChildren(card);
  }
  var running = false, lastLoad = 0;
  function refresh() {
    if (running) return;
    running = true;
    var feed = new URL("feed.php", base);
    feed.searchParams.set("v", String(Date.now()));
    fetch(feed.href, {
      mode: "cors", credentials: "omit", cache: "no-store",
      headers: { Accept: "application/json" }
    })
      .then(function (response) {
        if (!response.ok) throw new Error("HTTP " + response.status);
        return response.json();
      })
      .then(function (data) {
        var posts = data && Array.isArray(data.posts) ? data.posts : [];
        // Prefer newest publication timestamp; retain feed order if timestamps are absent.
        posts = posts.slice().sort(function (a, b) {
          var aa = Date.parse(a && a.posted_at || "") || 0;
          var bb = Date.parse(b && b.posted_at || "") || 0;
          return bb - aa;
        });
        if (posts.length) render(posts[0]);
        else slot.replaceChildren(node("p", "hlc-status", "現在、公開中の釣果はありません。"));
        lastLoad = Date.now();
      })
      .catch(function () {
        if (!slot.querySelector(".hlc-card")) {
          slot.replaceChildren(node("p", "hlc-status", "釣果情報を読み込めませんでした。釣果一覧ページをご覧ください。"));
        }
      })
      .finally(function () { running = false; });
  }
  refresh();
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden && Date.now() - lastLoad >= 10000) refresh();
  });
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) refresh();
  });
  window.setInterval(function () {
    if (!document.hidden && Date.now() - lastLoad >= 60000) refresh();
  }, 60000);
})();
