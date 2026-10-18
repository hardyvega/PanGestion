const loginForm = document.querySelector('[data-login-form]');

if (loginForm) {
    const username = loginForm.querySelector('[name="nombre_usuario"]');
    const password = loginForm.querySelector('[name="password"]');
    const toggle = loginForm.querySelector('[data-password-toggle]');
    const toggleLabel = toggle?.querySelector('[data-toggle-label]');

    const clearCredentials = () => {
        for (const input of [username, password]) {
            if (input) {
                input.value = '';
                input.defaultValue = '';
            }
        }

        if (password) {
            password.type = 'password';
        }
        if (toggle) {
            toggle.setAttribute('aria-pressed', 'false');
            toggle.setAttribute('aria-label', 'Mostrar contrase\u00f1a');
        }
        if (toggleLabel) {
            toggleLabel.textContent = 'Mostrar';
        }
    };

    // Clear only on departure or restoration, never during normal form input.
    window.addEventListener('pagehide', clearCredentials);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            clearCredentials();
        }
    });
}
