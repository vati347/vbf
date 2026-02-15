(() => {
  const OPEN_CLASS = 'is-open';
  const BODY_LOCK_CLASS = 'modal-open';

  /** @type {HTMLElement|null} */
  let activeOverlay = null;
  /** @type {HTMLElement|null} */
  let activeDialog = null;
  /** @type {HTMLElement|null} */
  let lastFocused = null;

  function getFocusable(container) {
    const selectors = [
      'a[href]',
      'button:not([disabled])',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])'
    ];
    return Array.from(container.querySelectorAll(selectors.join(',')))
      .filter(el => !el.hasAttribute('disabled') && !el.getAttribute('aria-hidden'));
  }

  function openModal(overlay) {
    if (!overlay) return;

    const dialog = overlay.querySelector('.services-modal-dialog');
    if (!dialog) return;

    lastFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null;

    // Megjelenítés + anim indítás
    overlay.hidden = false;
    requestAnimationFrame(() => overlay.classList.add(OPEN_CLASS));

    document.body.classList.add(BODY_LOCK_CLASS);

    activeOverlay = overlay;
    activeDialog = dialog;

    // Fókusz a dialogra / első fókuszolhatóra
    const focusables = getFocusable(dialog);
    const target = focusables[0] || dialog;
    setTimeout(() => target.focus(), 0);
  }

  function closeModal() {
    if (!activeOverlay) return;

    const overlay = activeOverlay;
    overlay.classList.remove(OPEN_CLASS);

    document.body.classList.remove(BODY_LOCK_CLASS);

    // anim után elrejt
    const delay = 230;
    setTimeout(() => {
      overlay.hidden = true;
    }, delay);

    // fókusz vissza
    if (lastFocused && typeof lastFocused.focus === 'function') {
      setTimeout(() => lastFocused.focus(), 0);
    }

    activeOverlay = null;
    activeDialog = null;
    lastFocused = null;
  }

  // Nyitás gombok
  document.addEventListener('click', (e) => {
    const openBtn = e.target.closest('[data-open-modal]');
    if (openBtn) {
      const id = openBtn.getAttribute('data-open-modal');
      const overlay = id ? document.getElementById(id) : null;
      openModal(overlay);
      return;
    }

    // Zárás gombok
    const closeBtn = e.target.closest('[data-close-modal]');
    if (closeBtn) {
      closeModal();
      return;
    }

    // Overlay click (csak ha a háttérre katt)
    if (activeOverlay && e.target === activeOverlay) {
      closeModal();
      return;
    }
  });

  // ESC + Tab fókuszcsapda
  document.addEventListener('keydown', (e) => {
    if (!activeOverlay || !activeDialog) return;

    if (e.key === 'Escape') {
      e.preventDefault();
      closeModal();
      return;
    }

    if (e.key === 'Tab') {
      const focusables = getFocusable(activeDialog);
      if (focusables.length === 0) {
        e.preventDefault();
        activeDialog.focus();
        return;
      }

      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const current = document.activeElement;

      if (e.shiftKey) {
        if (current === first || current === activeDialog) {
          e.preventDefault();
          last.focus();
        }
      } else {
        if (current === last) {
          e.preventDefault();
          first.focus();
        }
      }
    }
  });
})();
