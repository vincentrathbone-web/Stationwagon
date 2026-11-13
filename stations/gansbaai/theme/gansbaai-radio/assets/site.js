/* Gansbaai Community Radio: the site's script (round 79), for the WordPress theme and the design pages.
   Reads the station's public line-up and "now playing" (both allow other websites), opens the
   station's own player in a bar at the bottom, and runs the contact form.
   The theme sets window.GBCR = { station, contactUrl, sample }; the design pages (stations/gansbaai/site)
   set nothing, so they use the live station and a form that only shows its thank-you. */
(function () {
  "use strict";
  var CFG = window.GBCR || {};
  var STATION = String(CFG.station || "https://gbcr.controll.co.za/radio").replace(/\/+$/, "");
  var USE_SAMPLE = CFG.sample !== false; // the sample week while the station has no shows
  var TZ = "Africa/Johannesburg";
  var DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };

  /* ---------- header and menu ---------- */
  var head = $(".site-head");
  if (head) {
    var solid = function () { head.classList.toggle("solid", !document.body.classList.contains("home") || window.scrollY > 40); };
    solid(); window.addEventListener("scroll", solid, { passive: true });
    var mb = $(".menu-btn");
    if (mb) mb.addEventListener("click", function () {
      var open = head.classList.toggle("open");
      mb.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  /* ---------- the hero video: the smaller file on phones, a still picture for "reduce motion" ---------- */
  var vid = $(".hero video");
  if (vid) {
    var src = vid.getAttribute("data-src-" + (window.innerWidth < 700 ? "small" : "large"));
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) { vid.removeAttribute("autoplay"); }
    else if (src) { vid.src = src; var p = vid.play(); if (p && p.catch) p.catch(function () {}); }
  }

  /* ---------- the player bar ---------- */
  var bar = $(".player-bar");
  $$("[data-listen]").forEach(function (b) {
    b.addEventListener("click", function (e) {
      if (!bar) return;
      e.preventDefault();
      var f = $("iframe", bar);
      if (!f.getAttribute("src")) f.src = STATION + "/embed?look=ocean";
      bar.classList.add("show"); bar.removeAttribute("inert"); document.body.classList.add("has-player");
      if (head) { head.classList.remove("open"); }
    });
  });
  if (bar) $(".close", bar).addEventListener("click", function () {
    var f = $("iframe", bar); f.removeAttribute("src"); // stops the sound
    bar.classList.remove("show"); bar.setAttribute("inert", ""); document.body.classList.remove("has-player");
  });

  /* ---------- time in the station's time zone ---------- */
  function stationNow() {
    var parts = {};
    new Intl.DateTimeFormat("en-GB", { timeZone: TZ, weekday: "short", hour: "2-digit", minute: "2-digit", year: "numeric", month: "2-digit", day: "2-digit", hourCycle: "h23" })
      .formatToParts(new Date()).forEach(function (p) { parts[p.type] = p.value; });
    var wd = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].indexOf(parts.weekday) + 1;
    return { day: wd, min: (+parts.hour) * 60 + (+parts.minute), date: parts.year + "-" + parts.month + "-" + parts.day };
  }
  function toMin(t) { var a = String(t).split(":"); return (+a[0]) * 60 + (+a[1]); }
  function hhmm(m) { m = m % 1440; return ("0" + Math.floor(m / 60)).slice(-2) + ":" + ("0" + (m % 60)).slice(-2); }
  function dateOfDay(now, d) { // the date of weekday d in this week, as YYYY-MM-DD
    var t = new Date(now.date + "T12:00:00Z"); t.setUTCDate(t.getUTCDate() + (d - now.day)); return t.toISOString().slice(0, 10);
  }

  /* ---------- the line-up ---------- */
  // Until the station has its own shows, the pages show this sample week (and say so).
  var W = [1, 2, 3, 4, 5], ALL = [1, 2, 3, 4, 5, 6, 7];
  var SAMPLE = [
    { title: "Harbour Breakfast", kind: "live", host: "the morning crew", days: W, start: "06:00", end: "09:00" },
    { title: "Community Notice Board", kind: "live", host: "the station team", days: W, start: "09:00", end: "10:00" },
    { title: "Fynbos & Coffee", kind: "playlist", days: W, start: "10:00", end: "13:00" },
    { title: "Whale Coast Drive", kind: "live", host: "the drive team", days: W, start: "15:00", end: "18:00" },
    { title: "Sundowners", kind: "playlist", days: ALL, start: "18:00", end: "21:00" },
    { title: "Night Tide", kind: "playlist", days: ALL, start: "21:00", end: "00:00" },
    { title: "Saturday Market Morning", kind: "live", host: "the weekend team", days: [6], start: "08:00", end: "11:00" },
    { title: "Sport on the Bay", kind: "live", host: "the sports desk", days: [6], start: "14:00", end: "17:00" },
    { title: "Sunday Worship", kind: "live", host: "local churches", days: [7], start: "07:00", end: "09:00" },
    { title: "Sondag Treffers", kind: "playlist", days: [7], start: "10:00", end: "13:00" }
  ];
  var blocks = null, isSample = false;

  function dayList(d, now) { // the day's blocks in order, with the gaps between them as "non-stop music"
    var date = dateOfDay(now, d);
    var list = blocks.filter(function (b) {
      return b.days.indexOf(d) > -1 && (!b.valid_from || b.valid_from <= date) && (!b.valid_to || b.valid_to >= date);
    }).map(function (b) {
      var s = toMin(b.start), e = toMin(b.end); if (e <= s) e = 1440;
      return { b: b, s: s, e: e };
    }).sort(function (x, y) { return x.s - y.s; });
    var out = [], cur = 0;
    list.forEach(function (x) {
      if (x.s > cur) out.push({ gap: true, s: cur, e: x.s });
      out.push(x); cur = Math.max(cur, x.e);
    });
    if (cur < 1440) out.push({ gap: true, s: cur, e: 1440 });
    return out;
  }
  function slotHtml(x, isNow) {
    var t = '<time>' + (x.s === 0 && x.e === 1440 ? 'All day' : hhmm(x.s) + ' – ' + hhmm(x.e)) + '</time>';
    if (x.gap) return '<li class="slot gap' + (isNow ? ' now' : '') + '">' + t + '<div class="name">Non-stop music</div>' + (isNow ? '<span class="pill onnow">On now</span>' : '<span></span>') + '</li>';
    var b = x.b, live = b.kind === "live";
    var pill = isNow ? '<span class="pill onnow"><span class="live-dot"></span>On now</span>'
      : (live ? '<span class="pill live">Live</span>' : '<span class="pill music">Music</span>');
    return '<li class="slot' + (isNow ? ' now' : '') + '">' + t + '<div><div class="name">' + esc(b.title) + '</div>' +
      (b.host ? '<div class="host">with ' + esc(b.host) + '</div>' : '') + '</div>' + pill + '</li>';
  }
  function renderDay(el, d, now, opts) {
    var items = dayList(d, now), html = "";
    items.forEach(function (x) {
      var isNow = d === now.day && now.min >= x.s && now.min < x.e;
      if (opts && opts.fromNow && d === now.day && x.e <= now.min) return; // the home page shows the rest of today
      if (opts && opts.noGaps && x.gap && !isNow) return;
      html += slotHtml(x, isNow);
    });
    el.innerHTML = html || '<li class="slot gap"><time></time><div class="name">Non-stop music for the rest of the day</div><span></span></li>';
  }
  function renderNow(now) {
    var today = dayList(now.day, now), cur = null, next = null;
    today.forEach(function (x) { if (now.min >= x.s && now.min < x.e) cur = x; else if (x.s > now.min && !x.gap && !next) next = x; });
    if (!next) { // the first show of a later day
      for (var i = 1; i <= 7 && !next; i++) {
        var d = ((now.day - 1 + i) % 7) + 1;
        dayList(d, now).some(function (x) { if (!x.gap) { next = x; next.day = d; return true; } return false; });
      }
    }
    var nc = $("#now-show"), nx = $("#next-show");
    if (nc && cur) {
      $(".title", nc).textContent = cur.gap ? "Non-stop music" : cur.b.title;
      $(".meta", nc).textContent = (cur.gap ? "" : (cur.b.host ? "with " + cur.b.host + " · " : "")) + hhmm(cur.s) + " – " + hhmm(cur.e);
    }
    if (nx) {
      $(".title", nx).textContent = next ? next.b.title : "Non-stop music";
      $(".meta", nx).textContent = next ? ((next.day ? DAYS[next.day - 1] + " " : "") + hhmm(next.s) + " – " + hhmm(next.e) + (next.b.host ? " · with " + next.b.host : "")) : "All day, every day";
    }
  }
  function drawLineup() {
    var now = stationNow();
    $$(".sample-note").forEach(function (n) { n.hidden = !isSample; });
    var today = $("#today-slots");
    if (today) renderDay(today, now.day, now, { fromNow: true, noGaps: true });
    var tabs = $(".days");
    if (tabs) {
      var sel = +(tabs.getAttribute("data-day") || now.day);
      tabs.innerHTML = DAYS.map(function (n, i) {
        var d = i + 1;
        return '<button type="button" role="tab" data-d="' + d + '" aria-selected="' + (d === sel) + '">' + n.slice(0, 3) +
          '<span class="full">' + n.slice(3) + '</span>' + (d === now.day ? '<span class="t">Today</span>' : '') + '</button>';
      }).join("");
      renderDay($("#week-slots"), sel, now);
      $("#day-name").textContent = DAYS[sel - 1] + (sel === now.day ? " (today)" : "");
    }
    renderNow(now);
  }
  if ($("#today-slots") || $(".days") || $("#now-show")) {
    var tabsEl = $(".days");
    if (tabsEl) {
      tabsEl.addEventListener("click", function (e) {
        var b = e.target.closest("button"); if (!b) return;
        tabsEl.setAttribute("data-day", b.getAttribute("data-d")); drawLineup();
        b = $('[data-d="' + tabsEl.getAttribute("data-day") + '"]', tabsEl); if (b) b.focus();
      });
      tabsEl.addEventListener("keydown", function (e) {
        if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
        var cur = +(tabsEl.getAttribute("data-day") || stationNow().day), d = ((cur - 1 + (e.key === "ArrowRight" ? 1 : 6)) % 7) + 1;
        tabsEl.setAttribute("data-day", d); drawLineup(); $('[data-d="' + d + '"]', tabsEl).focus();
      });
    }
    fetch(STATION + "/api/schedule.php").then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.blocks && j.blocks.length) { blocks = j.blocks; isSample = false; } else { blocks = USE_SAMPLE ? SAMPLE : []; isSample = USE_SAMPLE; }
    }).catch(function () { blocks = USE_SAMPLE ? SAMPLE : []; isSample = USE_SAMPLE; }).then(function () {
      drawLineup(); setInterval(drawLineup, 60000);
    });
  }

  /* ---------- now playing (the song on air) ---------- */
  var npEls = $$("[data-nowplaying]");
  if (npEls.length) {
    var poll = function () {
      fetch(STATION + "/api/nowplaying.php").then(function (r) { return r.json(); }).then(function (j) {
        var a = j && j.on_air, txt = a ? (a.title || "") + (a.artist ? " · " + a.artist : "") : "";
        npEls.forEach(function (el) { el.textContent = txt || "Gansbaai Community Radio"; el.title = txt; });
      }).catch(function () {});
    };
    poll(); setInterval(poll, 20000);
  }

  /* ---------- the contact form ---------- */
  var form = $(".js-contact-form");
  if (form) {
    var topic = $("#c-topic", form);
    var want = new URLSearchParams(location.search).get("topic");
    var pick = function (v) {
      $$("option", topic).forEach(function (o) { if (o.value === v) topic.value = v; });
      $$(".chips button").forEach(function (c) { c.setAttribute("aria-pressed", c.getAttribute("data-topic") === topic.value ? "true" : "false"); });
    };
    if (want) pick(want);
    topic.addEventListener("change", function () { pick(topic.value); });
    $$(".chips button").forEach(function (c) {
      c.addEventListener("click", function () { pick(c.getAttribute("data-topic")); $("#c-message", form).focus(); });
    });
    form.addEventListener("input", function (e) { // the red note goes as soon as the field is typed in
      var f = e.target.closest(".field"); if (f && f.classList.contains("bad")) { f.classList.remove("bad"); e.target.setAttribute("aria-invalid", "false"); }
    });
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var ok = true, first = null;
      [["c-name", function (v) { return v.trim().length > 1; }],
       ["c-email", function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); }],
       ["c-message", function (v) { return v.trim().length > 4; }]].forEach(function (f) {
        var el = $("#" + f[0], form), good = f[1](el.value);
        el.closest(".field").classList.toggle("bad", !good);
        el.setAttribute("aria-invalid", good ? "false" : "true");
        if (!good) { ok = false; first = first || el; }
      });
      if (!ok) { first.focus(); return; }
      var name = $("#c-name", form).value.trim().split(" ")[0];
      var card = form.closest(".form-card"), status = $(".status", form), btn = $("button[type=submit]", form);
      var thanks = function (preview) {
        card.innerHTML = '<div class="sent" role="status"><div class="badge-icon"><svg class="icon" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>' +
          '<h2>Thanks, ' + esc(name) + '!</h2><p class="lead" style="margin:0 auto">We got your message and will get back to you soon.</p>' +
          (preview ? '<p class="fineprint">Design preview: nothing was sent. On the live site this goes to the station.</p>' : '') + '</div>';
        card.scrollIntoView({ block: "start" }); // under the menu bar (scroll-margin-top)
      };
      if (!CFG.contactUrl) { thanks(true); return; } // the design pages stop here
      var data = {};
      ["website", "name", "phone", "email", "topic", "message"].forEach(function (k) { var el = form.elements[k]; data[k] = el ? el.value : ""; });
      btn.disabled = true; status.className = "status"; status.textContent = "Sending…";
      fetch(CFG.contactUrl, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) })
        .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
        .then(function (j) {
          if (j && j.ok) { thanks(false); return; }
          btn.disabled = false; status.className = "status bad"; status.textContent = (j && j.error) || "Sorry, we could not send that right now. Please try again later.";
        })
        .catch(function () { btn.disabled = false; status.className = "status bad"; status.textContent = "We could not reach the website. Check your connection and try again."; });
    });
  }

  /* ---------- this year in the footer ---------- */
  $$("[data-year]").forEach(function (el) { el.textContent = new Date().getFullYear(); });
})();
