(function ($) {
  const $grid = $('#servicesCardsGrid');
  if (!$grid.length) return;

  const expandMs = 260;   // szélesség anim
  const slideMs  = 220;   // body slide
  const flipMs   = 380;   // reflow simítás
  const flipEase = 'cubic-bezier(.2,.8,.2,1)';

  function getGapPx(gridEl) {
    const cs = getComputedStyle(gridEl);
    const v = parseFloat(cs.columnGap) || parseFloat(cs.gap) || 0;
    return isNaN(v) ? 0 : v;
  }

  function getColsCount(gridEl) {
    const cs = getComputedStyle(gridEl);
    const cols = (cs.gridTemplateColumns || '').trim();
    if (!cols) return 1;

    // computed style általában pl. "312px 312px 312px"
    const parts = cols.split(' ').filter(Boolean);
    return Math.max(1, parts.length);
  }

  function setAria($card, expanded) {
    $card.attr('aria-expanded', expanded ? 'true' : 'false');
  }

  function cancelAnimations(el) {
    if (el.getAnimations) {
      el.getAnimations().forEach(a => a.cancel());
    }
  }

  // FLIP: a grid reflow "ugrását" csúsztatja
  function flipReflow($items, mutate) {
    const first = new Map();
    $items.each((_, el) => first.set(el, el.getBoundingClientRect()));

    mutate();

    const last = new Map();
    $items.each((_, el) => last.set(el, el.getBoundingClientRect()));

    $items.each((_, el) => {
      const f = first.get(el);
      const l = last.get(el);
      if (!f || !l) return;

      const dx = f.left - l.left;
      const dy = f.top - l.top;

      if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return;

      cancelAnimations(el);
      if (!el.animate) return;

      el.animate(
        [{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0,0)' }],
        { duration: flipMs, easing: flipEase }
      );
    });
  }

  function openCard($card) {
    if ($card.hasClass('is-open') || $card.hasClass('is-busy')) return;

    $card.addClass('is-busy');
    setAria($card, true);

    const $items = $grid.find('.service-card');
    const startW = $card.outerWidth();

    // reflow: full width sor -> a többi kártya FLIP-pel csúszik
    flipReflow($items, () => {
      $card.addClass('is-open is-expanding').css({ width: startW + 'px' });
    });

    // szélesség anim a teljes grid szélességére, majd body slideDown
    requestAnimationFrame(() => {
      const targetW = $grid.width();
      $card.stop(true, true).animate({ width: targetW }, expandMs, 'swing', function () {
        $card.css({ width: '' }).removeClass('is-expanding');

        $card.find('.service-card__body').stop(true, true).slideDown(slideMs, function () {
          $card.removeClass('is-busy');
        });
      });
    });
  }

  function closeCard($card) {
    if (!$card.hasClass('is-open') || $card.hasClass('is-busy')) return;

    $card.addClass('is-busy');
    setAria($card, false);

    // 1) előbb body felcsuk
    $card.find('.service-card__body').stop(true, true).slideUp(slideMs, function () {
      // 2) szélesség vissza a "normál" kártyaszélességre, miközben még full sorban van
      const gridEl = $grid[0];
      const cols = getColsCount(gridEl);
      const gap = getGapPx(gridEl);

      const gridW = $grid.width();
      const targetW = cols <= 1 ? gridW : (gridW - gap * (cols - 1)) / cols;

      const fullW = $card.outerWidth();
      $card.css({ width: fullW + 'px' }).addClass('is-expanding');

      $card.stop(true, true).animate({ width: targetW }, expandMs, 'swing', function () {
        const $items = $grid.find('.service-card');

        // 3) most vált vissza grid oszlopba -> FLIP simítja az összes elem mozgását (beleértve ezt is)
        flipReflow($items, () => {
          $card.removeClass('is-open is-expanding').css({ width: '' });
        });

        $card.removeClass('is-busy');
      });
    });
  }

  function toggleCard($card) {
    if ($card.hasClass('is-open')) closeCard($card);
    else openCard($card);
  }

  // Click: ha linkre kattintanak belül, ne toggle-öljön
  $(document).on('click', '#servicesCardsGrid .service-card', function (e) {
    if ($(e.target).closest('a, button, input, textarea, select, label').length) return;
    toggleCard($(this));
  });

  // Enter / Space
  $(document).on('keydown', '#servicesCardsGrid .service-card', function (e) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      toggleCard($(this));
    }
  });

  // Resize: nyitott kártyáknál biztosítsuk, hogy ne maradjon inline width
  $(window).on('resize', function () {
    $('#servicesCardsGrid .service-card.is-open').css({ width: '' });
  });

})(jQuery);
