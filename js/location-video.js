/* 和歌山雑賀クラブ／所在地特設ページの背景映像
 * 利用許諾を確認した実際の空撮映像を次のパスに配置するだけで自動反映します。
 * assets/videos/wsc-marina-drone.mp4
 * 映像がない場合・再生できない場合は既存の写真演出を表示します。
 * 現状、動画ファイルは未設置です（存在しない映像を掲載済みと扱わない）。
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
      if (caption) caption.hidden = true;
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
