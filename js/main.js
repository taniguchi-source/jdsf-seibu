/* ===== ヒーローカルーセル ===== */
(function () {
  const slides = document.querySelectorAll('.carousel-slide');
  const dots   = document.querySelectorAll('.carousel-dot');
  if (!slides.length) return;

  let current   = 0;
  let timer     = null;
  const INTERVAL = 5000;

  function goTo(index) {
    slides[current].classList.remove('active');
    dots[current].classList.remove('active');
    current = (index + slides.length) % slides.length;
    slides[current].classList.add('active');
    dots[current].classList.add('active');
  }

  function startAuto() {
    clearInterval(timer);
    timer = setInterval(() => goTo(current + 1), INTERVAL);
  }

  /* 矢印 */
  const prev = document.querySelector('.carousel-prev');
  const next = document.querySelector('.carousel-next');
  if (prev) prev.addEventListener('click', () => { goTo(current - 1); startAuto(); });
  if (next) next.addEventListener('click', () => { goTo(current + 1); startAuto(); });

  /* ドット */
  dots.forEach(dot => {
    dot.addEventListener('click', () => {
      goTo(Number(dot.dataset.index));
      startAuto();
    });
  });

  /* スワイプ対応（スマホ） */
  let touchStartX = 0;
  const carousel  = document.querySelector('.hero-carousel');
  if (carousel) {
    carousel.addEventListener('touchstart', e => { touchStartX = e.touches[0].clientX; }, { passive: true });
    carousel.addEventListener('touchend',   e => {
      const diff = touchStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 40) { goTo(diff > 0 ? current + 1 : current - 1); startAuto(); }
    });
  }

  startAuto();
}());

/* ===== モバイルナビゲーション ===== */
(function () {
  const hamburger = document.querySelector('.hamburger');
  const nav       = document.querySelector('.site-nav');
  if (!hamburger || !nav) return;

  hamburger.addEventListener('click', () => {
    const isOpen = nav.classList.toggle('is-open');
    hamburger.classList.toggle('is-open', isOpen);
    hamburger.setAttribute('aria-expanded', String(isOpen));
  });

  /* クリック外で閉じる */
  document.addEventListener('click', (e) => {
    if (!hamburger.contains(e.target) && !nav.contains(e.target)) {
      nav.classList.remove('is-open');
      hamburger.classList.remove('is-open');
      hamburger.setAttribute('aria-expanded', 'false');
    }
  });

  /* リンククリックで閉じる */
  nav.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      nav.classList.remove('is-open');
      hamburger.classList.remove('is-open');
    });
  });
}());

/* ===== タブ切替 ===== */
(function () {
  document.querySelectorAll('.tabs').forEach(tabGroup => {
    const buttons = tabGroup.querySelectorAll('.tab-btn');
    const panels  = document.querySelectorAll('.tab-panel');

    buttons.forEach(btn => {
      btn.addEventListener('click', () => {
        buttons.forEach(b => b.classList.remove('active'));
        panels.forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        const target = document.getElementById(btn.dataset.target);
        if (target) target.classList.add('active');
      });
    });
  });
}());

/* ===== スクロールでヘッダーに影 ===== */
(function () {
  const header = document.querySelector('.site-header');
  if (!header) return;
  const onScroll = () => {
    header.style.boxShadow = window.scrollY > 10
      ? '0 2px 12px rgba(27,58,107,0.12)'
      : '0 1px 0 var(--border, #E2E8F0)';
  };
  window.addEventListener('scroll', onScroll, { passive: true });
}());

/* ===== ニュースティッカーの複製（シームレスループ） ===== */
(function () {
  const list = document.querySelector('.ticker-list');
  if (!list) return;
  list.innerHTML += list.innerHTML;
}());

/* ===== お問い合わせフォーム ===== */
(function () {
  const form = document.getElementById('contactForm');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type="submit"]');
    btn.disabled = true;
    btn.textContent = '送信中...';

    /* ここに実際の送信処理（Formspree / Netlify Forms 等）を追加する */
    setTimeout(() => {
      form.innerHTML = `
        <div style="text-align:center;padding:48px 24px;">
          <div style="font-size:3rem;margin-bottom:16px;">✅</div>
          <h3 style="font-size:1.25rem;font-weight:700;color:var(--text-dark);margin-bottom:8px;">
            お問い合わせを受け付けました
          </h3>
          <p style="color:var(--text-light);">
            内容を確認のうえ、担当者よりご連絡いたします。<br>
            しばらくお待ちください。
          </p>
        </div>`;
    }, 800);
  });
}());

/* ===== アクティブなnavリンクをハイライト ===== */
(function () {
  const current = location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-link').forEach(a => {
    const href = a.getAttribute('href') || '';
    if (href === current || (current === '' && href === 'index.html')) {
      a.classList.add('active');
    }
  });
}());
