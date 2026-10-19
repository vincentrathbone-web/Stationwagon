import re, sys, pathlib

src_dir = pathlib.Path(sys.argv[1])

TITLES = {
    "wordwave-player": "WordWave Green Player",
    "wordwave-studio": "WordWave Green Studio",
    "wordwave-manager": "WordWave Green Manager",
}

FONT_LINK = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap">'

TOKENS = """:root{
  color-scheme: dark;
  /* Green skin: soft near-black ground, rounded cards that lift on hover, one bright green for play and live-ready actions. */
  --ground:#121212; --panel:#181818; --raised:#242424; --edge:#2e2e2e; --hi:transparent; --shade:transparent;
  --ink:#ffffff; --muted:#b3b3b3; --faint:#7c7c7c;
  --lcd:#1f1f1f; --amber:#1ed760; --amber-soft:#1db954; --amber-dim:#1f5133;
  --onair:#f15e6c; --ok:#1ed760; --warn:#ffa42b; --cue:#509bf5;
  --f-ui:"Figtree",system-ui,-apple-system,"Segoe UI",sans-serif;
  --f-body:"Figtree",system-ui,-apple-system,"Segoe UI",sans-serif;
  --f-lcd:"Figtree",system-ui,-apple-system,"Segoe UI",sans-serif;
  --f-mono:"DM Mono",ui-monospace,Consolas,monospace;
  --bevel: 0 0 0 transparent;
  --sunken: 0 0 0 transparent;
}"""

COLOR_MAP = {
    "#322e29": "#2a2a2a", "#24211d": "#1f1f1f", "#2a1e0e": "#173a25", "#241a0c": "#15301f",
    "#3a2a10": "#1ed760", "#2a2012": "#173322", "#221d15": "#15301f", "#1d1a14": "#262626",
    "#26231f": "#262626", "#1a1816": "#1c1c1c", "#ffd08a": "#8affb8", "#ffb24a": "#1ed760",
    "#c98a3a": "#1db954", "#5c4220": "#1f5133", "#0d0c09": "#1f1f1f", "#a49c8d": "#b3b3b3",
    "#ece6da": "#ffffff", "#3a3630": "#333333", "#4a1712": "#4a1c22", "#3a1712": "#3a1a1f",
    "#ffd2cc": "#ffd5da", "#2a1311": "#2a1517", "#6b2a22": "#6b2a33", "#26120f": "#26121a",
    "#24130f": "#26121a", "#5a241c": "#5a2430", "#ff9a8c": "#ff9aa6", "#ffc9c1": "#ffc9d0",
    "#ffe1dc": "#ffe1e5",
}

OVERRIDES = """
/* ---- green skin: shape and type overrides ---- */
body{font-family:var(--f-body)}
.win,.card,.embed,.tablewrap,.hdr,.mod,.preview{border:0;border-radius:16px;box-shadow:none}
.win{background:var(--panel)}
.win:hover,.card:hover{background:#1c1c1c}
.titlebar{padding:14px 16px 4px;font:700 15px/1.2 var(--f-ui);letter-spacing:0;text-transform:none;color:var(--ink)}
.titlebar .grip{visibility:hidden}
.titlebar .grip.short{display:none}
.win-body,.body{padding:8px 16px 16px}
.lcd,.lcdbox{border-radius:12px;box-shadow:none;background:var(--lcd);text-shadow:none}
.lcd *,.clock,.time,.rem{text-shadow:none!important}
.btn{border:0;border-radius:999px;box-shadow:none;background:var(--raised);font:700 13px/1 var(--f-ui);letter-spacing:0;text-transform:none;padding:10px 16px}
.btn:hover{background:#2f2f2f;transform:scale(1.03)}
.btn:active,.btn[aria-pressed="true"]{box-shadow:none;background:#333}
.btn.sm{padding:7px 12px;font-size:12px}
.btn.primary,.btn.play{background:var(--amber);color:#000}
.btn.primary:hover,.btn.play:hover{background:#3be477}
.btn.play[aria-pressed="true"]{background:#fff;color:#000}
.btn.cue[aria-pressed="true"]{background:#1d2f45;color:var(--cue)}
.btn[aria-pressed="true"]:not(.play):not(.cue){color:var(--amber)}
@media (prefers-reduced-motion:reduce){.btn:hover{transform:none}}
input[type=range]::-webkit-slider-runnable-track{height:5px;border-radius:999px;box-shadow:none;background:#4d4d4d}
input[type=range]::-moz-range-track{height:5px;border-radius:999px;box-shadow:none;background:#4d4d4d}
input[type=range]::-webkit-slider-thumb{width:14px;height:14px;margin-top:-4.5px;border-radius:50%;border:0;box-shadow:none;background:#fff}
input[type=range]::-moz-range-thumb{width:14px;height:14px;border-radius:50%;border:0;box-shadow:none;background:#fff}
select,input[type=search]{border:0;box-shadow:none;border-radius:999px;background:var(--raised);color:var(--ink);font:500 14px var(--f-ui);padding:9px 14px}
.label,h2,.eyebrow,.seek-meta,.tag,.pill,th,.lbl,.mod h3,.lampline,.rail button,.meter-col span,.stat span,.dh,.brand small,.health,.status,.pl-foot,.showmeta,.fillmeta,.duck,.xf b{text-transform:none!important;letter-spacing:0!important}
h1{font-family:var(--f-ui);font-weight:800;letter-spacing:-.02em}
h2{font:700 18px var(--f-ui);color:var(--ink)}
.eyebrow{font:600 13px var(--f-ui);color:var(--muted)}
.tag,.pill{border-radius:999px;border:0;padding:4px 10px;font:600 12px var(--f-ui);background:var(--raised)}
.brand{font-weight:800;letter-spacing:-.01em}
pre,code{font-family:var(--f-mono)}
.embed pre{border-radius:12px;box-shadow:none}
.seek,.showbar,.storage,.prog,.grm,.fillbar,.meter{border-radius:999px;box-shadow:none;background:#3a3a3a}
.seek .fill,.storage i,.showbar i,.prog i{background:var(--amber);border-radius:999px}
.meter{width:12px}.meter i{border-radius:999px}
.wave{border-radius:10px}
.marquee{font-weight:700;font-size:17px;color:var(--ink)}
.lcd .time{font-weight:800;letter-spacing:-.02em}
.lcd .specs,.lcd .mode{color:var(--muted)}
.lcd .mode{border-top:1px solid #2e2e2e}
.lcd .specs b{color:var(--ink)}
.pl{border-radius:12px;box-shadow:none;padding:6px;font-family:var(--f-ui);font-size:14px;background:transparent}
.pl li{border-radius:8px;color:var(--ink)}
.pl li:hover{background:#2a2a2a}
.pl li .n,.pl li .d{color:var(--muted)}
.pl li.now{background:#173a25;color:var(--amber)}
.sched li{border-radius:12px}
.sched li.current{box-shadow:none;background:#173a25;border-radius:12px}
.sched time,.runorder,.clock,.stat b,td.num,th.num,.ctl output,.ctrl output,.vol output,.deck .fader output,.duck,.showmeta,.fillmeta,td .sub,.now{font-family:var(--f-ui)}
.stat b{font-weight:800}
.runorder li{border-radius:8px;color:var(--ink)}
.runorder li+li{border-top:0}
.runorder li.now{background:#173a25}
.runorder .at,.runorder .d{color:var(--muted)}
.golive{border-radius:999px;background:var(--amber);color:#000}
.golive .dot{background:#000}
.golive.joining{background:var(--warn);color:#000}
.golive.on{background:var(--onair);color:#fff}
.golive.on .dot{background:#fff;box-shadow:0 0 8px #fff}
.talk[aria-pressed="true"]{background:var(--amber);color:#000}
.banner{border:0;border-radius:14px}
.mod{background:var(--raised);border-radius:14px}
.rail button{border-radius:10px}
.rail button[aria-current="page"]{box-shadow:none;background:var(--raised);color:var(--ink);border-radius:10px}
.rail button[aria-current="page"]::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--amber);align-self:center;margin-right:-2px;order:-1}
.now{border-radius:999px}
.checks li,.stat{border-radius:12px}
.week .cell{border-radius:6px}
.week .blk{border-radius:8px;box-shadow:none!important}
.week .blk.live{background:#3a1a1f}
.week .blk.auto{background:#173322;color:var(--amber)}
.drop{border-radius:16px;border-color:#3a3a3a}
.toast{border:0;border-radius:999px;box-shadow:0 8px 24px rgba(0,0,0,.5);background:#fff;color:#000}
.sw{background:#535353;box-shadow:none}
.sw::after{background:#fff}
.sw:checked{background:var(--amber)}
.sw:checked::after{background:#fff}
.lamp.auto i,.lamp i{border-radius:50%}
table{font-family:var(--f-ui)}
tr:hover td{background:#1f1f1f}
"""

for name, title in TITLES.items():
    s = (src_dir / f"{name}.html").read_text(encoding="utf-8")
    s = re.sub(r"<title>.*?</title>", f"<title>{title}</title>", s, count=1)
    s = re.sub(r'<link rel="stylesheet" href="https://fonts.googleapis.com/css2[^"]*">', FONT_LINK, s, count=1)
    s = re.sub(r":root\{.*?\n\}", TOKENS, s, count=1, flags=re.S)
    s = s.replace("1px solid #000", "0 solid transparent")
    for a, b in COLOR_MAP.items():
        s = re.sub(re.escape(a), b, s, flags=re.I)
    s = s.replace("rgba(255,178,74,", "rgba(30,215,96,")
    s = s.replace("Share Tech Mono,monospace", "Figtree,sans-serif")
    s = s.replace("</style>", OVERRIDES + "</style>", 1)
    (src_dir / f"{name}-green.html").write_text(s, encoding="utf-8")
    print("wrote", name + "-green.html", len(s))
