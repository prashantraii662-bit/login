(() => {
    const toggle = document.querySelector('[data-nav-toggle]');
    const closers = document.querySelectorAll('[data-nav-close]');

    if (!toggle) {
        return;
    }

    const setOpen = (isOpen) => {
        document.body.classList.toggle('nav-open', isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    };

    toggle.addEventListener('click', () => {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });
    closers.forEach((closer) => closer.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });
    document.querySelectorAll('.app-nav-link').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });
})();
