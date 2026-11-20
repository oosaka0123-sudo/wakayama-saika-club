/* 釣果情報：公開API（承認済みのみ）から読み込み、カード・写真切替・拡大表示を描画 */
(function () {
  "use strict";
  var list = document.getElementById("catch-list");
  var status = document.getElementById("catch-status");
  var empty = document.getElementById("catch-empty");
  if (!list || !status) return;

  var lineUrl = String(window.WSC_CATCH_LINE_URL || "");
  if (/^https:\/\/(lin\.ee|line\.me|page\.line\.me)\//.test(lineUrl)) {
    document.getElementById("catch-line-link").href = lineUrl;
    document.getElementById("catch-line").hidden = false;
  }

  var api = String(window.WSC_CATCH_API || "");
  var apiOrigin = null;
  try { if (api) apiOrigin = new URL(api, document.baseURI).origin; } catch (_) { api = ""; }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }
  function safeUrl(u) {
    try {
      var url = new URL(u, api);
      return url.origin === apiOrigin && (url.protocol === "https:" || location.protocol === "http:") ? url.href : null;
    } catch (_) { return null; }
  }
  function jpDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ""));
    return m ? (+m[1]) + "年" + (+m[2]) + "月" + (+m[3]) + "日" : "";
  }
  function showEmpty(msg) {
    list.replaceChildren();
    status.textContent = msg || "";
    if (empty) empty.hidden = false;
  }

  /* ---- 拡大表示（ギャラリーと同じダイアログ） ---- */
  var dialog = document.getElementById("photo-viewer");
  var vImg = document.getElementById("viewer-image");
  var vCap = document.getElementById("viewer-caption");
  var vCount = document.getElementById("viewer-count");
  var viewing = null, vIndex = 0, lastTrigger = null;
  function vShow(i) {
    vIndex = (i + viewing.photos.length) % viewing.photos.length;
    vImg.src = viewing.photos[vIndex];
    vImg.alt = viewing.alt + "（" + (vIndex + 1) + "枚目）";
    vCap.textContent = viewing.caption;
    vCount.textContent = (vIndex + 1) + " / " + viewing.photos.length;
    var multi = viewing.photos.length > 1;
    dialog.querySelector(".viewer-prev").style.visibility = multi ? "" : "hidden";
    dialog.querySelector(".viewer-next").style.visibility = multi ? "" : "hidden";
  }
  function openViewer(data, index, trigger) {
    if (!dialog || typeof dialog.showModal !== "function") { window.open(data.photos[index], "_blank", "noopener"); return; }
    viewing = data; lastTrigger = trigger; vShow(index); dialog.showModal();
    dialog.querySelector(".viewer-close").focus();
  }
  if (dialog) {
    dialog.querySelector(".viewer-close").addEventListener("click", function () { dialog.close(); });
    dialog.querySelector(".viewer-prev").addEventListener("click", function () { vShow(vIndex - 1); });
    dialog.querySelector(".viewer-next").addEventListener("click", function () { vShow(vIndex + 1); });
    dialog.addEventListener("click", function (e) { if (e.target === dialog) dialog.close(); });
    dialog.addEventListener("keydown", function (e) {
      if (!viewing) return;
      if (e.key === "ArrowLeft") { e.preventDefault(); vShow(vIndex - 1); }
      if (e.key === "ArrowRight") { e.preventDefault(); vShow(vIndex + 1); }
    });
    dialog.addEventListener("close", function () { vImg.src = "data:,"; if (lastTrigger) lastTrigger.focus(); });
  }

  function card(post) {
    var li = el("li", "catch-card");
    var art = el("article");
    art.style.display = "contents";
    var fish = String(post.fish || "").slice(0, 60);
    var dateLabel = post.caught_on ? jpDate(post.caught_on) : (jpDate(post.posted_at) ? jpDate(post.posted_at) + " 投稿" : "");
    var alt = (fish ? fish + "の" : "") + "釣果写真";
    var photos = (Array.isArray(post.photos) ? post.photos : []).map(function (p) {
      return { m: safeUrl(p.m), l: safeUrl(p.l) };
    }).filter(function (p) { return p.m && p.l; });

    var media = el("div", "catch-media");
    if (photos.length) {
      var track = el("div", "catch-track");
      track.tabIndex = -1;
      var data = { photos: photos.map(function (p) { return p.l; }), alt: alt, caption: [dateLabel, fish].filter(Boolean).join("　") || "釣果写真" };
      photos.forEach(function (p, i) {
        var b = el("button", "catch-shot");
        b.type = "button";
        b.setAttribute("aria-label", alt + "を拡大" + (photos.length > 1 ? "（" + (i + 1) + "/" + photos.length + "）" : ""));
        var bg = el("span", "bg"); bg.style.backgroundImage = "url(\"" + p.m.replace(/"/g, "%22") + "\")";
        var img = document.createElement("img");
        img.src = p.m; img.alt = alt; img.loading = "lazy"; img.decoding = "async";
        b.appendChild(bg); b.appendChild(img);
        b.addEventListener("click", function () { openViewer(data, i, b); });
        track.appendChild(b);
      });
      media.appendChild(track);
      media.appendChild(el("span", "catch-zoom", "＋")).setAttribute("aria-hidden", "true");
      if (photos.length > 1) {
        var count = el("span", "catch-count", "1 / " + photos.length);
        var prev = el("button", "catch-nav prev", "‹"), next = el("button", "catch-nav next", "›");
        prev.type = next.type = "button";
        prev.setAttribute("aria-label", "前の写真"); next.setAttribute("aria-label", "次の写真");
        var current = function () { return Math.round(track.scrollLeft / Math.max(1, track.clientWidth)); };
        var update = function () {
          var i = current();
          count.textContent = (i + 1) + " / " + photos.length;
          prev.disabled = i <= 0; next.disabled = i >= photos.length - 1;
        };
        var go = function (d) { track.scrollTo({ left: (current() + d) * track.clientWidth, behavior: "smooth" }); };
        prev.addEventListener("click", function () { go(-1); });
        next.addEventListener("click", function () { go(1); });
        track.addEventListener("scroll", function () { window.requestAnimationFrame(update); }, { passive: true });
        media.appendChild(prev); media.appendChild(next); media.appendChild(count);
        update();
      }
    } else {
      var no = el("div", "catch-noimg");
      var logo = document.createElement("img");
      logo.src = "../assets/logo-wsc.svg"; logo.alt = ""; logo.width = 84; logo.height = 52;
      no.appendChild(logo);
      media.appendChild(no);
    }
    art.appendChild(media);

    var body = el("div", "catch-body");
    if (dateLabel) {
      var t = el("time", "catch-date", dateLabel);
      t.dateTime = String(post.caught_on || post.posted_at || "").slice(0, 10);
      body.appendChild(t);
    }
    if (fish) body.appendChild(el("h3", "catch-fish", fish));
    if (post.comment) body.appendChild(el("p", "catch-comment", String(post.comment).slice(0, 600)));
    if (post.author) body.appendChild(el("p", "catch-author", "投稿者：" + String(post.author).slice(0, 30)));
    art.appendChild(body);
    li.appendChild(art);
    return li;
  }

  if (!api) { showEmpty(""); return; }
  fetch(new URL("feed.php", api).href, { mode: "cors", credentials: "omit", headers: { Accept: "application/json" } })
    .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); })
    .then(function (data) {
      var posts = data && Array.isArray(data.posts) ? data.posts : [];
      if (!posts.length) { showEmpty(""); return; }
      var frag = document.createDocumentFragment();
      posts.forEach(function (p) { frag.appendChild(card(p)); });
      list.replaceChildren(frag);
      status.textContent = "";
      if (empty) empty.hidden = true;
    })
    .catch(function () { showEmpty("釣果情報を読み込めませんでした。時間をおいて再度お試しください。"); });
})();
