/* =========================================================
   CDP CORE — Landing Page JavaScript
   ========================================================= */

document.addEventListener('DOMContentLoaded', () => {

  /* ── 1. NAV: Scroll + Hamburger ──────────────────────── */
  const nav        = document.getElementById('nav');
  const hamburger  = document.getElementById('nav-hamburger');
  const mobileMenu = document.getElementById('nav-mobile');

  window.addEventListener('scroll', () => {
    nav.classList.toggle('scrolled', window.scrollY > 30);
  }, { passive: true });

  hamburger?.addEventListener('click', () => {
    const open = mobileMenu.classList.toggle('open');
    hamburger.setAttribute('aria-expanded', open);
    hamburger.querySelectorAll('span')[0].style.transform = open ? 'rotate(45deg) translate(5px,5px)' : '';
    hamburger.querySelectorAll('span')[1].style.opacity  = open ? '0' : '1';
    hamburger.querySelectorAll('span')[2].style.transform = open ? 'rotate(-45deg) translate(5px,-5px)' : '';
  });

  // Close mobile menu on link click
  mobileMenu?.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => mobileMenu.classList.remove('open'));
  });


  /* ── 2. HERO CANVAS: Particle Network ───────────────── */
  const canvas = document.getElementById('hero-canvas');
  const ctx    = canvas.getContext('2d');
  let W, H, particles;

  const PARTICLE_COUNT = 70;
  const MAX_DIST       = 130;

  class Particle {
    constructor() { this.reset(); }
    reset() {
      this.x  = Math.random() * W;
      this.y  = Math.random() * H;
      this.vx = (Math.random() - 0.5) * 0.45;
      this.vy = (Math.random() - 0.5) * 0.45;
      this.r  = Math.random() * 2 + 1;
      this.opacity = Math.random() * 0.4 + 0.15;
    }
    update() {
      this.x += this.vx;
      this.y += this.vy;
      if (this.x < 0 || this.x > W) this.vx *= -1;
      if (this.y < 0 || this.y > H) this.vy *= -1;
    }
    draw() {
      ctx.beginPath();
      ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
      ctx.fillStyle = `rgba(154,226,210,${this.opacity})`;
      ctx.fill();
    }
  }

  function initCanvas() {
    W = canvas.width  = canvas.offsetWidth;
    H = canvas.height = canvas.offsetHeight;
    particles = Array.from({ length: PARTICLE_COUNT }, () => new Particle());
  }

  function drawLines() {
    for (let i = 0; i < particles.length; i++) {
      for (let j = i + 1; j < particles.length; j++) {
        const dx   = particles[i].x - particles[j].x;
        const dy   = particles[i].y - particles[j].y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < MAX_DIST) {
          const alpha = (1 - dist / MAX_DIST) * 0.18;
          ctx.beginPath();
          ctx.moveTo(particles[i].x, particles[i].y);
          ctx.lineTo(particles[j].x, particles[j].y);
          ctx.strokeStyle = `rgba(41,140,119,${alpha})`;
          ctx.lineWidth = 1;
          ctx.stroke();
        }
      }
    }
  }

  function animateCanvas() {
    ctx.clearRect(0, 0, W, H);
    particles.forEach(p => { p.update(); p.draw(); });
    drawLines();
    requestAnimationFrame(animateCanvas);
  }

  initCanvas();
  animateCanvas();
  window.addEventListener('resize', initCanvas, { passive: true });


  /* ── 3. SPOTLIGHT / FLASHLIGHT CURSOR ───────────────── */
  const spotlight = document.getElementById('spotlight');
  let mouseX = -600, mouseY = -600;
  let spotX   = -600, spotY = -600;

  // Track mouse position
  document.addEventListener('mousemove', e => {
    mouseX = e.clientX;
    mouseY = e.clientY;
  });

  // Only show spotlight over dark sections
  const darkSections = [
    document.getElementById('hero'),
    document.getElementById('endpoints'),
    document.getElementById('techstack'),
  ].filter(Boolean);

  function isOverDark() {
    for (const sec of darkSections) {
      const r = sec.getBoundingClientRect();
      if (mouseY >= r.top && mouseY <= r.bottom &&
          mouseX >= r.left && mouseX <= r.right) return true;
    }
    return false;
  }

  function animateSpotlight() {
    // Smooth lag follow
    spotX += (mouseX - spotX) * 0.08;
    spotY += (mouseY - spotY) * 0.08;

    if (isOverDark()) {
      spotlight.style.opacity  = '1';
      spotlight.style.left     = spotX + 'px';
      spotlight.style.top      = spotY + 'px';
    } else {
      spotlight.style.opacity = '0';
    }
    requestAnimationFrame(animateSpotlight);
  }
  animateSpotlight();

  // Hide spotlight when mouse leaves window
  document.addEventListener('mouseleave', () => {
    mouseX = -600; mouseY = -600;
  });


  /* ── 4. INTERSECTION OBSERVER: Reveal Animations ─────── */
  const revealEls = document.querySelectorAll(
    '.feature-card, .arch-step, .h-item, .ep-row, .stats__item'
  );

  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry, i) => {
      if (entry.isIntersecting) {
        const el = entry.target;
        const delay = el.dataset.delay || 0;
        setTimeout(() => el.classList.add('visible'), Number(delay));
        observer.unobserve(el);
      }
    });
  }, { threshold: 0.12 });

  revealEls.forEach((el, i) => {
    el.dataset.delay = i * 60;
    observer.observe(el);
  });

  // Stagger stats on scroll
  document.querySelectorAll('.stats__item').forEach((el, i) => {
    el.style.transitionDelay = (i * 80) + 'ms';
  });


  /* ── 5. COUNT-UP ANIMATION ────────────────────────────── */
  const counters = document.querySelectorAll('[data-count]');
  const countObs = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      const el     = entry.target;
      const target = parseInt(el.dataset.count);
      const suffix = el.dataset.suffix || '';
      const dur    = 1800;
      const start  = performance.now();
      function step(now) {
        const t    = Math.min((now - start) / dur, 1);
        const ease = 1 - Math.pow(1 - t, 3); // cubic ease-out
        el.textContent = Math.floor(ease * target) + suffix;
        if (t < 1) requestAnimationFrame(step);
        else el.textContent = target + suffix;
      }
      requestAnimationFrame(step);
      countObs.unobserve(el);
    });
  }, { threshold: 0.5 });

  counters.forEach(el => countObs.observe(el));


  /* ── 6. ENDPOINT TABS ─────────────────────────────────── */
  const tabs   = document.querySelectorAll('.ep-tab');
  const panels = document.querySelectorAll('.ep-panel');

  function activateTab(tabId) {
    tabs.forEach(t   => t.classList.toggle('active', t.dataset.tab === tabId));
    panels.forEach(p => {
      const active = p.id === 'panel-' + tabId;
      p.classList.toggle('active', active);
      if (active) {
        // Stagger rows
        p.querySelectorAll('.ep-row').forEach((row, i) => {
          row.classList.remove('visible');
          setTimeout(() => row.classList.add('visible'), i * 55);
        });
      }
    });
  }

  tabs.forEach(tab => {
    tab.addEventListener('click', () => activateTab(tab.dataset.tab));
  });
  // Activate first tab
  if (tabs.length) activateTab(tabs[0].dataset.tab);


  /* ── 7. LIVE HEALTH CHECK ─────────────────────────────── */
  const STATUS_EL   = document.getElementById('health-status-text');
  const STATUS_DOT  = document.getElementById('health-big-dot');
  const STATUS_TAG  = document.getElementById('health-status-tag');
  const DB_BADGE    = document.getElementById('health-db-badge');
  const DATE_EL     = document.getElementById('health-date');
  const TIME_EL     = document.getElementById('health-time');
  const LAST_EL     = document.getElementById('health-last-checked');
  const NAV_DOT     = document.getElementById('nav-status-dot');
  const NAV_STATUS  = document.getElementById('nav-status-text');

  async function fetchHealth() {
    try {
      const res  = await fetch('/health', { cache: 'no-store' });
      const data = await res.json();

      const isHealthy  = data.status === 'healthy';
      const isDegraded = data.status === 'degraded';

      // Update big dot
      STATUS_DOT.className = 'health__big-dot' + (isDegraded ? ' degraded' : isHealthy ? '' : ' unhealthy');

      // Update text
      if (STATUS_EL)  STATUS_EL.textContent  = isHealthy ? 'All Systems Operational' : isDegraded ? 'Degraded Performance' : 'Service Disruption';
      if (STATUS_TAG) STATUS_TAG.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
      STATUS_TAG.style.background = isHealthy ? 'rgba(34,197,94,0.1)' : isDegraded ? 'rgba(245,158,11,0.1)' : 'rgba(239,68,68,0.1)';
      STATUS_TAG.style.color      = isHealthy ? '#16a34a' : isDegraded ? '#d97706' : '#dc2626';

      // DB badge
      const dbStatus = data.components?.database || 'unknown';
      if (DB_BADGE) {
        DB_BADGE.textContent = '● ' + dbStatus.charAt(0).toUpperCase() + dbStatus.slice(1);
        DB_BADGE.className = 'health__db-badge' + (dbStatus === 'healthy' ? '' : ' checking');
        DB_BADGE.style.background = dbStatus === 'healthy' ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)';
        DB_BADGE.style.color      = dbStatus === 'healthy' ? '#16a34a' : '#dc2626';
      }

      // Date / Time
      if (DATE_EL) DATE_EL.textContent = data.date || '—';
      if (TIME_EL) TIME_EL.textContent = data.time || '—';

      // Nav dot
      if (NAV_DOT)    NAV_DOT.style.background = isHealthy ? '#22c55e' : isDegraded ? '#f59e0b' : '#ef4444';
      if (NAV_STATUS) NAV_STATUS.textContent   = isHealthy ? 'API Online' : isDegraded ? 'Degraded' : 'Offline';

    } catch (err) {
      if (STATUS_EL)  STATUS_EL.textContent  = 'Connection Failed';
      if (STATUS_DOT) STATUS_DOT.className   = 'health__big-dot unhealthy';
      if (NAV_DOT)    NAV_DOT.style.background = '#ef4444';
      if (NAV_STATUS) NAV_STATUS.textContent = 'Offline';
    }

    // Update timestamp
    if (LAST_EL) LAST_EL.textContent = 'Last checked: ' + new Date().toLocaleTimeString();
  }

  // Manual refresh button
  document.getElementById('health-refresh-btn')?.addEventListener('click', () => {
    const icon = document.querySelector('#health-refresh-btn svg');
    if (icon) {
      icon.style.transition = 'transform 0.5s';
      icon.style.transform  = 'rotate(360deg)';
      setTimeout(() => { icon.style.transition = ''; icon.style.transform = ''; }, 600);
    }
    fetchHealth();
  });

  // Initial + polling
  fetchHealth();
  setInterval(fetchHealth, 30000);


  /* ── 8. SMOOTH SCROLL for anchor links ──────────────── */
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const target = document.querySelector(a.getAttribute('href'));
      if (target) {
        e.preventDefault();
        const offset = 68;
        window.scrollTo({ top: target.offsetTop - offset, behavior: 'smooth' });
      }
    });
  });

});
