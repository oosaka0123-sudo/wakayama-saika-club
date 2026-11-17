(function () {
  'use strict';

  var video = document.querySelector('.about-hero-video[data-video-parts]');
  if (!video) return;

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  if (reduce && reduce.matches) return;

  var count = Number(video.getAttribute('data-video-parts')) || 0;
  if (!count) return;

  var base = new URL('../assets/about-video-parts/', document.currentScript.src);
  var urls = [];
  for (var i = 0; i < count; i += 1) {
    urls.push(new URL('part' + String(i).padStart(2, '0') + '.bin', base).href);
  }

  Promise.all(urls.map(function (url) {
    return fetch(url, { cache: 'force-cache' }).then(function (response) {
      if (!response.ok) throw new Error('video chunk load failed: ' + response.status);
      return response.arrayBuffer();
    });
  })).then(function (buffers) {
    var blob = new Blob(buffers, { type: 'video/mp4' });
    var objectUrl = URL.createObjectURL(blob);
    video.src = objectUrl;
    video.load();
    var play = video.play();
    if (play && typeof play.catch === 'function') play.catch(function () {});
    window.addEventListener('pagehide', function () { URL.revokeObjectURL(objectUrl); }, { once: true });
  }).catch(function () {
    // Keep the poster image visible if the animation cannot be assembled.
  });
})();