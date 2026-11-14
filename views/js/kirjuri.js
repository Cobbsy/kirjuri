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

/* Forms marked data-warn-unsaved hold text worth keeping. Leaving the page while one of them has changes that were
 * not sent asks first; browsers word that question themselves. A change is typing or choosing in the form, or in a
 * text editor (TinyMCE) inside it. Sending the form is not leaving it, and clearing it with its reset button
 * leaves nothing to lose. The status and location menus save on their own and are forms of their own. */
(function () {
    function editorsIn(form) {
        if (!window.tinymce || typeof window.tinymce.get !== 'function') {
            return [];
        }
        return window.tinymce.get().filter(function (editor) {
            return form.contains(editor.getElement());
        });
    }

    function hasUnsavedChanges(form) {
        return form.hasAttribute('data-changed') || editorsIn(form).some(function (editor) {
            return editor.isDirty();
        });
    }

    function markChanged(event) {
        var form = event.target.form;
        if (form && form.hasAttribute('data-warn-unsaved')) {
            form.setAttribute('data-changed', '');
        }
    }

    document.addEventListener('input', markChanged);
    document.addEventListener('change', markChanged);

    document.addEventListener('reset', function (event) {
        var form = event.target;
        if (form.hasAttribute('data-warn-unsaved')) {
            form.removeAttribute('data-changed');
            editorsIn(form).forEach(function (editor) {
                editor.setDirty(false);
            });
        }
    });

    // On window, so that it runs after the data-validate check (on document), which may stop the sending.
    window.addEventListener('submit', function (event) {
        if (!event.defaultPrevented && event.target.hasAttribute('data-warn-unsaved')) {
            event.target.setAttribute('data-sending', '');
        }
    });

    window.addEventListener('beforeunload', function (event) {
        var forms = document.querySelectorAll('form[data-warn-unsaved]:not([data-sending])');
        for (var i = 0; i < forms.length; i++) {
            if (hasUnsavedChanges(forms[i])) {
                event.preventDefault();
                event.returnValue = ''; // Older browsers ask only when this is set.
                return '';
            }
        }
        return undefined;
    });
})();
