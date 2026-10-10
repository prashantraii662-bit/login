(() => {
    const toggle = document.querySelector('[data-nav-toggle]');
    const closers = document.querySelectorAll('[data-nav-close]');

    if (!toggle) {
        return;
    }

    const mobileNavigation = window.matchMedia('(max-width: 900px)');
    const collapsedStorageKey = 'campusvote-navigation-collapsed';
    if (sessionStorage.getItem(collapsedStorageKey) === 'true') {
        document.body.classList.add('nav-collapsed');
    }
    const setMobileOpen = (isOpen) => {
        document.body.classList.toggle('nav-open', isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
    };
    const updateToggle = () => {
        if (mobileNavigation.matches) {
            toggle.setAttribute('aria-expanded', String(document.body.classList.contains('nav-open')));
            toggle.setAttribute('aria-label', document.body.classList.contains('nav-open')
                ? 'Close navigation menu'
                : 'Open navigation menu');
            return;
        }
        document.body.classList.remove('nav-open');
        const isCollapsed = document.body.classList.contains('nav-collapsed');
        toggle.setAttribute('aria-expanded', String(!isCollapsed));
        toggle.setAttribute('aria-label', isCollapsed ? 'Expand navigation sidebar' : 'Collapse navigation sidebar');
    };

    toggle.addEventListener('click', () => {
        if (mobileNavigation.matches) {
            setMobileOpen(toggle.getAttribute('aria-expanded') !== 'true');
        } else {
            const isCollapsed = document.body.classList.toggle('nav-collapsed');
            sessionStorage.setItem(collapsedStorageKey, String(isCollapsed));
            updateToggle();
        }
    });
    closers.forEach((closer) => closer.addEventListener('click', () => setMobileOpen(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileNavigation.matches) {
            setMobileOpen(false);
        }
    });
    document.querySelectorAll('.app-nav-link').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileNavigation.matches) {
                setMobileOpen(false);
            }
        });
    });
    const addStudentButton = document.querySelector('[data-student-add-open]');
    const addStudentDetails = document.querySelector('#student-add-details');
    if (addStudentButton && addStudentDetails) {
        addStudentButton.addEventListener('click', () => {
            addStudentDetails.open = true;
            addStudentDetails.scrollIntoView({behavior: 'smooth', block: 'center'});
            addStudentDetails.querySelector('summary').focus();
        });
    }
    mobileNavigation.addEventListener('change', updateToggle);
    updateToggle();
})();
