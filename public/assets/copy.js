/*
 * Copy buttons: <button data-copy="element-id"> copies that element's text.
 *
 * The buttons are rendered hidden and only shown here, so without JavaScript
 * the page shows the text alone rather than a button that does nothing.
 */
(function () {
    'use strict';

    function selectText(element) {
        const range = document.createRange();
        range.selectNodeContents(element);

        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }

    async function copy(element) {
        const text = element.textContent;

        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);

            return;
        }

        // Older browsers and plain-http pages: copy the selection instead.
        selectText(element);

        if (!document.execCommand('copy')) {
            throw new Error('Copy failed');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('button[data-copy]').forEach(function (button) {
            const target = document.getElementById(button.dataset.copy);

            if (!target) {
                return;
            }

            const label = button.textContent;
            let timer = null;

            button.hidden = false;

            button.addEventListener('click', async function () {
                try {
                    await copy(target);
                    button.textContent = 'Copied!';
                } catch (error) {
                    // Leave the text selected so it can be copied by hand.
                    selectText(target);
                    button.textContent = 'Press ⌘C / Ctrl+C';
                }

                clearTimeout(timer);
                timer = setTimeout(function () {
                    button.textContent = label;
                }, 2000);
            });
        });
    });
})();
