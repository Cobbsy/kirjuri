/* Links that change data carry data-post. Clicking one sends a POST request to the link's URL
 * with the session's CSRF token (from <meta name="csrf-token">) and, when the link has
 * data-ct, the case access token, so that no token ever appears in a URL. An onclick
 * confirm() that returns false still cancels the action. */
document.addEventListener('click', function (event) {
    var link = event.target.closest('a[data-post]');
    if (!link || event.defaultPrevented) {
        return;
    }
    event.preventDefault();
    var form = document.createElement('form');
    form.method = 'post';
    form.action = link.getAttribute('href');
    form.style.display = 'none';
    var fields = { token: document.querySelector('meta[name="csrf-token"]').getAttribute('content') };
    if (link.hasAttribute('data-ct')) {
        fields.ct = link.getAttribute('data-ct');
    }
    Object.keys(fields).forEach(function (name) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = fields[name];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
});
