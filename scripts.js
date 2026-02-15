document.addEventListener('DOMContentLoaded', function () {
  var menu = document.getElementById('menu');
  var header = document.querySelector('header');

  if (menu && header) {

    function toggleMenu() {
      var expanded = menu.classList.toggle('expanded');
      menu.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    menu.addEventListener('click', toggleMenu);
    menu.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleMenu();
      }
    });
  }


  // Re-center an SVG's viewBox to a square that exactly contains a target group
  function centerSquareViewBox(svg, target, { padding = 0 } = {}) {
    if (typeof svg === 'string') svg = document.getElementById(svg);
    if (typeof target === 'string') target = document.getElementById(target);
    if (!svg || !target) return;

    const bb = target.getBBox();
    // Add a little padding so strokes/text halos don't crop at high zooms
    const side = Math.max(bb.width, bb.height) + padding * 2;
    const cx = bb.x + bb.width / 2;
    const cy = bb.y + bb.height / 2;
    svg.setAttribute('viewBox', `${cx - side / 2} ${cy - side / 2} ${side} ${side}`);
  }

  // Auto-recenter on load, font load, resize, and any geometry-affecting mutation
  function autoCenterSquareViewBox(svgId, groupId, opts = {}) {
    const svg = document.getElementById(svgId);
    const target = document.getElementById(groupId);
    if (!svg || !target) return;

    let raf = 0;
    const recenter = () => {
      if (raf) return;
      raf = requestAnimationFrame(() => {
        raf = 0;
        centerSquareViewBox(svg, target, opts);
      });
    };

    // Initial passes (covers dev hot reload, bfcache restore)
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', recenter, { once: true });
    } else {
      recenter();
    }
    window.addEventListener('load', recenter, { once: true });
    window.addEventListener('pageshow', recenter);
    window.addEventListener('resize', recenter);

    // Wait for webfonts so text bbox is correct
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(recenter).catch(() => { });
    }

    // Recenter when geometry-affecting stuff changes inside the measured group
    const obs = new MutationObserver(recenter);
    obs.observe(target, {
      subtree: true,
      childList: true,
      characterData: true,
      attributes: true,
      attributeFilter: [
        'transform', 'd', 'x', 'y', 'dx', 'dy',
        'width', 'height', 'r', 'rx', 'ry',
        'font-size', 'font-family', 'letter-spacing', 'word-spacing',
        'stroke-width', 'class', 'style'
      ]
    });

    // Also run after CSS/SMIL animations on the group
    target.addEventListener('transitionend', recenter, true);
    target.addEventListener('animationend', recenter, true);

    // Return a cleanup if needed
    return () => {
      obs.disconnect();
      window.removeEventListener('resize', recenter);
      window.removeEventListener('pageshow', recenter);
    };
  }

  const slot = document.getElementById('logoSlot');
  if (slot) {
    const svgId = 'carousel-logo';
    const groupId = 'carousel-art';
    const cssPadding =
      parseFloat(getComputedStyle(slot).getPropertyValue('--padding')) || 0;
    autoCenterSquareViewBox(svgId, groupId, { padding: cssPadding });
  }


  const banner = document.getElementById('banner');
  if (banner) {
    const banner_svgId = 'banner-svg';
    const banner_groupId = 'banner-art';
    const cssBannerPadding =
      parseFloat(getComputedStyle(banner).getPropertyValue('--padding')) || 0;
    autoCenterSquareViewBox(banner_svgId, banner_groupId, { padding: cssBannerPadding });
  }


});