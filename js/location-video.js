/* 和歌山雑賀クラブ／所在地特設ページの背景映像
 * 提供されたマリーナのイメージ映像（10秒・無音）を表示します。
 * assets/videos/wsc-marina-drone.mp4
 * 再生できない場合や動きを減らす設定の場合は静止画を表示します。
 * 2026-11-20 提供された映像をサイト用に軽量化して設置済みです。
 */
(function () {
  "use strict";
  const VIDEO_URL = "../assets/videos/wsc-marina-drone.mp4";
  const video = document.getElementById("wsc-location-video");
  if (!video) return;
  if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;

  const caption = document.querySelector(".location-hero-caption");

  function restorePhoto() {
    video.classList.remove("is-ready");
    video.parentElement.classList.remove("has-video");
    if (caption) caption.hidden = false;
    video.removeAttribute("src");
    video.load();
  }

  function playVideo() {
    video.addEventListener("playing", function () {
      video.classList.add("is-ready");
      video.parentElement.classList.add("has-video");
      // 映像は実写の現地映像と確認できていないため、イメージ映像の注記を残す。
    }, { once: true });
    video.addEventListener("error", restorePhoto, { once: true });
    video.src = VIDEO_URL;
    const playback = video.play();
    if (playback && typeof playback.catch === "function") {
      playback.catch(restorePhoto);
    }
  }

  // GitHub Pages が HEAD に対応。未設置なら全映像のダウンロードを試みない。
  fetch(VIDEO_URL, { method: "HEAD", cache: "no-store" })
    .then(function (response) { if (response.ok) playVideo(); })
    .catch(function () { /* 接続不能時も写真演出を表示する */ });
})();
