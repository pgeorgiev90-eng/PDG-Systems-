(() => {
    const form = document.getElementById('contactForm');
    if (!form) return;
    document.querySelectorAll('[data-panel-enquiry]').forEach(link => link.addEventListener('click', () => {
        document.getElementById('panel-projects-dialog')?.close();
        const message = form.elements.namedItem('message');
        if (!message.value.trim()) message.value = 'Запитване за електрическо табло по поръчка\nНаселено място: \nВид обект: \nНеобходимо оборудване / задание: \nПредпочитан срок: \nТелефон за връзка (по желание): ';
        form.scrollIntoView({block: 'center'});
        form.elements.namedItem('name').focus({preventScroll: true});
    }));
    document.querySelectorAll('[data-about-enquiry]').forEach(link => link.addEventListener('click', () => {
        const message = form.elements.namedItem('message');
        if (!message.value.trim()) message.value = link.dataset.aboutEnquiry === 'diagnostics'
            ? 'Запитване за диагностика и техническа помощ\nНаселено място: \nИнвертор / батерия (марка и модел): \nОписание на проблема / код на грешка: \nТелефон за връзка (по желание): '
            : 'Запитване за изграждане на фотоволтаична система\nНаселено място: \nВид обект: \nЖелана мощност / оборудване: \nТелефон за връзка (по желание): ';
        form.scrollIntoView({block: 'center'});
        form.elements.namedItem('name').focus({preventScroll: true});
    }));
    const status = document.getElementById('contactStatus');
    const button = form.querySelector('button[type="submit"]');
    let ticket;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        button.disabled = true;
        status.hidden = false;
        status.textContent = 'Изпращане…';
        try {
            if (!ticket) {
                const session = await fetch(form.action, {cache: 'no-store'});
                if (!session.ok) throw new Error();
                ticket = await session.json();
            }
            const payload = Object.fromEntries(new FormData(form));
            payload.request_key = ticket.request_key;
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': ticket.csrf},
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok || !result.ok) {
                if (response.status === 403 || response.status === 409) ticket = null;
                if (response.status === 429) {
                    status.textContent = 'Изчакайте малко, преди да изпратите ново запитване.';
                    return;
                }
                throw new Error();
            }
            ticket = null;
            form.reset();
            if (response.status === 201) window.PDGTrack?.('enquiry');
            status.textContent = 'Запитването е получено. Номер: ' + result.reference;
        } catch {
            status.textContent = 'Не успяхме да потвърдим изпращането. Опитайте отново.';
        } finally {
            button.disabled = false;
        }
    });
})();
