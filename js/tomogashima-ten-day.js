/* Real 10-day Tomogashima marine forecast; no mock numbers or made-up gaps. */
(function () {
  "use strict";
  var table = document.getElementById("td10-table");
  var tbody = document.getElementById("td10-body");
  var status = document.getElementById("td10-status");
  if (!table || !tbody || !status) return;
  var lastFetched = 0;
  var DAYS = 10;
  var HOURS = 13; /* 05:00 through 17:00 */
  var weekdays = ["日","月","火","水","木","金","土"];
  function jstToday() {
    return new Date(Date.now() + 9 * 3600000).toISOString().slice(0, 10);
  }
  function getDays() {
    var start = Date.parse(jstToday() + "T00:00:00Z");
    return Array.from({length: DAYS}, function (_, i) {
      return new Date(start + i * 86400000).toISOString().slice(0, 10);
    });
  }
  function valid(v) {
    return typeof v === "number" && Number.isFinite(v) && v >= 0 ? v : null;
  }
  function at(api, field, date, hour) {
    var h = api && api.hourly;
    if (!h || !Array.isArray(h.time) || !Array.isArray(h[field])) return null;
    var time = date + "T" + String(hour).padStart(2, "0") + ":00";
    var i = h.time.indexOf(time);
    return i < 0 ? null : valid(h[field][i]);
  }
  function getDayRows(weather, marine) {
    return getDays().map(function (date) {
      var waves = [], winds = [], radians = [];
      for (var hour = 5; hour <= 17; hour++) {
        var wave = at(marine, "wave_height", date, hour);
        var wind = at(weather, "wind_speed_10m", date, hour);
        var dir = at(weather, "wind_direction_10m", date, hour);
        if (wave !== null) waves.push(wave);
        if (wind !== null) {
          winds.push(wind);
          if (dir !== null) radians.push({d: dir * Math.PI / 180, speed: wind});
        }
      }
      // Showing a daily min/max or mean from only a few hours would be misleading.
      var waveMin = waves.length === HOURS ? Math.min.apply(null, waves) : null;
      var waveMax = waves.length === HOURS ? Math.max.apply(null, waves) : null;
      var windMean = winds.length === HOURS ? winds.reduce(function (a,b) {return a+b;},0)/HOURS : null;
      var direction = null;
      if (radians.length === HOURS) {
        var east = 0, north = 0;
        radians.forEach(function (item) {
          east += Math.sin(item.d) * item.speed;
          north += Math.cos(item.d) * item.speed;
        });
        if (Math.hypot(east,north) > 0.1) {
          direction = (Math.atan2(east,north) * 180 / Math.PI + 360) % 360;
        }
      }
      return {date: date, code: at(weather, "weather_code", date, 11),
        waveMin:waveMin, waveMax:waveMax, wind:windMean, direction:direction,
        partialWave: waves.length > 0 && waves.length < HOURS};
    });
  }
  function weatherIcon(code) {
    if (code === null) return "—";
    if (code === 0) return "☀️";
    if (code === 1 || code === 2) return "🌤️";
    if (code === 3) return "☁️";
    if (code === 45 || code === 48) return "🌫️";
    if (code >= 95) return "⛈️";
    if (code >= 71 && code <= 77 || code === 85 || code === 86) return "🌨️";
    if (code >= 51 && code <= 67 || code >= 80 && code <= 82) return "🌧️";
    return "🌦️";
  }
  function compass(d) {
    if (d === null) return "—";
    return ["北","北東","東","南東","南","南西","西","北西"][Math.floor((d + 22.5) / 45) % 8];
  }
  function cell(tag, klass, text) {
    var el = document.createElement(tag);
    if (klass) el.className=klass;
    if (text !== undefined) el.textContent=String(text);
    return el;
  }
  function meterCell(kind, label, value, max, suffix) {
    var td = cell("td");
    var meter = cell("div","td10-meter");
    var track = cell("div","td10-track");
    if (value === null) {
      track.append(cell("span","td10-value td10-empty","—"));
      td.title="データが不足しているため表示できません";
    } else {
      var fill=cell("span","td10-fill td10-fill-"+kind);
      var ratio=max>0?value/max*100:0;
      fill.style.setProperty("--bar-percent",Math.min(100,Math.max(0,ratio)).toFixed(1)+"%");
      fill.setAttribute("aria-hidden","true");
      track.append(fill);
      var caption=cell("span","td10-value");
      caption.append(document.createTextNode(label),cell("span","",suffix));
      track.append(caption);
      td.title=(kind==="wave"?"5時〜17時の波高範囲：":"5時〜17時の平均風速：")+label+suffix;
    }
    meter.append(track);td.append(meter);
    return td;
  }
  function render(rows) {
    tbody.replaceChildren();
    var maxWave=Math.max(0,...rows.map(function(x){return x.waveMax||0;}));
    var maxWind=Math.max(0,...rows.map(function(x){return x.wind||0;}));
    rows.forEach(function (item,i) {
      var date=new Date(item.date+"T00:00:00Z");
      var dow=date.getUTCDay();
      var tr=cell("tr",i===0?"td10-today":"");
      var label=cell("th","",String(date.getUTCMonth()+1)+"/"+String(date.getUTCDate()));
      label.scope="row";
      label.append(cell("span","td10-day"+(dow===0?" td10-sun":dow===6?" td10-sat":""),"("+weekdays[dow]+")"));
      tr.append(label);
      var weather=cell("td","td10-weather",weatherIcon(item.code));
      weather.title="11時の天気予報";
      tr.append(weather);
      var waveLabel=item.waveMax===null?"—":item.waveMin.toFixed(1)+"–"+item.waveMax.toFixed(1);
      tr.append(meterCell("wave",waveLabel,item.waveMax,maxWave,"m"));
      var windLabel=item.wind===null?"—":item.wind.toFixed(1);
      tr.append(meterCell("wind",windLabel,item.wind,maxWind,"m/s"));
      var direction=cell("td");
      if (item.direction===null) {
        direction.append(cell("span","td10-empty","—"));
      } else {
        var wrapper=cell("div","td10-wind-direction");
        var arrow=cell("span","td10-arrow","➤");
        arrow.style.transform="rotate("+((item.direction+90)%360)+"deg)";
        wrapper.append(arrow,cell("span","",compass(item.direction)));
        direction.append(wrapper);
      }
      tr.append(direction);tbody.append(tr);
    });
  }
  function apiUrl(base, keys) {
    var url=new URL(base);
    url.searchParams.set("latitude","34.281047");
    url.searchParams.set("longitude","135.000879");
    url.searchParams.set("timezone","Asia/Tokyo");
    url.searchParams.set("forecast_days","10");
    keys.forEach(function(pair){url.searchParams.set(pair[0],pair[1]);});
    return url.toString();
  }
  function request(url) {
    return fetch(url,{mode:"cors",credentials:"omit",cache:"no-store"}).then(function(resp) {
      if(!resp.ok)throw Error("Forecast HTTP "+resp.status);
      return resp.json();
    }).then(function(json) {
      if(!json.hourly||!Array.isArray(json.hourly.time))throw Error("Invalid forecast response");
      return json;
    });
  }
  function refresh() {
    status.textContent="10日間の予報を取得しています…";
    var weatherUrl=apiUrl("https://api.open-meteo.com/v1/forecast",[
      ["hourly","weather_code,wind_speed_10m,wind_direction_10m"],["wind_speed_unit","ms"]]);
    var marineUrl=apiUrl("https://marine-api.open-meteo.com/v1/marine",[
      ["hourly","wave_height"],["cell_selection","sea"]]);
    Promise.allSettled([request(weatherUrl),request(marineUrl)]).then(function(results) {
      var weather=results[0].status==="fulfilled"?results[0].value:null;
      var marine=results[1].status==="fulfilled"?results[1].value:null;
      var rows=getDayRows(weather,marine);
      render(rows);
      var incomplete=rows.filter(function(r){return r.waveMax===null||r.wind===null;}).length;
      var fullyFailed=!weather&&!marine;
      status.textContent=fullyFailed?"予報を取得できませんでした。再読み込みしてお試しください。":
        (incomplete?"※ 一部の予報期間でデータ不足のため「—」を表示しています。":"")+
        " データ取得："+new Date().toLocaleString("ja-JP",{timeZone:"Asia/Tokyo",month:"numeric",day:"numeric",hour:"2-digit",minute:"2-digit"});
      lastFetched=Date.now();
    }).catch(function() {status.textContent="予報を取得できませんでした。";});
  }
  document.addEventListener("visibilitychange",function(){
    if(!document.hidden&&Date.now()-lastFetched>45*60000)refresh();
  });
  refresh();
})();
