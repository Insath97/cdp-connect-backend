<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="CDP CORE — Investment Management Platform API by CDP Empire (Pvt) Ltd." />
  <meta name="robots" content="noindex, nofollow" />
  <title>CDP CORE API | CDP Empire (Pvt) Ltd</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />

  <style>
    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      width: 100%; height: 100%;
      overflow: hidden; /* hero-only, no scroll */
      font-family: 'Inter', system-ui, sans-serif;
    }

    /* =========================================================
       SPOTLIGHT CURSOR
    ========================================================= */
    #spotlight {
      position: fixed;
      pointer-events: none;
      z-index: 9999;
      width: 600px;
      height: 600px;
      border-radius: 50%;
      transform: translate(-50%, -50%);
      background: radial-gradient(
        circle at center,
        rgba(41, 140, 119, 0.18) 0%,
        rgba(41, 140, 119, 0.08) 30%,
        rgba(41, 140, 119, 0.02) 60%,
        transparent 75%
      );
      opacity: 0;
      transition: opacity 0.5s ease;
      will-change: transform, left, top;
      top: 0; left: 0;
    }

    /* =========================================================
       HERO
    ========================================================= */
    .hero {
      position: relative;
      width: 100vw;
      height: 100vh;
      background: #0e2b27;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }

    /* Full-screen canvas */
    #hero-canvas {
      position: absolute;
      top: 0; left: 0;
      width: 100%;
      height: 100%;
      display: block;
      z-index: 0;
    }

    /* Radial overlay for depth */
    .hero__overlay {
      position: absolute;
      inset: 0;
      z-index: 1;
      background:
        radial-gradient(ellipse 80% 60% at 50% 50%, rgba(41,140,119,0.15) 0%, transparent 70%),
        radial-gradient(ellipse 100% 50% at 50% 100%, rgba(14,43,39,0.9) 0%, transparent 60%);
      pointer-events: none;
    }

    /* ── NAV ── */
    .nav {
      position: absolute;
      top: 0; left: 0; right: 0;
      z-index: 10;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 clamp(20px, 5vw, 56px);
      height: 70px;
      background: rgba(14, 43, 39, 0.6);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid rgba(154, 226, 210, 0.1);
    }
    .nav__brand {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
    }
    .nav__icon {
      width: 38px; height: 38px;
      border-radius: 10px;
      background: linear-gradient(135deg, #46b2a0, #1e594f);
      display: grid;
      place-items: center;
      box-shadow: 0 0 18px rgba(41,140,119,0.5);
    }
    .nav__icon svg { width: 20px; height: 20px; fill: #fff; }
    .nav__name {
      font-size: 1.1rem;
      font-weight: 800;
      color: #fff;
      letter-spacing: -0.02em;
    }
    .nav__name span { color: #68cdb9; }
    .nav__status {
      display: flex;
      align-items: center;
      gap: 8px;
      background: rgba(41,140,119,0.18);
      border: 1px solid rgba(41,140,119,0.35);
      border-radius: 999px;
      padding: 6px 16px;
      font-size: 0.78rem;
      font-weight: 600;
      color: #9ae2d2;
    }
    .status-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: #22c55e;
      animation: pulseDot 2s infinite;
    }

    /* ── HERO CONTENT ── */
    .hero__content {
      position: relative;
      z-index: 5;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      gap: clamp(18px, 2.5vw, 26px);
      padding: 0 clamp(20px, 6vw, 40px);
      max-width: 860px;
      width: 100%;
    }

    /* Eyebrow pill */
    .eyebrow {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 6px 18px;
      border-radius: 999px;
      background: rgba(41,140,119,0.15);
      border: 1px solid rgba(154,226,210,0.22);
      font-size: 0.75rem;
      font-weight: 700;
      color: #9ae2d2;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      opacity: 0;
      animation: fadeSlideDown 0.7s 0.2s ease forwards;
    }
    .eyebrow::before {
      content: '';
      width: 6px; height: 6px;
      border-radius: 50%;
      background: #46b2a0;
    }

    /* Floating shield logo */
    .hero__logo {
      width: clamp(72px, 10vw, 108px);
      height: clamp(72px, 10vw, 108px);
      border-radius: clamp(18px, 2.5vw, 26px);
      background: linear-gradient(145deg, #298c77, #1b4a43);
      display: grid;
      place-items: center;
      box-shadow:
        0 0 0 1px rgba(154,226,210,0.18),
        0 0 0 8px rgba(41,140,119,0.08),
        0 0 50px rgba(41,140,119,0.35),
        0 20px 60px rgba(0,0,0,0.5);
      opacity: 0;
      animation: fadeSlideUp 0.8s 0.4s ease forwards, floatY 5s 1.2s ease-in-out infinite;
    }
    .hero__logo svg { width: 52%; height: 52%; }

    /* Headline */
    h1.headline {
      font-size: clamp(2.8rem, 7vw, 5.2rem);
      font-weight: 900;
      line-height: 1.05;
      letter-spacing: -0.04em;
      color: #fff;
      opacity: 0;
      animation: fadeSlideUp 0.8s 0.55s ease forwards;
    }
    h1.headline .teal {
      background: linear-gradient(135deg, #9ae2d2 0%, #46b2a0 50%, #68cdb9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    /* Sub */
    .hero__sub {
      font-size: clamp(0.95rem, 1.8vw, 1.15rem);
      color: rgba(196,240,229,0.75);
      line-height: 1.75;
      max-width: 580px;
      opacity: 0;
      animation: fadeSlideUp 0.8s 0.7s ease forwards;
    }

    /* Version row */
    .version-row {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      justify-content: center;
      opacity: 0;
      animation: fadeSlideUp 0.7s 0.85s ease forwards;
    }
    .ver-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 5px 14px;
      border-radius: 999px;
      background: rgba(41,140,119,0.18);
      border: 1px solid rgba(41,140,119,0.3);
      font-size: 0.78rem;
      font-weight: 600;
      color: #9ae2d2;
    }
    .ver-badge .live-dot {
      width: 6px; height: 6px;
      border-radius: 50%;
      background: #22c55e;
      animation: pulseDot 2s infinite;
    }
    .sep { color: rgba(154,226,210,0.3); font-size: 0.85rem; }

    /* CTA buttons */
    .hero__ctas {
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      justify-content: center;
      opacity: 0;
      animation: fadeSlideUp 0.8s 1s ease forwards;
    }
    .btn-primary {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      padding: 15px 32px;
      border-radius: 999px;
      background: linear-gradient(135deg, #298c77, #227061);
      color: #fff;
      font-size: 0.95rem;
      font-weight: 700;
      text-decoration: none;
      border: none;
      cursor: pointer;
      box-shadow:
        0 0 0 1px rgba(154,226,210,0.15),
        0 8px 30px rgba(41,140,119,0.4);
      transition: transform 0.25s ease, box-shadow 0.3s ease;
      animation: glowPulse 3.5s 2s infinite;
    }
    .btn-primary:hover {
      transform: translateY(-3px) scale(1.03);
      box-shadow:
        0 0 0 1px rgba(154,226,210,0.25),
        0 16px 40px rgba(41,140,119,0.55);
      animation: none;
    }
    .btn-primary svg { width: 17px; height: 17px; fill: currentColor; }

    .btn-ghost {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      padding: 14px 30px;
      border-radius: 999px;
      background: rgba(255,255,255,0.05);
      color: #9ae2d2;
      font-size: 0.95rem;
      font-weight: 600;
      text-decoration: none;
      border: 1px solid rgba(154,226,210,0.22);
      cursor: pointer;
      transition: transform 0.25s ease, background 0.25s, border-color 0.25s;
    }
    .btn-ghost:hover {
      transform: translateY(-3px) scale(1.02);
      background: rgba(41,140,119,0.15);
      border-color: rgba(154,226,210,0.45);
    }

    /* Scroll cue */
    .scroll-cue {
      position: absolute;
      bottom: 32px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      opacity: 0;
      animation: fadeIn 1s 1.6s forwards;
      z-index: 5;
    }
    .scroll-cue span {
      font-size: 0.65rem;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: rgba(154,226,210,0.45);
    }
    .scroll-line {
      width: 1px;
      height: 48px;
      background: linear-gradient(to bottom, #298c77, transparent);
      animation: scrollPulse 2.2s ease-in-out infinite;
    }

    /* =========================================================
       KEYFRAMES
    ========================================================= */
    @keyframes fadeSlideDown {
      from { opacity: 0; transform: translateY(-18px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeSlideUp {
      from { opacity: 0; transform: translateY(22px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeIn {
      from { opacity: 0; }
      to   { opacity: 1; }
    }
    @keyframes floatY {
      0%, 100% { transform: translateY(0px); }
      50%       { transform: translateY(-14px); }
    }
    @keyframes pulseDot {
      0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
      50%       { opacity: 0.7; box-shadow: 0 0 0 6px rgba(34,197,94,0); }
    }
    @keyframes glowPulse {
      0%, 100% { box-shadow: 0 0 0 1px rgba(154,226,210,0.15), 0 8px 30px rgba(41,140,119,0.4); }
      50%       { box-shadow: 0 0 0 1px rgba(154,226,210,0.25), 0 8px 40px rgba(41,140,119,0.65), 0 0 60px rgba(41,140,119,0.2); }
    }
    @keyframes scrollPulse {
      0%, 100% { opacity: 0.4; transform: scaleY(1); }
      50%       { opacity: 1;   transform: scaleY(1.25); }
    }

    /* =========================================================
       RESPONSIVE
    ========================================================= */
    @media (max-width: 640px) {
      .hero__ctas { flex-direction: column; align-items: center; }
      .btn-primary, .btn-ghost { width: 100%; max-width: 280px; justify-content: center; }
      .nav__status span:last-child { display: none; }
    }
    @media (max-height: 640px) {
      .hero__logo { display: none; }
      .scroll-cue { display: none; }
    }
  </style>
</head>
<body>

  <!-- Spotlight cursor -->
  <div id="spotlight" aria-hidden="true"></div>

  <!-- ── HERO ── -->
  <section class="hero" id="hero">

    <!-- Full-screen particle canvas -->
    <canvas id="hero-canvas" aria-hidden="true"></canvas>

    <!-- Depth overlay -->
    <div class="hero__overlay" aria-hidden="true"></div>

    <!-- NAV -->
    <nav class="nav" aria-label="Main navigation">
      <a href="#" class="nav__brand" aria-label="CDP CORE">
        <div class="nav__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7L12 2zm-1 13l-3-3 1.41-1.41L11 12.17l4.59-4.58L17 9l-6 6z"/></svg>
        </div>
        <span class="nav__name">CDP <span>CORE</span></span>
      </a>
      <div class="nav__status" role="status" aria-live="polite">
        <span class="status-dot" aria-hidden="true"></span>
        <span id="nav-status">API Online</span>
      </div>
    </nav>

    <!-- Content -->
    <div class="hero__content">

      <!-- Eyebrow -->
      <div class="eyebrow">CDP Empire (Pvt) Ltd</div>

      <!-- Logo -->
      <div class="hero__logo" aria-hidden="true">
        <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M32 4L8 16v16c0 14 10 27.08 24 30.24C46 59.08 56 45.96 56 32V16L32 4z"
            fill="rgba(255,255,255,0.06)"
            stroke="rgba(154,226,210,0.5)"
            stroke-width="1.5"/>
          <path d="M32 12L14 21.5V32c0 10.5 7.5 20.3 18 22.68C43.5 52.3 50 42.48 50 32V21.5L32 12z"
            fill="rgba(41,140,119,0.25)"/>
          <path d="M24 32l-4-4 2.12-2.12L24 27.76l9.88-9.88L36 20 24 32z"
            fill="white"/>
          <path d="M20 38h24M20 44h16"
            stroke="rgba(154,226,210,0.6)"
            stroke-width="2"
            stroke-linecap="round"/>
        </svg>
      </div>

      <!-- Headline -->
      <h1 class="headline">
        <span class="teal">CDP CORE</span><br>Investment API
      </h1>

      <!-- Subtext -->
      <p class="hero__sub">
        The backbone of CDP Empire's investment management platform —
        powering agent hierarchies, investment lifecycles, commission
        tracking, and regulatory compliance.
      </p>

      <!-- Version info -->
      <div class="version-row">
        <span class="ver-badge">
          <span class="live-dot" aria-hidden="true"></span>
          API v1.0.0
        </span>
        <span class="sep" aria-hidden="true">·</span>
        <span class="ver-badge">Laravel 12</span>
        <span class="sep" aria-hidden="true">·</span>
        <span class="ver-badge">REST / JWT</span>
      </div>


    </div>



  </section>

  <script>
  (function () {

    /* =====================================================
       1. PARTICLE NETWORK CANVAS
    ===================================================== */
    const canvas = document.getElementById('hero-canvas');
    const ctx    = canvas.getContext('2d');

    let W = 0, H = 0;
    const COUNT    = 90;
    const MAX_DIST = 140;
    let particles  = [];

    // Size canvas to window
    function resize() {
      W = canvas.width  = window.innerWidth;
      H = canvas.height = window.innerHeight;
    }

    class Dot {
      constructor() {
        this.x  = Math.random() * W;
        this.y  = Math.random() * H;
        this.vx = (Math.random() - 0.5) * 0.5;
        this.vy = (Math.random() - 0.5) * 0.5;
        this.r  = Math.random() * 1.8 + 0.8;
        this.a  = Math.random() * 0.5 + 0.15;
      }
      move() {
        this.x += this.vx;
        this.y += this.vy;
        if (this.x < 0 || this.x > W) this.vx *= -1;
        if (this.y < 0 || this.y > H) this.vy *= -1;
      }
      draw() {
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(154,226,210,${this.a})`;
        ctx.fill();
      }
    }

    function init() {
      resize();
      particles = Array.from({ length: COUNT }, () => new Dot());
    }

    function connectDots() {
      for (let i = 0; i < particles.length; i++) {
        for (let j = i + 1; j < particles.length; j++) {
          const dx   = particles[i].x - particles[j].x;
          const dy   = particles[i].y - particles[j].y;
          const dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < MAX_DIST) {
            const alpha = (1 - dist / MAX_DIST) * 0.2;
            ctx.beginPath();
            ctx.moveTo(particles[i].x, particles[i].y);
            ctx.lineTo(particles[j].x, particles[j].y);
            ctx.strokeStyle = `rgba(41,140,119,${alpha})`;
            ctx.lineWidth   = 1;
            ctx.stroke();
          }
        }
      }
    }

    function loop() {
      ctx.clearRect(0, 0, W, H);
      particles.forEach(p => { p.move(); p.draw(); });
      connectDots();
      requestAnimationFrame(loop);
    }

    init();
    loop();

    // Re-size on window resize
    window.addEventListener('resize', () => {
      resize();
      // Re-randomize positions so dots fill new size
      particles.forEach(p => {
        p.x = Math.random() * W;
        p.y = Math.random() * H;
      });
    });


    /* =====================================================
       2. SPOTLIGHT / FLASHLIGHT CURSOR
    ===================================================== */
    const spot = document.getElementById('spotlight');
    let mx = -1000, my = -1000; // raw mouse
    let sx = -1000, sy = -1000; // smoothed position

    document.addEventListener('mousemove', e => {
      mx = e.clientX;
      my = e.clientY;
      spot.style.opacity = '1';
    });

    document.addEventListener('mouseleave', () => {
      spot.style.opacity = '0';
    });

    function animateSpot() {
      // Lerp — smooth lag follow
      sx += (mx - sx) * 0.09;
      sy += (my - sy) * 0.09;
      spot.style.left = sx + 'px';
      spot.style.top  = sy + 'px';
      requestAnimationFrame(animateSpot);
    }
    animateSpot();


    /* =====================================================
       3. HEALTH CHECK — nav status dot
    ===================================================== */
    async function pingHealth() {
      try {
        const r = await fetch('/health', { cache: 'no-store' });
        const d = await r.json();
        const ok = d.status === 'healthy';
        document.querySelector('.status-dot').style.background =
          ok ? '#22c55e' : d.status === 'degraded' ? '#f59e0b' : '#ef4444';
        document.getElementById('nav-status').textContent =
          ok ? 'API Online' : d.status === 'degraded' ? 'Degraded' : 'Offline';
      } catch {
        document.querySelector('.status-dot').style.background = '#ef4444';
        document.getElementById('nav-status').textContent = 'Offline';
      }
    }
    pingHealth();
    setInterval(pingHealth, 30000);

  })();
  </script>

</body>
</html>
