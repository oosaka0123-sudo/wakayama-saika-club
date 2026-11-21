/* Tomogashima hourly marine forecast: measured values are never simulated. */
(function () {
  "use strict";
  var root = document.querySelector(".tw-dashboard");
  if (!root) return;
  var $ = function (id) { return document.getElementById(id); };
  var hours = Array.from({length:13}, function (_, i) { return i + 5; });
  var startDate = jstDate(0);
  var selectedDay = 0;
  var selectedHour = Math.max(5, Math.min(17, new Date(Date.now() + 9 * 3600000).getUTCHours()));
  var meteo = null, marine = null, lastLoaded = 0;
  var loading = false;
  var svgNS = "http://www.w3.org/2000/svg";
  var scroller = $("tw-scroller");
  var graph = $("tw-combo-chart");
  var status = $("tw-status");
  var dateLabel = $("tw-date");

  function jstDate(days) {
    return new Date(Date.now() + (9 + 24 * days) * 3600000).toISOString().slice(0, 10);
  }
  function dateForDay() {
    return new Date(startDate + "T00:00:00Z").getTime() + selectedDay * 86400000;
  }
  function dateKey() {
    return new Date(dateForDay()).toISOString().slice(0, 10);
  }
  function num(value) {
    return typeof value === "number" && isFinite(value) ? value : null;
  }
  function fmt(value, digits) {
    return value === null ? "—" : value.toFixed(digits);
  }
  function compass(degrees) {
    if (degrees === null) return "—";
    return ["北","北東","東","南東","南","南西","西","北西"][Math.floor((degrees + 22.5) / 45) % 8];
  }
  function forecastHour(api, key) {
    var hourly = api && api.hourly;
    if (!hourly || !Array.isArray(hourly.time)) return {};
    var idx = hourly.time.indexOf(key);
    if (idx < 0) return {};
    var result = {};
    Object.keys(hourly).forEach(function (name) {
      if (name !== "time" && Array.isArray(hourly[name])) result[name] = hourly[name][idx];
    });
    return result;
  }
  function weatherIcon(code, hour) {
    if (code === null) return "—";
    if (code === 0) return hour < 6 ? "🌙" : "☀️";
    if (code === 1 || code === 2) return hour < 6 ? "🌙" : "🌤️";
    if (code === 3) return "☁️";
    if (code === 45 || code === 48) return "🌫️";
    if (code >= 95) return "⛈️";
    if ((code >= 71 && code <= 77) || code === 85 || code === 86) return "🌨️";
    if ((code >= 51 && code <= 67) || (code >= 80 && code <= 82)) return "🌧️";
    return "🌦️";
  }
  function points() {
    var date = dateKey();
    return hours.map(function (hour) {
      var iso = date + "T" + String(hour).padStart(2, "0") + ":00";
      var w = forecastHour(meteo, iso);
      var m = forecastHour(marine, iso);
      return {
        hour: hour,
        weather: num(w.weather_code),
        wave: num(m.wave_height),
        period: num(m.wave_period),
        wind: num(w.wind_speed_10m),
        gust: num(w.wind_gusts_10m),
        direction: num(w.wind_direction_10m)
      };
    });
  }
  function node(tag, className, txt) {
    var elem = document.createElement(tag);
    if (className) elem.className = className;
    if (txt !== undefined) elem.textContent = String(txt);
    return elem;
  }
  function svgElem(tag, attrs, txt) {
    var elem = document.createElementNS(svgNS, tag);
    Object.keys(attrs || {}).forEach(function (key) { elem.setAttribute(key, attrs[key]); });
    if (txt !== undefined) elem.textContent = String(txt);
    return elem;
  }
  function updateTitle() {
    var d = new Date(dateForDay());
    var week = ["日","月","火","水","木","金","土"][d.getUTCDay()];
    dateLabel.textContent = (d.getUTCMonth() + 1) + "月" + d.getUTCDate() + "日（" + week + "）";
    $("tw-prev").disabled = selectedDay === 0;
    $("tw-next").disabled = selectedDay === 2;
  }
  function renderSummary(rows) {
    var p = rows.find(function (r) { return r.hour === selectedHour; }) || rows[0];
    $("tw-selected-time").textContent = p.hour + ":00";
    $("tw-selected-wave").textContent = fmt(p.wave, 1) + (p.wave === null ? "" : " m");
    $("tw-selected-period").textContent = fmt(p.period, 1) + (p.period === null ? "" : " 秒");
    $("tw-selected-wind").textContent = fmt(p.wind, 1) + (p.wind === null ? "" : " m/s");
    $("tw-selected-gust").textContent = fmt(p.gust, 1) + (p.gust === null ? "" : " m/s");
    $("tw-selected-direction").textContent = compass(p.direction);
  }
  function renderTable(rows) {
    var ids = ["tw-hours-row","tw-weather-row","tw-wave-row","tw-period-row","tw-wind-row","tw-gust-row","tw-direction-row"];
    ids.forEach(function (id) {
      var row = $(id);
      while (row.children.length > 1) row.lastChild.remove();
    });
    var weatherStrip = $("tw-weather-strip");
    weatherStrip.replaceChildren(node("div","tw-weather-title","天気"));
    rows.forEach(function (p) {
      var sel = p.hour === selectedHour;
      var th = node("th",sel?"tw-selected":"");
      th.scope = "col";
      var btn = node("button","",p.hour + ":00");
      btn.type = "button";
      btn.setAttribute("aria-label",p.hour + "時の予報を選択");
      btn.setAttribute("aria-pressed", String(sel));
      btn.addEventListener("click", function () { chooseHour(p.hour); });
      th.appendChild(btn);
      $("tw-hours-row").appendChild(th);

      var small = node("small","",p.hour + ":00");
      var icon = node("span","",weatherIcon(p.weather,p.hour));
      var w = node("div",sel?"is-selected":"");
      w.append(small, icon);
      weatherStrip.appendChild(w);

      var weather = node("td",sel?"tw-selected":"",weatherIcon(p.weather,p.hour));
      $("tw-weather-row").appendChild(weather);
      [["tw-wave-row", fmt(p.wave,1)],["tw-period-row",fmt(p.period,1)],
       ["tw-wind-row",fmt(p.wind,1)],["tw-gust-row",fmt(p.gust,1)]].forEach(function (item) {
        $(item[0]).appendChild(node("td",sel?"tw-selected":"",item[1]));
      });
      var direction = node("td",sel?"tw-selected":"");
      if (p.direction === null) direction.textContent = "—";
      else {
        var arrow = node("span","tw-direction-arrow","↑");
        arrow.style.transform = "rotate(" + ((p.direction + 180) % 360) + "deg)";
        arrow.style.display = "inline-block";
        var directionName = node("span","tw-direction-label",compass(p.direction));
        direction.append(arrow,directionName);
      }
      $("tw-direction-row").appendChild(direction);
    });
  }
  function renderGraph(rows) {
    graph.replaceChildren();
    var WIDTH = 1080, left = 144, step = 72, top = 50, bottom = 281;
    var validWave = rows.map(function (r) { return r.wave; }).filter(function (v) { return v !== null; });
    var validWind = rows.map(function (r) { return r.wind; }).filter(function (v) { return v !== null; });
    var maxWave = validWave.length ? Math.max.apply(null,validWave) : 0;
    var maxWind = validWind.length ? Math.max.apply(null,validWind) : 0;
    var waveAxis = Math.max(1,Math.ceil(maxWave*1.18*2)/2);
    var windAxis = Math.max(8,Math.ceil(maxWind*1.18/2)*2);
    var yWave = function (v) { return bottom - v / waveAxis * (bottom - top); };
    var yWind = function (v) { return bottom - v / windAxis * (bottom - top); };
    var cx = function (i) { return left + 36 + i*step; };

    graph.appendChild(svgElem("title",{},"友ヶ島灯台 5時から17時の波高と風速"));
    graph.appendChild(svgElem("desc",{},"水色の縦棒が波高（左軸m）、オレンジの線が風速（右軸m/s）。時間別の正確な数値は直下の表で確認できます。"));
    graph.appendChild(svgElem("text",{x:12,y:23,fill:"#6ee4ff","font-size":14,"font-weight":700},"波高（m）"));
    graph.appendChild(svgElem("text",{x:1068,y:23,fill:"#ff9b4b","font-size":14,"text-anchor":"end","font-weight":700},"風速（m/s）"));
    for (var n=0;n<=4;n++) {
      var yy = bottom - n*(bottom-top)/4;
      graph.appendChild(svgElem("line",{x1:left,y1:yy,x2:1080,y2:yy,stroke:"#2f5368","stroke-width":1,"stroke-dasharray":n===0?"":"4 5"}));
      graph.appendChild(svgElem("text",{x:132,y:yy+5,fill:"#8ee7ff","text-anchor":"end","font-size":13},(waveAxis*n/4).toFixed(1)));
      graph.appendChild(svgElem("text",{x:1071,y:yy+5,fill:"#ffaf71","text-anchor":"end","font-size":13},(windAxis*n/4).toFixed(n===0?0:0)));
    }
    rows.forEach(function (p,i) {
      var x = cx(i);
      if (p.hour === selectedHour) {
        graph.appendChild(svgElem("rect",{x:x-35,y:top-3,width:70,height:bottom-top+17,fill:"#ff7b2240"}));
      }
      if (p.wave !== null) {
        var y = yWave(p.wave);
        graph.appendChild(svgElem("rect",{x:x-22,y:y,width:44,height:Math.max(0,bottom-y),rx:3,fill:p.hour===selectedHour?"#43eeff":"#16b5e4",opacity:.88}));
      }
      graph.appendChild(svgElem("text",{x:x,y:317,fill:p.hour===selectedHour?"#ffa756":"#c5deec","text-anchor":"middle","font-size":13,"font-weight":p.hour===selectedHour?900:650},p.hour+":00"));
    });
    var segments = [], segment = [];
    rows.forEach(function (p,i) {
      if (p.wind === null) { if(segment.length) segments.push(segment); segment=[]; }
      else segment.push({x:cx(i), y:yWind(p.wind), point:p});
    });
    if(segment.length) segments.push(segment);
    segments.forEach(function (part) {
      if (part.length<2) return;
      var d = part.map(function (p,i) { return (i?"L":"M") + p.x+" "+p.y; }).join(" ");
      graph.appendChild(svgElem("path",{d:d,fill:"none",stroke:"#ff8b31","stroke-width":4,"stroke-linecap":"round","stroke-linejoin":"round"}));
    });
    rows.forEach(function (p,i) {
      if (p.wind !== null) {
        graph.appendChild(svgElem("circle",{cx:cx(i),cy:yWind(p.wind),r:p.hour===selectedHour?7:4.5,stroke:"#fff",fill:"#ff8b31","stroke-width":p.hour===selectedHour?3:1.4}));
      }
    });
    var selected = rows.find(function (p) {return p.hour===selectedHour;});
    if (selected) {
      var idx=hours.indexOf(selectedHour), x=cx(idx);
      graph.appendChild(svgElem("line",{x1:x,y1:top,x2:x,y2:bottom,stroke:"#eaf9ff","stroke-width":1.5,"stroke-dasharray":"5 6",opacity:.72}));
      if(selected.wind!==null) {
        var y1 = Math.max(47,yWind(selected.wind)-17);
        graph.appendChild(svgElem("text",{x:x,y:y1,fill:"#ffb27a","font-size":16,"font-weight":900,"text-anchor":"middle","paint-order":"stroke",stroke:"#05263b","stroke-width":5},fmt(selected.wind,1)+" m/s"));
      }
      if(selected.wave!==null) {
        var yw = Math.min(bottom-10,yWave(selected.wave)+25);
        graph.appendChild(svgElem("text",{x:x,y:yw,fill:"#bcfaff","font-size":15,"font-weight":900,"text-anchor":"middle","paint-order":"stroke",stroke:"#05263b","stroke-width":5},fmt(selected.wave,1)+" m"));
      }
    }
    graph.onclick = function (event) {
      var bounds = graph.getBoundingClientRect();
      var coordinate = (event.clientX-bounds.left)/bounds.width*WIDTH;
      var index = Math.floor((coordinate-left)/step);
      if (index>=0&&index<13) chooseHour(hours[index]);
    };
  }
  function keepSelectedHourVisible() {
    var index = hours.indexOf(selectedHour);
    var scale = scroller.querySelector(".tw-rail").getBoundingClientRect().width / 1080;
    var centerX = (144 + (index + 0.5) * 72) * scale;
    scroller.scrollLeft = Math.max(0, centerX - scroller.clientWidth / 2);
  }
  function chooseHour(hour) {
    selectedHour = hour;
    render();
    keepSelectedHourVisible();
  }
  function render() {
    updateTitle();
    var rows = points();
    renderSummary(rows);
    renderTable(rows);
    renderGraph(rows);
    if (meteo || marine) {
      $("tw-updated").textContent = "予報取得：" + new Date(lastLoaded).toLocaleString("ja-JP",{timeZone:"Asia/Tokyo",month:"numeric",day:"numeric",hour:"2-digit",minute:"2-digit"});
    }
  }
  function apiUrls() {
    var weather = new URL("https://api.open-meteo.com/v1/forecast");
    var sea = new URL("https://marine-api.open-meteo.com/v1/marine");
    [weather,sea].forEach(function (url) {
      url.searchParams.set("latitude","34.281047");
      url.searchParams.set("longitude","135.000879");
      url.searchParams.set("timezone","Asia/Tokyo");
      url.searchParams.set("forecast_days","3");
    });
    weather.searchParams.set("wind_speed_unit","ms");
    weather.searchParams.set("hourly","temperature_2m,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m");
    sea.searchParams.set("hourly","wave_height,wave_direction,wave_period");
    sea.searchParams.set("cell_selection","sea");
    return [weather,sea];
  }
  function readJson(url) {
    return fetch(url,{mode:"cors",credentials:"omit",cache:"no-store"})
      .then(function (r) {if(!r.ok)throw new Error("HTTP "+r.status);return r.json();})
      .then(function (data) {if(!data.hourly || !Array.isArray(data.hourly.time))throw new Error("Hourly forecast unavailable");return data;});
  }
  function refresh() {
    if(loading) return;
    loading=true;
    status.textContent="時間別の予報を取得しています…";
    var urls=apiUrls();
    Promise.allSettled([readJson(urls[0]),readJson(urls[1])]).then(function(results) {
      if (results[0].status==="fulfilled") meteo=results[0].value;
      if (results[1].status==="fulfilled") marine=results[1].value;
      if (!meteo && !marine) {
        status.textContent="予報を取得できませんでした。通信環境を確認してページを再読み込みしてください。";
      } else {
        lastLoaded=Date.now();
        status.textContent=results.every(function(r){return r.status==="fulfilled";})?"":"一部の予報を取得できませんでした。欠測項目は「—」と表示しています。";
      }
      render();
    }).catch(function() {
      status.textContent="予報取得時にエラーが発生しました。";
    }).finally(function(){loading=false;});
  }
  $("tw-prev").addEventListener("click",function () {
    if(selectedDay===0) return;
    selectedDay--;
    selectedHour=7;
    render();
    scroller.scrollLeft=0;
  });
  $("tw-next").addEventListener("click",function () {
    if(selectedDay===2) return;
    selectedDay++;
    selectedHour=7;
    render();
    scroller.scrollLeft=0;
  });
  window.addEventListener("pageshow",function(event){if(event.persisted&&Date.now()-lastLoaded>1800000)refresh();});
  document.addEventListener("visibilitychange",function(){if(!document.hidden&&Date.now()-lastLoaded>1800000)refresh();});
  render();
  requestAnimationFrame(keepSelectedHourVisible);
  refresh();
})();
