// Daniel's Solar Engineering and Design — shared interactions

document.addEventListener('DOMContentLoaded', () => {
  /* mobile nav open/close is pure CSS (checkbox + label) — works even if
     this script fails to load, is cached stale, or loads out of order.
     This script only adds two enhancements on top of that: */

  const navToggle = document.getElementById('nav-toggle');
  const servicesToggle = document.getElementById('services-toggle');
  const nav = document.querySelector('.main-nav');

  /* 1) lock background scroll while the mobile menu is open */
  let scrollLockY = 0;
  if (navToggle) {
    navToggle.addEventListener('change', () => {
      if (navToggle.checked) {
        scrollLockY = window.scrollY || window.pageYOffset || 0;
        document.documentElement.style.setProperty('--scroll-lock-y', scrollLockY + 'px');
        document.documentElement.classList.add('nav-open');
      } else {
        document.documentElement.classList.remove('nav-open');
        window.scrollTo(0, scrollLockY);
      }
    });
  }

  /* 2) close the mobile menu (and collapse the Services accordion) when a
        nav link is tapped */
  if (nav) {
    nav.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        nav.classList.remove('open');
        if (navToggle) navToggle.checked = false;
        if (servicesToggle) servicesToggle.checked = false;
        document.documentElement.classList.remove('nav-open');
        window.scrollTo(0, scrollLockY);
      });
    });
  }

  /* scroll reveal */
  const revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15 });
    revealEls.forEach(el => io.observe(el));
  } else {
    revealEls.forEach(el => el.classList.add('in'));
  }

  /* quote-form submit is handled inline in contact.html */

  /* animated stat counters */
  const counters = document.querySelectorAll('[data-count]');
  if (counters.length && 'IntersectionObserver' in window) {
    const countIO = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const el = entry.target;
          const target = parseFloat(el.dataset.count);
          const suffix = el.dataset.suffix || '';
          const decimals = el.dataset.decimals ? parseInt(el.dataset.decimals) : 0;
          const duration = 1200;
          const start = performance.now();
          function tick(now) {
            const p = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - p, 3);
            const val = target * eased;
            el.textContent = val.toFixed(decimals) + suffix;
            if (p < 1) requestAnimationFrame(tick);
          }
          requestAnimationFrame(tick);
          countIO.unobserve(el);
        }
      });
    }, { threshold: 0.5 });
    counters.forEach(el => countIO.observe(el));
  }
});