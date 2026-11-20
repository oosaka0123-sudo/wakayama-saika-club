/* 和歌山雑賀クラブ／所在地特設ページ
 * Google等で制作したドローン動画を受け取った後、このURLだけを設定します。
 * 指定したファイルをassets/videos/に置いてから公開してください。
 * 例: const VIDEO_URL = "../assets/videos/wsc-marina-drone.mp4";
 * 動画未入手の現時点では画像フォールバックを表示。存在しないURLへの通信は行いません。
 */
(function () {
  "use strict";
  const VIDEO_URL = "";
  const video = document.getElementById("wsc-location-video");
  if (!video || !VIDEO_URL) return;
  video.src = VIDEO_URL;
  video.addEventListener("loadeddata", function () {
    video.classList.add("is-ready");
    video.parentElement.classList.add("has-video");
    const label = document.querySelector(".location-hero-caption");
    if (label) label.hidden = true;
  }, { once: true });
  const play = video.play();
  if (play && typeof play.catch === "function") play.catch(function () {});
})();
