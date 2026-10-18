if (document.body.classList.contains('authenticated-page')) {
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.documentElement.hidden = true;
            window.location.reload();
        }
    });
}
