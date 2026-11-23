/* Windy map tabs for the Tomogashima forecast page. No third-party API keys. */
(function () {
  "use strict";
  var iframe = document.getElementById("tw-windy-map");
  if (!iframe) return;
  var badge = document.getElementById("tw-windy-badge");
  var model = document.getElementById("tw-windy-model");
  var open = document.getElementById("tw-windy-open");
  var loader = document.getElementById("tw-windy-loader");
  var loaderText = document.getElementById("tw-windy-loader-text");
  var layerButtons = document.querySelectorAll("[data-windy-layer]");
  var unitButtons = document.querySelectorAll("[data-windy-unit]");
  var lat = "34.281", lon = "135.001", zoom = "10";
  var selectedLayer = "wind", selectedUnit = "kt", fallbackTimer = null;
  var layers = {
    wind: { label: "風", overlay: "wind", product: "ecmwf", model: "ECMWF" },
    waves: { label: "波", overlay: "waves", product: "ecmwfWaves", model: "ECMWF Waves" },
    currents: { label: "海流", overlay: "currents", product: "cmems", model: "CMEMS 海流" }
  };
  function hideLoader() {
    clearTimeout(fallbackTimer);
    loader.hidden = true;
  }
  function showLoader(label) {
    loaderText.textContent = "「" + label + "」を読み込み中…";
    loader.hidden = false;
    clearTimeout(fallbackTimer);
    // The iframe is cross-origin; it cannot reliably report Windy's inner map status.
    fallbackTimer = setTimeout(hideLoader, 10000);
  }
  iframe.addEventListener("load", function () { setTimeout(hideLoader, 250); });
  iframe.addEventListener("error", hideLoader);
  function repaint() {
    layerButtons.forEach(function (button) {
      var active = button.getAttribute("data-windy-layer") === selectedLayer;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });
    unitButtons.forEach(function (button) {
      var active = button.getAttribute("data-windy-unit") === selectedUnit;
      button.classList.toggle("is-active", active);
      button.setAttribute("aria-pressed", String(active));
    });
    var chosen = layers[selectedLayer];
    badge.textContent = "表示中：" + chosen.label;
    model.textContent = "モデル：" + chosen.model;
    iframe.title = "Windy｜友ヶ島灯台付近の" + chosen.label + "の予報マップ";
    open.href = "https://www.windy.com/?" + chosen.overlay + "," + lat + "," + lon + "," + zoom;
  }
  function reload() {
    var chosen = layers[selectedLayer];
    var url = new URL("https://embed.windy.com/embed.html");
    var params = {
      type: "map", location: "coordinates", metricRain: "mm",
      metricTemp: "°C", metricWind: selectedUnit, zoom: zoom,
      overlay: chosen.overlay, product: chosen.product,
      level: "surface", lat: lat, lon: lon, message: "true"
    };
    Object.keys(params).forEach(function (key) { url.searchParams.set(key, params[key]); });
    showLoader(chosen.label);
    iframe.src = url.href;
  }
  layerButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      var next = button.getAttribute("data-windy-layer");
      if (!layers[next] || selectedLayer === next) return;
      selectedLayer = next;
      repaint();
      reload();
    });
  });
  unitButtons.forEach(function (button) {
    button.addEventListener("click", function () {
      var next = button.getAttribute("data-windy-unit");
      if ((next !== "kt" && next !== "m/s") || selectedUnit === next) return;
      selectedUnit = next;
      repaint();
      reload();
    });
  });
  repaint();
})();
