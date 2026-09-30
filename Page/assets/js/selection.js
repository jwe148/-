(() => {
    const form = document.getElementById('selection-form');
    if (!form) return;

    const primary = Array.from(form.querySelectorAll('select[name="primary[]"]'));
    const backup = Array.from(form.querySelectorAll('select[name="backup[]"]'));
    const all = [...primary, ...backup];
    const primaryCount = document.getElementById('primary-count');
    const backupCount = document.getElementById('backup-count');
    const duplicateMessage = document.getElementById('selection-duplicate');

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
        duplicateMessage.hidden = !hasDuplicate;
        duplicateMessage.textContent = hasDuplicate ? '同一门课程重复选择，请调整后再保存。' : '';
    }

    form.addEventListener('change', updateSelection);
    updateSelection();
})();
