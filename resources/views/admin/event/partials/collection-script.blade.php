{{--
    The behaviour behind the collection dialogs.

    Hand written, like every other dialog in this admin. No library: the whole job is
    showing a box, ticking some checkboxes, enabling a button and one fetch.

    Its own script rather than the shop's, because this screen has one rule the shop
    cannot have — an entry that still owes money — and two scripts both deciding
    whether the same submit button is enabled would fight over it. The markup contract
    is the shop's, so the two dialogs stay recognisable as the same act.

    Nothing here decides anything. Every rule is enforced again on the server, because
    a disabled button is a courtesy and not a control.
--}}
<script>
    (function () {
        function closeAll() {
            document.querySelectorAll('[role="dialog"]').forEach(function (dialog) {
                dialog.classList.add('hidden');
            });
            document.body.classList.remove('overflow-hidden');
        }

        /* -----------------------------------------------------------------
         | Opening, and which rows arrive ticked
         |
         | The row button ticks one person, the entry button ticks all of them.
         | Two triggers onto one dialog, because both are real: a family of six
         | turning up together is one press, and one of them turning up alone
         | must not need the other five unticked first.
         * -------------------------------------------------------------- */
        document.querySelectorAll('[data-open-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                const dialog = document.getElementById(trigger.dataset.openDialog);

                if (!dialog) {
                    return;
                }

                closeAll();

                const only = trigger.dataset.collectionOnly || null;

                dialog.querySelectorAll('[data-collection-row]').forEach(function (box) {
                    box.checked = only === null || box.dataset.collectionRow === only;
                });

                dialog.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');

                dialog.dispatchEvent(new CustomEvent('collection:refresh', { bubbles: true }));
            });
        });

        document.querySelectorAll('[data-close-dialog]').forEach(function (trigger) {
            trigger.addEventListener('click', closeAll);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        /* -----------------------------------------------------------------
         | One form at a time
         * -------------------------------------------------------------- */
        document.querySelectorAll('[data-collection-form]').forEach(function (form) {
            const block = form.querySelector('[data-collector]');

            if (!block) {
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
            const submit = form.querySelector('[data-collection-submit]');
            const paymentReason = form.querySelector('[data-collection-payment-reason]');
            const owes = form.dataset.owes === '1';
            const boxes = form.querySelectorAll('[data-collection-row]');

            function chosen() {
                const picked = block.querySelector('[data-collector-choice]:checked');

                return picked ? picked.value : 'buyer';
            }

            function ticked() {
                return Array.prototype.filter.call(boxes, function (box) {
                    return box.checked;
                }).length;
            }

            function say(message, tone) {
                if (!status) {
                    return;
                }

                status.textContent = message;
                status.className = 'text-xs mt-2 ' + (tone === 'bad' ? 'text-red-600' : 'text-green-700');
            }

            /*
             | Whether Handed Over may be pressed.
             |
             | Four things, and each of them is a server refusal as well. Something has
             | to be ticked. A representative needs six digits or a written reason. An
             | entry that still owes money needs a reason of its own, which is a
             | separate question from who is standing there and so a separate box.
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
                const hasPaymentReason = paymentReason && paymentReason.value.trim() !== '';

                submit.disabled = ticked() === 0
                    || (other && !hasCode && !hasReason)
                    || (owes && !hasPaymentReason);

                submit.classList.toggle('opacity-50', submit.disabled);
                submit.classList.toggle('cursor-not-allowed', submit.disabled);
            }

            block.querySelectorAll('[data-collector-choice]').forEach(function (choice) {
                choice.addEventListener('change', refresh);
            });

            boxes.forEach(function (box) {
                box.addEventListener('change', refresh);
            });

            code?.addEventListener('input', function () {
                // Digits only, so a pasted "code: 123456" does not go to the server
                // as something that cannot match.
                code.value = code.value.replace(/\D+/g, '').slice(0, 6);
                refresh();
            });

            reason?.addEventListener('input', refresh);
            paymentReason?.addEventListener('input', refresh);
            form.closest('[role="dialog"]')?.addEventListener('collection:refresh', refresh);

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
