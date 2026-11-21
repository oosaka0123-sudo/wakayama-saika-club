/* Open-Meteo weather + marine forecasts for Nishihama; model estimates, not marine-navigation advice. */
(function () {
  "use strict";
  var root = document.getElementById("sea-forecast");
  if (!root) return;
  var status = document.getElementById("sf-status");
  var updated = document.getElementById("sf-info");
  var buttons = Array.prototype.slice.call(root.querySelectorAll("[data-sf-period]"));
  var fields = {
    weatherIcon: document.getElementById("sf-weather-icon"),
    weather: document.getElementById("sf-weather"),
    temp: document.getElementById("sf-temp"),
    rain: document.getElementById("sf-rain"),
    wind: document.getElementById("sf-wind"),
    windDir: document.getElementById("sf-wind-dir"),
    gust: document.getElementById("sf-gust"),
    wave: document.getElementById("sf-wave"),
    waveDir: document.getElementById("sf-wave-dir"),
    period: document.getElementById("sf-period")
  };
  var lat = "34.2089", lon = "135.1497";
  var wApi = new URL("https://api.open-meteo.com/v1/forecast");
  var mApi = new URL("https://marine-api.open-meteo.com/v1/marine");
  [wApi, mApi].forEach(function (u) {
    u.searchParams.set("latitude", lat);
    u.searchParams.set("longitude", lon);
    u.searchParams.set("timezone", "Asia/Tokyo");
    u.searchParams.set("forecast_days", "3");
  });
  wApi.searchParams.set("wind_speed_unit", "ms");
  wApi.searchParams.set("current", "temperature_2m,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m");
  wApi.searchParams.set("hourly", "temperature_2m,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,precipitation_probability");
  mApi.searchParams.set("hourly", "wave_height,wave_direction,wave_period");
  mApi.searchParams.set("current", "wave_height,wave_direction,wave_period");
  mApi.searchParams.set("cell_selection", "sea");
  var selected = "now", dataW = null, dataM = null, lastSuccess = 0, fetching = false;

  function value(obj, key) {
    return obj && typeof obj[key] === "number" && isFinite(obj[key]) ? obj[key] : null;
  }
  function getHour(api, wanted) {
    var h = api && api.hourly;
    if (!h || !Array.isArray(h.time)) return {};
    var index = h.time.indexOf(wanted);
    if (index < 0) return {};
    var result = {};
    Object.keys(h).forEach(function (key) {
      if (key !== "time" && Array.isArray(h[key])) result[key] = h[key][index];
    });
    return result;
  }
  function nowHour(api) {
    var iso = api && api.current && api.current.time;
    if (!iso) iso = new Date(Date.now() + 9 * 3600000).toISOString().slice(0, 13);
    return String(iso).slice(0, 13) + ":00";
  }
  function tomorrowSeven() {
    return new Date(Date.now() + 9 * 3600000 + 86400000).toISOString().slice(0, 10) + "T07:00";
  }
  function pointFromNow(api) {
    return Object.assign({}, getHour(api, nowHour(api)), api && api.current || {});
  }
  function compass(deg) {
    if (deg === null) return "風向不明";
    return ["北","北東","東","南東","南","南西","西","北西"][Math.floor((deg + 22.5) / 45) % 8];
  }
  function wxCode(code) {
    if (code === null) return { label: "不明", icon: "🌤" };
    if (code === 0) return {label:"快晴", icon:"☀️"};
    if (code === 1) return {label:"晴れ", icon:"🌤️"};
    if (code === 2) return {label:"晴れ時々曇り", icon:"⛅"};
    if (code === 3) return {label:"曇り", icon:"☁️"};
    if (code === 45 || code === 48) return {label:"霧", icon:"🌫️"};
    if (code >= 95) return {label:"雷雨", icon:"⛈️"};
    if (code >= 71 && code <= 77 || code >= 85 && code <= 86) return {label:"雪", icon:"🌨️"};
    if (code >= 51 && code <= 67 || code >= 80 && code <= 82) return {label:"雨", icon:"🌧️"};
    return {label:"変わりやすい天気", icon:"🌦️"};
  }
  function formatted(n, suffix, decimals) {
    return n === null ? "—" : n.toFixed(decimals === undefined ? 1 : decimals) + suffix;
  }
  function print(key, txt) {
    if (fields[key]) fields[key].textContent = txt;
  }
  function render() {
    var day = selected === "tomorrow";
    var t = tomorrowSeven();
    var w = day ? getHour(dataW, t) : pointFromNow(dataW);
    var m = day ? getHour(dataM, t) : pointFromNow(dataM);
    var weather = wxCode(value(w, "weather_code"));
    print("weatherIcon", weather.icon);
    print("weather", dataW ? weather.label : "取得できません");
    print("temp", formatted(value(w, "temperature_2m"), "℃", 0));
    var rain = value(w, "precipitation_probability");
    print("rain", "降水確率 " + (rain === null ? "—" : Math.round(rain) + "%"));
    print("wind", formatted(value(w, "wind_speed_10m"), " m/s"));
    var direction = value(w, "wind_direction_10m");
    print("windDir", direction === null ? "風向 —" : compass(direction) + "の風");
    print("gust", "最大瞬間 " + formatted(value(w, "wind_gusts_10m"), " m/s"));
    print("wave", formatted(value(m, "wave_height"), " m"));
    var wd = value(m, "wave_direction");
    print("waveDir", wd === null ? "波向 —" : compass(wd) + "からの波");
    print("period", "周期 " + formatted(value(m, "wave_period"), " 秒"));
    buttons.forEach(function (b) {
      b.setAttribute("aria-pressed", String(b.getAttribute("data-sf-period") === selected));
    });
    if (dataW || dataM) {
      var display = day ? t.slice(5, 7) + "月" + t.slice(8, 10) + "日 7:00" : "現在（予報値）";
      updated.textContent = "表示時刻：" + display + " ／ 閲覧時に予報を取得・約30分ごとに更新";
    }
  }
  function fetchJson(url) {
    return fetch(url.toString(), {mode:"cors", cache:"no-store", credentials:"omit"})
      .then(function (r) { if (!r.ok) throw new Error("HTTP " + r.status); return r.json(); });
  }
  function reload() {
    if (fetching) return;
    fetching = true;
    status.textContent = "天気・海況の予報を更新しています…";
    Promise.allSettled([fetchJson(wApi), fetchJson(mApi)])
      .then(function (results) {
        if (results[0].status === "fulfilled") dataW = results[0].value;
        if (results[1].status === "fulfilled") dataM = results[1].value;
        if (dataW || dataM) {
          lastSuccess = Date.now();
          render();
          status.textContent = results.every(function (x) {return x.status === "fulfilled";}) ?
            "" : "一部の予報を取得できませんでした。取得済みの情報を表示しています。";
        } else {
          status.textContent = "予報データを取得できませんでした。時間をおいて再読み込みしてください。";
        }
      })
      .finally(function () { fetching = false; });
  }
  buttons.forEach(function (button) {
    button.addEventListener("click", function () {
      selected = button.getAttribute("data-sf-period");
      render();
    });
  });
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden && Date.now() - lastSuccess > 1800000) reload();
  });
  window.setInterval(function () {
    if (!document.hidden && Date.now() - lastSuccess > 1800000) reload();
  }, 1800000);
  reload();
})();
