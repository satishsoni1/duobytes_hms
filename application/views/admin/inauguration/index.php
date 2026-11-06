<?php
/**
 * Inauguration ceremony (standalone, full-screen — no app chrome).
 * Vars from Inauguration::index(): $brand, $hospital, $rehearsal (bool), $can_mark (bool)
 */
$name      = html_escape($hospital->name ?? '');
$local     = html_escape($brand['local_name']);
$tagline   = html_escape($brand['tagline']);
$guest     = html_escape(trim((string) $brand['inauguration_guest']));
$title     = html_escape(trim((string) $brand['inauguration_title']) !== '' ? $brand['inauguration_title'] : 'Digital Hospital Management System');
$logo      = !empty($hospital->mini_logo) ? $this->media_storage->getImageURL('uploads/hospital_content/logo/' . $hospital->mini_logo) : '';
$done_at   = (!$rehearsal && !empty($brand['inaugurated_at'])) ? date('d M Y, h:i A', strtotime($brand['inaugurated_at'])) : '';
$done_by   = html_escape((string) $brand['inaugurated_by']);
$modules   = array('OPD', 'IPD', 'Pharmacy', 'Pathology', 'Radiology', 'Billing & TPA', 'Blood Bank', 'Operation Theatre', 'ABHA / ABDM', 'Patient Portal');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $name ?> · Inauguration</title>
<?php if ($logo): ?><link rel="icon" href="<?= $logo ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&family=Mukta:wght@500;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --teal-900:#06292B; --teal-800:#0B3D40; --teal-700:#135E61; --teal:#1A7A7E;
  --blue:#1E5AA8; --lime:#D4E157; --gold:#F5C542; --gold-2:#E0A526; --marigold:#F59E0B;
  --ribbon:#C8102E; --ribbon-2:#8E0B21; --cream:#FFF6E0; --ink:#FFFFFF; --ink-2:rgba(255,255,255,.8);
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{
  font-family:'Nunito','Mukta',system-ui,sans-serif; color:var(--ink); overflow:hidden;
  background:
    radial-gradient(70% 60% at 50% 38%, #1B6E72 0%, var(--teal-800) 45%, var(--teal-900) 100%);
  -webkit-font-smoothing:antialiased;
}
/* warm glow that spreads once the lamp is lit */
body::before{content:"";position:fixed;inset:0;pointer-events:none;opacity:0;transition:opacity 2.4s ease;
  background:radial-gradient(55% 45% at 50% 88%, rgba(245,197,66,.38), transparent 70%),
             radial-gradient(40% 35% at 50% 35%, rgba(245,197,66,.12), transparent 70%);}
body.lit::before{opacity:1}
canvas#fx{position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:5}

/* ---------- top bar ---------- */
.bar{position:fixed;top:0;left:0;right:0;display:flex;align-items:center;gap:10px;padding:16px 22px;z-index:30}
.chip{font-size:13px;font-weight:700;padding:6px 12px;border-radius:999px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:var(--ink-2);text-decoration:none;white-space:nowrap}
.chip.warn{background:rgba(245,197,66,.18);border-color:rgba(245,197,66,.5);color:var(--cream)}
.bar .sp{flex:1}
.icon-btn{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;cursor:pointer;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);color:#fff}
.icon-btn:hover,.chip:hover{background:rgba(255,255,255,.18)}
.icon-btn svg{width:18px;height:18px}
.icon-btn:focus-visible,.chip:focus-visible,button:focus-visible{outline:3px solid var(--lime);outline-offset:3px}

/* ---------- curtains ---------- */
.curtain{position:fixed;top:0;bottom:0;width:17vw;z-index:20;transition:transform 2.2s cubic-bezier(.7,0,.2,1);
  background:
    repeating-linear-gradient(90deg, rgba(0,0,0,.28) 0 1.2vw, rgba(255,255,255,.05) 1.2vw 2.6vw, rgba(0,0,0,.18) 2.6vw 4vw),
    linear-gradient(180deg,#9E1230,#6E0A20 70%,#4C0716);
  box-shadow:0 0 60px rgba(0,0,0,.55)}
.curtain::after{content:"";position:absolute;left:0;right:0;bottom:0;height:16px;background:linear-gradient(90deg,var(--gold-2),var(--gold),var(--gold-2))}
.curtain.l{left:0;border-radius:0 0 60% 0/0 0 18% 0}
.curtain.r{right:0;border-radius:0 0 0 60%/0 0 0 18%}
.valance{position:fixed;top:0;left:0;right:0;height:44px;z-index:21;transition:transform 2.2s cubic-bezier(.7,0,.2,1);
  background:linear-gradient(180deg,#7E0E26,#5A0919);border-bottom:6px solid var(--gold);
  -webkit-mask:radial-gradient(28px 22px at 28px 44px,#0000 98%,#000) 0 0/56px 100%;mask:radial-gradient(28px 22px at 28px 44px,#0000 98%,#000) 0 0/56px 100%}
body.open .curtain.l{transform:translateX(-102%)}
body.open .curtain.r{transform:translateX(102%)}
body.open .valance{transform:translateY(-110%)}
body.open .bar{z-index:30}

/* ---------- stage ---------- */
.stage{position:relative;z-index:10;height:100%;display:grid;grid-template-columns:100%;grid-template-rows:1fr auto auto;justify-items:center;align-items:center;padding:70px 0 22px;text-align:center}
.hero{max-width:min(64vw,1100px)}
.hero{display:flex;flex-direction:column;align-items:center;transition:transform 1.4s cubic-bezier(.2,.7,.2,1)}
.logo-wrap{position:relative;width:clamp(110px,15vh,170px);aspect-ratio:1;margin-bottom:clamp(10px,2vh,22px)}
.logo-wrap img{width:100%;height:100%;border-radius:50%;object-fit:cover;background:#fff;padding:5px;position:relative;z-index:2;
  box-shadow:0 0 0 6px rgba(255,255,255,.12),0 18px 50px rgba(0,0,0,.45)}
.logo-wrap .ring{position:absolute;inset:-18px;border-radius:50%;border:2px dashed rgba(212,225,87,.55);animation:spin 40s linear infinite}
.logo-wrap .ring.r2{inset:-34px;border:1px solid rgba(255,255,255,.14);animation-duration:70s;animation-direction:reverse}
body.lit .logo-wrap img{box-shadow:0 0 0 6px rgba(245,197,66,.35),0 0 80px rgba(245,197,66,.45),0 18px 50px rgba(0,0,0,.45)}
@keyframes spin{to{transform:rotate(360deg)}}
.eyebrow{font-family:'Mukta','Nunito',sans-serif;font-size:clamp(14px,1.9vh,19px);font-weight:700;letter-spacing:.3em;text-transform:uppercase;color:var(--gold)}
.eyebrow span{opacity:.6;margin:0 .5em}
h1{font-size:clamp(40px,7.2vh,84px);font-weight:900;letter-spacing:-.02em;line-height:1.02;margin-top:6px;text-shadow:0 6px 30px rgba(0,0,0,.35)}
.local{font-family:'Mukta',sans-serif;font-size:clamp(24px,4.2vh,46px);font-weight:800;color:var(--lime);line-height:1.15}
.sub{margin-top:clamp(8px,1.4vh,14px);font-size:clamp(15px,2.1vh,22px);font-weight:700;color:var(--ink-2)}
.guest{margin-top:clamp(8px,1.6vh,16px);font-size:clamp(14px,1.9vh,19px);color:var(--ink-2)}
.guest strong{color:var(--cream);font-weight:800}

/* ---------- ribbon ---------- */
.ribbon-zone{position:relative;width:100%;height:clamp(70px,11vh,110px);display:grid;place-items:center;margin:clamp(6px,1.5vh,18px) 0 clamp(54px,8vh,84px)}
.ribbon{position:absolute;top:50%;height:clamp(26px,3.8vh,40px);margin-top:calc(clamp(26px,3.8vh,40px)/-2);
  background:linear-gradient(180deg,#E3243F 0%,var(--ribbon) 45%,var(--ribbon-2) 100%);
  box-shadow:0 8px 20px rgba(0,0,0,.35), inset 0 2px 0 rgba(255,255,255,.25), inset 0 -2px 0 rgba(0,0,0,.2);
  transition:transform 1.6s cubic-bezier(.5,0,.75,0), opacity 1.6s ease}
.ribbon::after{content:"";position:absolute;left:0;right:0;top:4px;bottom:4px;border-top:1px dashed rgba(245,197,66,.6);border-bottom:1px dashed rgba(245,197,66,.6)}
.ribbon.l{left:0;right:50%;transform-origin:0 50%}
.ribbon.r{right:0;left:50%;transform-origin:100% 50%}
body.cut .ribbon.l{transform:rotate(26deg) translateY(30vh);opacity:0}
body.cut .ribbon.r{transform:rotate(-26deg) translateY(30vh);opacity:0}
.bow{position:relative;z-index:3;width:clamp(120px,17vh,170px);transition:transform 1.2s ease, opacity 1.2s ease;filter:drop-shadow(0 10px 18px rgba(0,0,0,.4))}
body.cut .bow{transform:translateY(40vh) rotate(40deg);opacity:0}
.cut-btn{position:absolute;z-index:4;top:100%;margin-top:6px;display:inline-flex;align-items:center;gap:10px;
  font:800 clamp(15px,2vh,19px) 'Nunito',sans-serif;padding:12px 26px;border-radius:999px;border:0;cursor:pointer;
  color:#3A0A12;background:linear-gradient(180deg,#FFE48A,var(--gold) 55%,var(--gold-2));
  box-shadow:0 10px 30px rgba(245,197,66,.35),inset 0 1px 0 rgba(255,255,255,.7);
  opacity:0;transform:translateY(8px) scale(.96);pointer-events:none;transition:all .6s cubic-bezier(.2,.7,.2,1)}
.cut-btn svg{width:22px;height:22px}
body.lit:not(.cut) .cut-btn{opacity:1;transform:none;pointer-events:auto;animation:breathe 2.2s ease-in-out infinite 1s}
@keyframes breathe{50%{box-shadow:0 10px 44px rgba(245,197,66,.65),inset 0 1px 0 rgba(255,255,255,.7)}}

/* ---------- diya ---------- */
.diya-zone{display:flex;flex-direction:column;align-items:center;gap:8px;transition:opacity .8s}
.diya{width:clamp(130px,19vh,200px);cursor:pointer;background:none;border:0;padding:0;position:relative}
.diya svg{width:100%;display:block;overflow:visible}
.diya .flame{transform-origin:100px 66px;transform:scale(0);transition:transform 1.1s cubic-bezier(.2,1.6,.4,1)}
body.lit .diya .flame{transform:scale(1)}
body.lit .diya .flicker{animation:flicker 1.6s ease-in-out infinite}
@keyframes flicker{0%,100%{transform:scaleY(1) skewX(0)}25%{transform:scaleY(1.07) skewX(2deg)}50%{transform:scaleY(.94) skewX(-2deg)}75%{transform:scaleY(1.04) skewX(1deg)}}
.diya .halo{opacity:0;transition:opacity 1.6s}
body.lit .diya .halo{opacity:1}
.hint{font-size:clamp(14px,1.9vh,18px);font-weight:800;color:var(--cream);letter-spacing:.04em}
.hint small{display:block;font-family:'Mukta',sans-serif;font-weight:700;color:var(--gold);font-size:.95em}
body:not(.lit) .diya{animation:invite 2.4s ease-in-out infinite}
@keyframes invite{50%{transform:translateY(-4px);filter:drop-shadow(0 0 22px rgba(245,197,66,.55))}}

/* ---------- countdown ---------- */
.count{position:fixed;inset:0;z-index:40;display:grid;place-items:center;pointer-events:none;background:rgba(3,20,22,0);transition:background .4s}
.count.on{background:rgba(3,20,22,.6)}
.count span{font-size:min(42vh,40vw);font-weight:900;color:var(--cream);text-shadow:0 0 60px rgba(245,197,66,.7);animation:pop 1s ease-out forwards}
@keyframes pop{0%{transform:scale(.4);opacity:0}25%{opacity:1}100%{transform:scale(1.25);opacity:0}}

/* ---------- live ---------- */
.live{position:fixed;left:50%;top:56%;z-index:12;width:min(920px,calc(100vw - 40px));transform:translate(-50%,-40%);opacity:0;pointer-events:none;
  transition:opacity 1.2s ease .9s, transform 1.4s cubic-bezier(.2,.7,.2,1) .9s;text-align:center}
body.cut .live{opacity:1;transform:translate(-50%,-50%);pointer-events:auto}
body.cut .hero{transform:translateY(-9vh) scale(.8)}
body.cut .diya-zone{opacity:0;pointer-events:none}
.live-badge{display:inline-flex;align-items:center;gap:10px;font-weight:900;font-size:clamp(28px,5vh,54px);letter-spacing:.06em;text-transform:uppercase;
  background:linear-gradient(90deg,var(--lime),#F5F0A0,var(--gold));-webkit-background-clip:text;background-clip:text;color:transparent}
.live-dot{width:.42em;height:.42em;border-radius:50%;background:var(--lime);box-shadow:0 0 0 0 rgba(212,225,87,.7);animation:ping 1.8s infinite}
@keyframes ping{70%{box-shadow:0 0 0 .5em rgba(212,225,87,0)}100%{box-shadow:0 0 0 0 rgba(212,225,87,0)}}
.live p{margin-top:8px;font-size:clamp(15px,2.1vh,21px);color:var(--ink-2)}
.live p strong{color:var(--cream)}
.mods{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:clamp(14px,2.4vh,24px) 0}
.mods span{padding:7px 14px;border-radius:999px;font-size:clamp(12px,1.6vh,15px);font-weight:700;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);
  opacity:0;transform:translateY(8px)}
body.cut .mods span{animation:rise .6s cubic-bezier(.2,.7,.2,1) forwards}
@keyframes rise{to{opacity:1;transform:none}}
.enter{display:inline-flex;align-items:center;gap:10px;text-decoration:none;font-weight:900;font-size:clamp(15px,2vh,19px);padding:14px 30px;border-radius:999px;
  color:var(--teal-900);background:linear-gradient(180deg,#fff,#E6F3F2);box-shadow:0 14px 40px rgba(0,0,0,.35)}
.enter:hover{transform:translateY(-1px)}
.live .again{display:inline-block;margin-left:14px;color:var(--ink-2);font-weight:700;font-size:14px;background:none;border:0;cursor:pointer;text-decoration:underline}

@media (max-height:820px){ .live{top:61%} body.cut .hero{transform:translateY(-8vh) scale(.76)} }
@media (max-width:820px){ .curtain{width:8vw} .stage{padding-left:10vw;padding-right:10vw} }
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.3s!important}
}
</style>
</head>
<body class="<?= $done_at ? 'lit cut open' : '' ?>">

<canvas id="fx" aria-hidden="true"></canvas>
<div class="curtain l" aria-hidden="true"></div>
<div class="curtain r" aria-hidden="true"></div>
<div class="valance" aria-hidden="true"></div>

<div class="bar">
  <a class="chip" href="<?= base_url('admin/admin/dashboard') ?>">← Portal</a>
  <?php if ($rehearsal): ?><span class="chip warn">Rehearsal: nothing is saved</span><?php endif; ?>
  <span class="sp"></span>
  <button class="icon-btn" id="soundBtn" type="button" aria-label="Sound on" title="Sound (M)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 5 6 9H2v6h4l5 4z"/><path class="wave" d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></svg></button>
  <button class="icon-btn" id="fsBtn" type="button" aria-label="Full screen" title="Full screen (F)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button>
</div>

<main class="stage">
  <section class="hero">
    <?php if ($logo): ?>
    <div class="logo-wrap"><span class="ring"></span><span class="ring r2"></span><img src="<?= $logo ?>" alt="<?= $name ?> logo"></div>
    <?php endif; ?>
    <div class="eyebrow" lang="mr">शुभारंभ<span>·</span>Inauguration</div>
    <h1><?= $name ?></h1>
    <?php if ($local): ?><div class="local" lang="mr"><?= $local ?></div><?php endif; ?>
    <div class="sub"><?= $title ?><?= $tagline ? ' · ' . $tagline : '' ?></div>
    <?php if ($guest): ?><div class="guest">In the gracious presence of <strong><?= $guest ?></strong></div><?php endif; ?>
  </section>

  <section class="ribbon-zone" aria-label="Ribbon">
    <div class="ribbon l"></div><div class="ribbon r"></div>
    <svg class="bow" viewBox="0 0 200 120" aria-hidden="true">
      <defs><linearGradient id="rb" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E8324C"/><stop offset="1" stop-color="#8E0B21"/></linearGradient></defs>
      <path d="M100 60 C70 10 20 10 22 45 C24 78 70 78 100 60Z" fill="url(#rb)"/>
      <path d="M100 60 C130 10 180 10 178 45 C176 78 130 78 100 60Z" fill="url(#rb)"/>
      <path d="M96 64 L70 118 L84 112 L92 120 L100 70Z" fill="#A70F27"/>
      <path d="M104 64 L130 118 L116 112 L108 120 L100 70Z" fill="#A70F27"/>
      <path d="M44 36 C60 30 80 44 92 56" stroke="rgba(255,255,255,.35)" stroke-width="3" fill="none"/>
      <path d="M156 36 C140 30 120 44 108 56" stroke="rgba(255,255,255,.35)" stroke-width="3" fill="none"/>
      <circle cx="100" cy="60" r="15" fill="#F5C542" stroke="#B9851A" stroke-width="3"/>
    </svg>
    <button class="cut-btn" id="cutBtn" type="button">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M20 4 8.1 15.9M14.5 14.5 20 20M8.1 8.1 12 12"/></svg>
      Cut the Ribbon
    </button>
  </section>

  <section class="diya-zone">
    <button class="diya" id="diyaBtn" type="button" aria-label="Light the lamp">
      <svg viewBox="0 0 200 150" aria-hidden="true">
        <defs>
          <linearGradient id="brass" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#F8D77A"/><stop offset=".5" stop-color="#D9A53A"/><stop offset="1" stop-color="#8C5E14"/></linearGradient>
          <radialGradient id="glow" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="rgba(255,214,102,.75)"/><stop offset="1" stop-color="rgba(255,214,102,0)"/></radialGradient>
          <linearGradient id="fl" x1="0" y1="1" x2="0" y2="0"><stop offset="0" stop-color="#FF7A00"/><stop offset=".55" stop-color="#FFC233"/><stop offset="1" stop-color="#FFF6C8"/></linearGradient>
        </defs>
        <circle class="halo" cx="100" cy="52" r="58" fill="url(#glow)"/>
        <!-- lamp: bowl, spout, stand -->
        <path d="M30 86 Q100 70 170 86 Q160 120 100 122 Q40 120 30 86Z" fill="url(#brass)"/>
        <path d="M100 80 L118 70 Q128 66 128 74 Q124 84 108 86Z" fill="#E2B04A"/>
        <ellipse cx="100" cy="86" rx="70" ry="9" fill="#6B4510" opacity=".55"/>
        <ellipse cx="100" cy="85" rx="62" ry="6" fill="#B07A1E"/>
        <path d="M84 122 H116 L124 136 H76Z" fill="url(#brass)"/>
        <ellipse cx="100" cy="138" rx="34" ry="6" fill="#8C5E14"/>
        <path d="M44 98 Q100 110 156 98" stroke="rgba(255,255,255,.35)" stroke-width="2" fill="none"/>
        <circle cx="70" cy="104" r="3" fill="#FCE7A8"/><circle cx="100" cy="108" r="3" fill="#FCE7A8"/><circle cx="130" cy="104" r="3" fill="#FCE7A8"/>
        <!-- wick + flame -->
        <path d="M118 70 q3 -6 0 -10" stroke="#3A2A12" stroke-width="3" fill="none" stroke-linecap="round"/>
        <g class="flame" style="transform-origin:118px 62px">
          <g class="flicker" style="transform-origin:118px 62px">
            <path d="M118 62 C100 44 110 22 118 6 C126 22 136 44 118 62Z" fill="url(#fl)"/>
            <path d="M118 60 C111 50 114 38 118 30 C122 38 125 50 118 60Z" fill="#FFFBEA"/>
          </g>
        </g>
      </svg>
    </button>
    <div class="hint" id="hint">Light the lamp to begin<small lang="mr">दीप प्रज्वलन</small></div>
  </section>
</main>

<div class="count" id="count" aria-live="assertive"></div>

<section class="live" aria-live="polite">
  <div class="live-badge"><span class="live-dot"></span>Now Live</div>
  <p id="liveWhen"><?php if ($done_at): ?>Inaugurated on <strong><?= $done_at ?></strong><?= $done_by ? ' by <strong>' . $done_by . '</strong>' : '' ?><?php endif; ?></p>
  <div class="mods">
    <?php foreach ($modules as $i => $m): ?><span style="animation-delay:<?= 1.4 + $i * 0.08 ?>s"><?= html_escape($m) ?></span><?php endforeach; ?>
  </div>
  <a class="enter" href="<?= base_url('admin/admin/dashboard') ?>">Enter the portal
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
  <button class="again" id="againBtn" type="button">Replay ceremony</button>
</section>

<script>
(function () {
  var body = document.body;
  var REHEARSAL = <?= $rehearsal ? 'true' : 'false' ?>;
  var CAN_MARK  = <?= $can_mark ? 'true' : 'false' ?>;
  var GUEST     = <?= json_encode(trim((string) $brand['inauguration_guest'])) ?>;
  var reduce    = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var busy = false;

  /* ---------------- sound: synthesised temple bell + soft ticks ---------------- */
  var soundOn = true, ac = null;
  function ctx() { if (!ac) { try { ac = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} } return ac; }
  function bell(t0, base) {
    var a = ctx(); if (!a || !soundOn) return;
    var now = a.currentTime + (t0 || 0);
    [1, 2.76, 5.4, 8.93].forEach(function (ratio, i) {
      var o = a.createOscillator(), g = a.createGain();
      o.type = 'sine'; o.frequency.value = (base || 392) * ratio;
      g.gain.setValueAtTime(0.0001, now);
      g.gain.exponentialRampToValueAtTime([0.35, 0.18, 0.1, 0.05][i], now + 0.01);
      g.gain.exponentialRampToValueAtTime(0.0001, now + 4.5 / (i + 1));
      o.connect(g).connect(a.destination); o.start(now); o.stop(now + 5);
    });
  }
  function tick() {
    var a = ctx(); if (!a || !soundOn) return;
    var o = a.createOscillator(), g = a.createGain(), n = a.currentTime;
    o.type = 'triangle'; o.frequency.value = 660;
    g.gain.setValueAtTime(0.25, n); g.gain.exponentialRampToValueAtTime(0.0001, n + 0.25);
    o.connect(g).connect(a.destination); o.start(n); o.stop(n + 0.3);
  }
  var soundBtn = document.getElementById('soundBtn');
  function toggleSound() {
    soundOn = !soundOn;
    soundBtn.querySelector('.wave').style.opacity = soundOn ? 1 : 0.15;
    soundBtn.setAttribute('aria-label', soundOn ? 'Sound on' : 'Sound off');
  }
  soundBtn.addEventListener('click', toggleSound);

  /* ---------------- full screen ---------------- */
  function toggleFs() {
    if (!document.fullscreenElement) { (document.documentElement.requestFullscreen || function(){}).call(document.documentElement); }
    else { document.exitFullscreen(); }
  }
  document.getElementById('fsBtn').addEventListener('click', toggleFs);

  /* ---------------- particles: stars, marigold petals, confetti ---------------- */
  var cv = document.getElementById('fx'), c = cv.getContext('2d'), W, H, DPR = Math.min(2, window.devicePixelRatio || 1);
  function size() { W = innerWidth; H = innerHeight; cv.width = W * DPR; cv.height = H * DPR; c.setTransform(DPR, 0, 0, DPR, 0, 0); }
  size(); addEventListener('resize', size);
  var stars = [], parts = [];
  for (var i = 0; i < 90; i++) stars.push({ x: Math.random(), y: Math.random() * 0.75, r: Math.random() * 1.4 + 0.3, p: Math.random() * 6.28 });
  var PETAL = ['#F59E0B', '#FBBF24', '#F97316', '#FDE047', '#EA580C'];
  var CONF  = ['#D4E157', '#FFFFFF', '#1A7A7E', '#5EC4C0', '#1E5AA8', '#F5C542', '#C8102E'];
  function spawn(kind, n, burst) {
    for (var i = 0; i < n; i++) {
      var petal = kind === 'petal';
      parts.push({
        k: kind,
        x: burst ? W / 2 + (Math.random() - 0.5) * 80 : Math.random() * W,
        y: burst ? H * 0.5 : -20 - Math.random() * H * 0.5,
        vx: burst ? (Math.random() - 0.5) * 16 : (Math.random() - 0.5) * 0.8,
        vy: burst ? -Math.random() * 14 - 4 : Math.random() * 1.2 + (petal ? 0.8 : 1.6),
        s: petal ? Math.random() * 6 + 6 : Math.random() * 5 + 4,
        a: Math.random() * 6.28, va: (Math.random() - 0.5) * 0.2,
        sway: Math.random() * 6.28,
        col: (petal ? PETAL : CONF)[Math.floor(Math.random() * (petal ? PETAL : CONF).length)],
        life: 0
      });
    }
  }
  var rain = 0; // frames left of continuous petal rain
  function frame(t) {
    c.clearRect(0, 0, W, H);
    if (!body.classList.contains('cut')) {
      for (var i = 0; i < stars.length; i++) {
        var s = stars[i]; c.globalAlpha = 0.25 + 0.35 * Math.sin(t / 900 + s.p);
        c.fillStyle = '#fff'; c.beginPath(); c.arc(s.x * W, s.y * H, s.r, 0, 6.28); c.fill();
      }
      c.globalAlpha = 1;
    }
    if (rain > 0) { rain--; if (rain % 4 === 0) spawn('petal', 2); if (rain % 6 === 0) spawn('conf', 2); }
    for (var j = parts.length - 1; j >= 0; j--) {
      var p = parts[j]; p.life++;
      if (p.vy < 0 || Math.abs(p.vx) > 1) { p.vx *= 0.97; p.vy += 0.35; } // burst physics
      p.sway += 0.03; p.x += p.vx + Math.sin(p.sway) * 0.6; p.y += p.vy > 3.5 && p.life > 60 ? 3.5 : p.vy; p.a += p.va;
      if (p.vy > 0 && p.life > 40 && p.vy > 2.2) p.vy = 2.2;
      c.save(); c.translate(p.x, p.y); c.rotate(p.a); c.fillStyle = p.col;
      if (p.k === 'petal') { c.beginPath(); c.ellipse(0, 0, p.s, p.s * 0.55, 0, 0, 6.28); c.fill(); c.globalAlpha = .25; c.fillStyle = '#7C2D12'; c.fillRect(-p.s * .6, -0.5, p.s * 1.2, 1); }
      else { c.scale(1, Math.cos(p.life / 8)); c.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2); }
      c.restore();
      if (p.y > H + 30) parts.splice(j, 1);
    }
    requestAnimationFrame(frame);
  }
  if (!reduce) requestAnimationFrame(frame);

  /* ---------------- ceremony ---------------- */
  var hint = document.getElementById('hint');
  function lightLamp() {
    if (busy || body.classList.contains('lit')) return;
    body.classList.add('lit'); bell(0, 523);
    hint.innerHTML = 'The lamp is lit<small lang="mr">शुभं भवतु</small>';
    setTimeout(function () { document.getElementById('cutBtn').focus({ preventScroll: true }); }, 900);
  }
  function cutRibbon() {
    if (busy || !body.classList.contains('lit') || body.classList.contains('cut')) return;
    busy = true;
    var box = document.getElementById('count'), n = reduce ? 0 : 3;
    (function step() {
      if (n > 0) { box.classList.add('on'); box.innerHTML = '<span>' + n + '</span>'; tick(); n--; setTimeout(step, 1000); return; }
      box.innerHTML = ''; box.classList.remove('on');
      body.classList.add('cut', 'open');
      bell(0, 392); bell(0.35, 523); bell(0.8, 659);
      if (!reduce) { spawn('conf', 160, true); spawn('petal', 60, true); rain = 60 * 9; }
      record();
      busy = false;
    })();
  }
  function record() {
    var when = document.getElementById('liveWhen');
    var stamp = new Date().toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    var by = GUEST ? ' by <strong>' + GUEST.replace(/[&<>"]/g, function (ch) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]; }) + '</strong>' : '';
    when.innerHTML = 'Inaugurated on <strong>' + stamp + '</strong>' + by;
    if (REHEARSAL || !CAN_MARK) return;
    fetch('<?= base_url('admin/inauguration/mark') ?>', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'cut=1' })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (res && res.status === 'success') { when.innerHTML = 'Inaugurated on <strong>' + res.display + '</strong>' + by; } })
      .catch(function () {});
  }
  function replay() {
    parts.length = 0; rain = 0;
    body.classList.remove('cut', 'open', 'lit');
    hint.innerHTML = 'Light the lamp to begin<small lang="mr">दीप प्रज्वलन</small>';
    setTimeout(function () { document.getElementById('diyaBtn').focus({ preventScroll: true }); }, 400);
  }
  document.getElementById('diyaBtn').addEventListener('click', lightLamp);
  document.getElementById('cutBtn').addEventListener('click', cutRibbon);
  document.getElementById('againBtn').addEventListener('click', replay);

  // Presenter remotes send →, PageDown or Space; F = full screen, M = mute.
  addEventListener('keydown', function (e) {
    if (e.key === 'f' || e.key === 'F') { toggleFs(); return; }
    if (e.key === 'm' || e.key === 'M') { toggleSound(); return; }
    if (['ArrowRight', 'PageDown', ' ', 'Enter'].indexOf(e.key) === -1) return;
    if (document.activeElement && document.activeElement.tagName === 'A') return;
    e.preventDefault();
    if (!body.classList.contains('lit')) lightLamp(); else if (!body.classList.contains('cut')) cutRibbon();
  });
  if (body.classList.contains('cut') && !reduce) { spawn('petal', 40); rain = 60 * 4; }
})();
</script>
</body>
</html>
