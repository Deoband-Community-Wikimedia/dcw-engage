// Conditional sections (#241): a section marked data-show-if-field / data-show-if-values is shown only
// while that dropdown question has one of the listed answers. Inputs inside a hidden section are
// disabled so a required question in it cannot block the browser's own submit check ("An invalid form
// control is not focusable"), and so nothing in it is posted. The server applies the same rule again
// (FormModel::stripHiddenAnswers / visibleFields), so this script only makes the page match it.
(function () {
    function setHidden(section, hide) {
        section.hidden = hide;
        section.querySelectorAll('input, select, textarea, button').forEach(function (el) {
            if (hide) {
                if (!el.disabled) { el.disabled = true; el.setAttribute('data-cond-disabled', '1'); }
            } else if (el.hasAttribute('data-cond-disabled')) {
                el.disabled = false;
                el.removeAttribute('data-cond-disabled');
            }
        });
    }

    document.querySelectorAll('.cond-section').forEach(function (section) {
        var form = section.closest('form');
        var field = section.getAttribute('data-show-if-field');
        var values;
        try { values = JSON.parse(section.getAttribute('data-show-if-values') || '[]'); } catch (e) { values = []; }
        if (!form || !field) return;

        // The controlling question lives in an always-shown section, so it is never disabled by us.
        var control = form.elements[field];
        if (!control || !control.addEventListener) return;

        function update() {
            setHidden(section, values.indexOf((control.value || '').trim()) === -1);
        }
        control.addEventListener('change', update);
        control.addEventListener('input', update);
        update();
    });
})();
