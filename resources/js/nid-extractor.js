const app = document.querySelector('[data-nid-extractor-app]');

if (app) {
    bootstrapNidExtractor(app);
}

function bootstrapNidExtractor(root) {
    const form = root.querySelector('[data-nid-form]');
    const statusEl = root.querySelector('[data-status]');
    const submitBtn = root.querySelector('[data-submit-btn]');
    const submitText = root.querySelector('[data-submit-text]');
    const resultSection = root.querySelector('[data-result-section]');
    const fieldsContainer = root.querySelector('[data-result-fields]');
    const warningList = root.querySelector('[data-warning-list]');
    const rawFront = root.querySelector('[data-raw-front]');
    const rawBack = root.querySelector('[data-raw-back]');

    const frontInput = form.querySelector('#front_image');
    const backInput = form.querySelector('#back_image');
    const frontPreview = root.querySelector('[data-preview-front]');
    const backPreview = root.querySelector('[data-preview-back]');

    let activeRequest = null;

    frontInput.addEventListener('change', () => renderImagePreview(frontInput, frontPreview));
    backInput.addEventListener('change', () => renderImagePreview(backInput, backPreview));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!frontInput.files?.length || !backInput.files?.length) {
            setStatus(statusEl, 'error', 'Both front and back images required.');
            return;
        }

        if (activeRequest) {
            activeRequest.abort();
        }

        activeRequest = new AbortController();
        setLoadingState(submitBtn, submitText, true);
        setStatus(statusEl, 'loading', 'Running OCR and parsing fields...');

        const formData = new FormData(form);

        try {
            const response = await fetch('/api/v1/nid/extract', {
                method: 'POST',
                body: formData,
                signal: activeRequest.signal,
            });

            const payload = await response.json();

            if (!response.ok) {
                throw new Error(payload.error || payload.message || 'Extraction failed.');
            }

            renderResult({
                fieldsContainer,
                warningList,
                rawFront,
                rawBack,
                resultSection,
                payload,
            });

            setStatus(statusEl, 'success', 'Extraction complete. Review parsed data below.');
        } catch (error) {
            if (error.name === 'AbortError') {
                setStatus(statusEl, 'warning', 'Previous extraction cancelled.');
            } else {
                setStatus(statusEl, 'error', error.message || 'Something went wrong.');
            }
        } finally {
            setLoadingState(submitBtn, submitText, false);
        }
    });
}

function renderImagePreview(input, previewEl) {
    const file = input.files?.[0];

    if (!file) {
        previewEl.removeAttribute('src');
        previewEl.classList.remove('is-visible');
        return;
    }

    const objectUrl = URL.createObjectURL(file);
    previewEl.src = objectUrl;
    previewEl.classList.add('is-visible');

    previewEl.onload = () => {
        URL.revokeObjectURL(objectUrl);
    };

    previewEl.onerror = () => {
        URL.revokeObjectURL(objectUrl);
        previewEl.removeAttribute('src');
        previewEl.classList.remove('is-visible');
    };
}

function setLoadingState(button, label, loading) {
    button.disabled = loading;
    button.classList.toggle('is-loading', loading);
    label.textContent = loading ? 'Processing...' : 'Extract NID Data';
}

function setStatus(statusEl, type, message) {
    statusEl.textContent = message;
    statusEl.dataset.state = type;
}

function renderResult({ fieldsContainer, warningList, rawFront, rawBack, resultSection, payload }) {
    const fields = [
        ['Name (BN)', payload?.data?.name?.bn],
        ['Name (EN)', payload?.data?.name?.en],
        ['Father Name (BN)', payload?.data?.father_name?.bn],
        ['Father Name (EN)', payload?.data?.father_name?.en],
        ['Mother Name (BN)', payload?.data?.mother_name?.bn],
        ['Mother Name (EN)', payload?.data?.mother_name?.en],
        ['Address (BN)', payload?.data?.address?.bn],
        ['Address (EN)', payload?.data?.address?.en],
        ['NID Number', payload?.data?.nid_number],
        ['Date of Birth', payload?.data?.date_of_birth],
        ['Blood Group', payload?.data?.blood_group],
        ['Issue Date', payload?.data?.issue_date],
    ];

    fieldsContainer.innerHTML = '';
    fields.forEach(([label, value]) => {
        const article = document.createElement('article');
        article.className = 'nid-field';

        const heading = document.createElement('h3');
        heading.textContent = label;

        const text = document.createElement('p');
        text.textContent = value || 'Not detected';

        article.append(heading, text);
        fieldsContainer.append(article);
    });

    warningList.innerHTML = '';
    const warnings = Array.isArray(payload?.warnings) ? payload.warnings : [];

    if (!warnings.length) {
        const li = document.createElement('li');
        li.textContent = 'No warning.';
        warningList.append(li);
    } else {
        warnings.forEach((warning) => {
            const li = document.createElement('li');
            li.textContent = warning;
            warningList.append(li);
        });
    }

    rawFront.textContent = payload?.raw_text?.front || 'No raw front text.';
    rawBack.textContent = payload?.raw_text?.back || 'No raw back text.';

    resultSection.hidden = false;
}
