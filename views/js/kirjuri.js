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

/* Forms marked data-autosave (a device's status and location menus) save as soon as a menu changes, in the
 * background. The answer is shown beside the menus, and a status change updates the device's progress bars,
 * its status text, the case's progress and whether the case can be closed, without reloading the page. */
document.addEventListener('change', function (event) {
    var form = event.target.form;
    if (!form || !form.hasAttribute('data-autosave')) {
        return;
    }
    var box = form.closest('.device-autosave');
    var status = box ? box.querySelector('.autosave-status') : null;
    var select = event.target;
    var body = new FormData(form);
    if (status) {
        status.textContent = '';
        status.classList.remove('is-saved', 'is-failed');
    }
    fetch(form.action, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json(); // A refused request answers with a redirect to a page, which is not JSON.
        })
        .then(function (answer) {
            if (!answer || answer.saved !== true) {
                throw new Error('Not saved');
            }
            select.classList.remove('is-missing');
            if (answer.device && box) {
                kirjuriShowDeviceProgress(box.getAttribute('data-device'), answer.device);
            }
            if (answer.case) {
                kirjuriShowCaseProgress(answer.case);
            }
            if (status) {
                status.textContent = status.getAttribute('data-saved');
                status.classList.add('is-saved');
            }
        })
        .catch(function () {
            if (status) {
                status.textContent = status.getAttribute('data-failed');
                status.classList.add('is-failed');
            }
        });
});

var KIRJURI_BAR_COLOURS = ['progress-bar-success', 'progress-bar-danger', 'progress-bar-info', 'progress-bar-warning'];

function kirjuriSetBar(bar, percent, colour) {
    bar.style.width = percent + '%';
    bar.setAttribute('aria-valuenow', percent);
    bar.classList.remove.apply(bar.classList, KIRJURI_BAR_COLOURS);
    bar.classList.add('progress-bar-' + colour);
}

/* device: {step, label, percent, bar} from kirjuri_device_progress(). */
function kirjuriShowDeviceProgress(id, device) {
    document.querySelectorAll('[data-device-bar="' + id + '"]').forEach(function (bar) {
        kirjuriSetBar(bar, device.percent, device.bar);
        if (bar.parentNode.hasAttribute('hidden')) {
            bar.parentNode.removeAttribute('hidden');
        }
    });
    document.querySelectorAll('[data-device-status="' + id + '"]').forEach(function (text) {
        text.textContent = device.label;
    });
}

/* progress: {percent, bar, started, all_done} from kirjuri_case_progress(). */
function kirjuriShowCaseProgress(progress) {
    var box = document.querySelector('[data-case-progress]');
    if (box) {
        box.hidden = !progress.started;
        box.querySelector('[data-case-percent]').textContent = progress.percent + '%';
        kirjuriSetBar(box.querySelector('[data-case-bar]'), progress.percent, progress.bar);
    }
    var close = document.querySelector('[data-close-request]');
    if (close) {
        close.hidden = !progress.all_done;
    }
}
