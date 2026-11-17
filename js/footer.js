/* Shared WSC footer for all public pages. Edit this file to update every footer. */
(function () {
  'use strict';
  var target = document.querySelector('footer[data-wsc-footer]');
  if (!target) return;

  // Resolves the site root from this script's own URL, so pages in subfolders
  // and future custom-domain deployments use the same assets and links.
  var base = new URL('../', document.currentScript.src).href;
  target.innerHTML = [
    '<div class="wrap">',
      '<a class="brand" href="' + base + '" aria-label="和歌山雑賀クラブ トップページへ">',
        '<img src="' + base + 'assets/logo-wsc.svg" width="64" height="40" alt="WSC 船のロゴ">',
        '<div><small>WAKAYAMA SAIKA CLUB</small><span>和歌山雑賀クラブ</span></div>',
      '</a>',
      '<p class="footer-about">1981年設立 ／ 会長：東 秀樹<br>「仲良く、安全に釣りを楽しむ」</p>',
      '<p class="copyright">© 2026 WAKAYAMA SAIKA CLUB. ALL RIGHTS RESERVED.</p>',
      '<p class="powered-by">Powered by <a href="https://chatgpt.com/" target="_blank" rel="noopener noreferrer">チャットホームページ</a><span aria-hidden="true"> · </span><a href="https://rss7.net/" target="_blank" rel="noopener noreferrer">rss7.net</a></p>',
    '</div>'
  ].join('');
})();
