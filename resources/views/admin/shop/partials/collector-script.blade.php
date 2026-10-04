{{--
    The behaviour behind collector-fields.

    Hand written, like every other dialog in this admin. No library: the whole job is
    showing a box, enabling a button and one fetch.

    Three things it does, and nothing it decides. Every rule here is enforced again on
    the server, because a disabled button is a courtesy and not a control.
--}}
<script>
    (function () {
        document.querySelectorAll('[data-collector]').forEach(function (block) {
            const form = block.closest('form');

            if (!form) {
                return;
            }

            // Read from the form's own @csrf field. There is no csrf-token meta tag
            // in this layout, and inventing one for this would put a token on every
            // page to serve one button.
            const token = form.querySelector('input[name="_token"]')?.value || '';

            const details = block.querySelector('[data-collector-details]');
            const code = block.querySelector('[data-collector-code]');
            const reason = block.querySelector('[data-collector-reason]');
            const status = block.querySelector('[data-collector-status]');
            const sendButton = block.querySelector('[data-collector-send]');
            const submit = form.querySelector('[data-collector-submit]');
            const choices = block.querySelectorAll('[data-collector-choice]');

            function chosen() {
                const picked = block.querySelector('[data-collector-choice]:checked');

                return picked ? picked.value : 'buyer';
            }

            function say(message, tone) {
                if (!status) {
                    return;
                }

                status.textContent = message;
                status.className = 'text-xs mt-2 ' + (tone === 'bad' ? 'text-red-600' : 'text-green-700');
            }

            /*
             | Whether Collected may be pressed.
             |
             | The buyer collecting their own order is always allowed: the identity
             | card is the check there and always has been. A third party needs
             | either six digits or a written reason for skipping them.
             */
            function refresh() {
                const other = chosen() === 'other';

                if (details) {
                    details.classList.toggle('hidden', !other);
                }

                if (!submit) {
                    return;
                }

                const hasCode = code && code.value.replace(/\D+/g, '').length === 6;
                const hasReason = reason && reason.value.trim() !== '';

                submit.disabled = other && !hasCode && !hasReason;
                submit.classList.toggle('opacity-50', submit.disabled);
                submit.classList.toggle('cursor-not-allowed', submit.disabled);
            }

            choices.forEach(function (choice) {
                choice.addEventListener('change', refresh);
            });

            code?.addEventListener('input', function () {
                // Digits only, so a pasted "code: 123456" does not go to the server
                // as something that cannot match.
                code.value = code.value.replace(/\D+/g, '').slice(0, 6);
                refresh();
            });

            reason?.addEventListener('input', refresh);

            /*
             | Send the code without leaving the page.
             |
             | A redirect would empty the three boxes the operator just filled in and
             | make them start again with a queue waiting. The response carries where
             | the message went and what the gateway said about it, and never the code
             | itself.
             */
            sendButton?.addEventListener('click', function () {
                const body = new FormData();

                body.append('_token', token);
                body.append('collector_name', block.querySelector('[data-collector-field="name"]')?.value || '');
                body.append('collector_ic', block.querySelector('[data-collector-field="ic"]')?.value || '');
                body.append('collector_phone', block.querySelector('[data-collector-field="phone"]')?.value || '');

                sendButton.disabled = true;
                status?.classList.remove('hidden');
                say('Sending...', 'good');

                fetch(block.dataset.codeUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: body,
                })
                    .then(function (response) {
                        return response.json().then(function (data) {
                            return { ok: response.ok, data: data };
                        });
                    })
                    .then(function (result) {
                        if (result.ok && result.data.ok) {
                            say(result.data.message, 'good');
                            code?.focus();

                            return;
                        }

                        // A 422 from validation carries errors rather than a message.
                        const errors = result.data.errors
                            ? Object.values(result.data.errors).flat().join(' ')
                            : null;

                        say(result.data.message || errors || 'The code could not be sent.', 'bad');
                    })
                    .catch(function () {
                        say('The code could not be sent. Use the override below if it will not go through.', 'bad');
                    })
                    .finally(function () {
                        sendButton.disabled = false;
                    });
            });

            refresh();
        });
    })();
</script>
