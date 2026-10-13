(() => {
    const form = document.getElementById('selection-form');
    if (!form) return;

    const primary = Array.from(form.querySelectorAll('select[name="primary[]"]'));
    const backup = Array.from(form.querySelectorAll('select[name="backup[]"]'));
    const all = [...primary, ...backup];
    const primaryCount = document.getElementById('primary-count');
    const backupCount = document.getElementById('backup-count');
    const duplicateMessage = document.getElementById('selection-duplicate');
    const availabilityMessage = document.getElementById('selection-availability-warning');
    let availability = null;

    function showMessage(element, text) {
        if (!element) return;
        if (element.textContent !== text) element.textContent = text;
        element.hidden = text === '';
    }

    function updateSelection() {
        primaryCount.textContent = `首选 ${primary.filter(select => select.value).length}/4`;
        backupCount.textContent = `备选 ${backup.filter(select => select.value).length}/2`;
        const byCourse = new Map();
        for (const select of all) {
            select.setCustomValidity('');
            select.removeAttribute('aria-invalid');
            if (!select.value) continue;
            const code = select.selectedOptions[0]?.dataset.courseCode;
            if (!code) continue;
            if (!byCourse.has(code)) byCourse.set(code, []);
            byCourse.get(code).push(select);
        }
        let hasDuplicate = false;
        for (const fields of byCourse.values()) {
            if (fields.length < 2) continue;
            hasDuplicate = true;
            for (const select of fields) {
                select.setCustomValidity('同一门课程只能选择一个教学班。');
                select.setAttribute('aria-invalid', 'true');
            }
        }
        showMessage(duplicateMessage, hasDuplicate ? '同一门课程重复选择，请调整后再保存。' : '');
        if (!availability) return;
        if (!availability.open) {
            showMessage(availabilityMessage, '选课已结束，当前填写内容已保留。');
            return;
        }
        const unavailable = [];
        for (const select of primary) {
            if (!select.value) continue;
            const course = availability.offerings[select.value];
            if (!course || course.status !== 'open' || (course.remaining === 0 && !course.owned)) {
                const name = select.selectedOptions[0]?.dataset.courseName || select.value;
                const reason = !course || course.status !== 'open' ? '当前不可选' : '已满';
                unavailable.push(`${name}${reason}`);
                // 已有重复课程提示时保留它，名额提示仍在下方显示。
                if (byCourse.get(select.selectedOptions[0]?.dataset.courseCode)?.length > 1) continue;
                select.setCustomValidity(`${name}${reason}，请调整首选或保存草稿。`);
                select.setAttribute('aria-invalid', 'true');
            }
        }
        showMessage(availabilityMessage, unavailable.length
            ? `${unavailable.join('；')}。请调整首选，满额班仍可作为备选或保存草稿。` : '');
    }

    form.addEventListener('change', updateSelection);
    updateSelection();
    const url = form.dataset?.availabilityUrl;
    const status = document.getElementById('selection-refresh-status');
    const refreshButton = document.getElementById('selection-refresh');
    if (!url || !status || !availabilityMessage || typeof fetch !== 'function') return;
    const submitButtons = Array.from(form.querySelectorAll('button[type="submit"]'));
    let busy = false;
    let timer;
    let sessionEnded = false;

    function applyAvailability(data) {
        if (!data || typeof data.open !== 'boolean' || !data.offerings
            || typeof data.offerings !== 'object' || Array.isArray(data.offerings)) throw new Error('Invalid availability');
        for (const course of Object.values(data.offerings)) {
            if (!course || !Number.isInteger(course.remaining) || course.remaining < 0 || course.remaining > 10
                || typeof course.owned !== 'boolean' || !['open', 'confirmed', 'cancelled'].includes(course.status)) {
                throw new Error('Invalid offering');
            }
        }
        // 校验完整响应后再更新，保持用户所选值和首选、备选顺序。
        availability = data;
        for (const select of all) {
            select.disabled = !data.open;
            for (const option of select.options) {
                if (!option.value) continue;
                const course = data.offerings[option.value];
                const label = option.dataset.availabilityLabel;
                const suffix = !course || course.status !== 'open' ? '（当前不可选）'
                    : course.owned ? `（已选，剩余${course.remaining}人）`
                    : course.remaining === 0 ? '（已满）' : `（剩余${course.remaining}人）`;
                option.textContent = label + suffix;
                option.disabled = (!course || course.status !== 'open'
                    || (primary.includes(select) && course.remaining === 0 && !course.owned))
                    && option.value !== select.value;
            }
        }
        for (const button of submitButtons) button.disabled = !data.open;
        updateSelection();
    }

    async function refreshAvailability() {
        if (busy || sessionEnded) return;
        clearTimeout(timer);
        if (document.hidden) return;
        busy = true;
        if (refreshButton) refreshButton.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
            if (response.status === 401 || response.status === 403) {
                sessionEnded = true;
                showMessage(status, '登录已失效，请重新登录。');
                for (const button of submitButtons) button.disabled = true;
                return;
            }
            if (!response.ok) throw new Error('Availability unavailable');
            applyAvailability(await response.json());
            showMessage(status, '名额已更新');
        } catch (error) {
            showMessage(status, '名额刷新失败，请重试。');
        } finally {
            clearTimeout(timeout);
            busy = false;
            if (refreshButton) refreshButton.disabled = sessionEnded;
            if (!sessionEnded) timer = setTimeout(refreshAvailability, 15000);
        }
    }

    refreshButton?.addEventListener('click', refreshAvailability);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshAvailability(); });
    window.addEventListener('focus', refreshAvailability);
    refreshAvailability();
})();
