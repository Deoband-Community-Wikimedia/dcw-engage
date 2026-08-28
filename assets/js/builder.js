const fieldsContainer = document.getElementById('fields_container');
const addFieldBtn = document.getElementById('add_field_btn');
const builderForm = document.getElementById('builderForm');
let fieldCounter = 0;

function createFieldCard(fieldData = null) {
    fieldCounter++;
    const card = document.createElement('div');
    card.className = 'field-card';
    card.id = `field_${fieldCounter}`;
    
    const label = fieldData ? fieldData.label : '';
    const type = fieldData ? fieldData.type : 'text';
    const requiredStr = fieldData && fieldData.required ? 'checked' : '';
    const optionsStr = fieldData && fieldData.options ? fieldData.options.join(', ') : '';
    
    card.innerHTML = `
        <div class="row">
            <div style="flex: 3;">
                <input type="text" class="field-title-input field-label" placeholder="Question Title (e.g. Full Name)" value="${label.replace(/"/g, '&quot;')}" required>
            </div>
            <div style="flex: 1;">
                <select class="field-type" onchange="toggleOptions(this, ${fieldCounter})">
                    <option value="text" ${type === 'text' ? 'selected' : ''}>Short Answer</option>
                    <option value="textarea" ${type === 'textarea' ? 'selected' : ''}>Paragraph</option>
                    <option value="email" ${type === 'email' ? 'selected' : ''}>Email Address</option>
                    <option value="select" ${type === 'select' ? 'selected' : ''}>Dropdown Menu</option>
                    <option value="checkbox" ${type === 'checkbox' ? 'selected' : ''}>Checkbox</option>
                    <option value="file" ${type === 'file' ? 'selected' : ''}>File Upload</option>
                </select>
            </div>
        </div>

        <div class="options-wrapper" id="options_wrapper_${fieldCounter}" style="display: ${type === 'select' ? 'block' : 'none'};">
            <label>Dropdown Options (comma separated)</label>
            <input type="text" class="field-options" placeholder="Option 1, Option 2, Option 3" value="${optionsStr.replace(/"/g, '&quot;')}">
        </div>

        <div class="row" style="margin-bottom:0; justify-content: space-between; border-top: 1px solid #f1f5f9; padding-top: 15px; margin-top: 15px;">
            <div class="toggle-wrapper">
                <input type="checkbox" class="field-required" id="req_${fieldCounter}" ${requiredStr}>
                <label for="req_${fieldCounter}" style="margin:0; cursor:pointer;">Required Question</label>
            </div>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn-outline btn-sm" title="Move up" onclick="moveField(${fieldCounter}, -1)">&uarr;</button>
                <button type="button" class="btn-outline btn-sm" title="Move down" onclick="moveField(${fieldCounter}, 1)">&darr;</button>
                <button type="button" class="btn-danger" onclick="removeField(${fieldCounter})">Trash</button>
            </div>
        </div>
    `;

    fieldsContainer.appendChild(card);
}

// Toggle options visibility if select is chosen
window.toggleOptions = function(selectElement, id) {
    const wrapper = document.getElementById(`options_wrapper_${id}`);
    wrapper.style.display = selectElement.value === 'select' ? 'block' : 'none';
};

// Remove a field block
window.removeField = function(id) {
    document.getElementById(`field_${id}`).remove();
};

// Move a field card up (-1) or down (+1). Field order on submit is read
// straight off the DOM (see the submit handler below), so swapping the
// cards' position in fields_container is the whole fix — no separate
// "order" value to track or persist.
window.moveField = function(id, direction) {
    const card = document.getElementById(`field_${id}`);
    if (!card) return;

    if (direction === -1) {
        const prev = card.previousElementSibling;
        if (prev) fieldsContainer.insertBefore(card, prev);
    } else {
        const next = card.nextElementSibling;
        if (next) fieldsContainer.insertBefore(next, card);
    }
};

// --- Description formatting toolbar (wikitext syntax, see #44) ---
// Operates directly on #form_description's selection — it's a plain
// <textarea>, not contenteditable, so there's no execCommand to lean on.
const descriptionToolbar = document.getElementById('description_toolbar');
if (descriptionToolbar) {
    const descriptionTextarea = document.getElementById('form_description');

    const getLineBounds = function(value, pos) {
        const start = value.lastIndexOf('\n', pos - 1) + 1;
        let end = value.indexOf('\n', pos);
        if (end === -1) end = value.length;
        return { start: start, end: end };
    };

    // Replaces [start, end) with `replacement`, then places the cursor
    // (or a selection, if selectLength is given) at start + selectOffset.
    const replaceRange = function(start, end, replacement, selectOffset, selectLength) {
        const value = descriptionTextarea.value;
        descriptionTextarea.value = value.slice(0, start) + replacement + value.slice(end);
        const cursor = start + selectOffset;
        descriptionTextarea.focus();
        descriptionTextarea.setSelectionRange(cursor, cursor + (selectLength || 0));
    };

    const wrapSelection = function(marker) {
        const start = descriptionTextarea.selectionStart;
        const end = descriptionTextarea.selectionEnd;
        const selected = descriptionTextarea.value.slice(start, end);
        replaceRange(start, end, marker + selected + marker, marker.length, selected.length);
    };

    // Re-clicking a heading button on an already-headinged line swaps the
    // level instead of nesting a second pair of markers onto it.
    const toggleHeadingLine = function(marker) {
        const pos = descriptionTextarea.selectionStart;
        const bounds = getLineBounds(descriptionTextarea.value, pos);
        const line = descriptionTextarea.value.slice(bounds.start, bounds.end);
        const bare = line.replace(/^={2,3}\s+/, '').replace(/\s+={2,3}$/, '');
        const replacement = marker + ' ' + bare + ' ' + marker;
        replaceRange(bounds.start, bounds.end, replacement, marker.length + 1, bare.length);
    };

    const increaseIndent = function() {
        const pos = descriptionTextarea.selectionStart;
        const bounds = getLineBounds(descriptionTextarea.value, pos);
        const line = descriptionTextarea.value.slice(bounds.start, bounds.end);
        const existing = line.match(/^(:+)/);
        const level = Math.min((existing ? existing[1].length : 0) + 1, 3); // cap: #44 asks for up to level 3
        const rest = line.replace(/^:+\s*/, '');
        const replacement = ':'.repeat(level) + ' ' + rest;
        replaceRange(bounds.start, bounds.end, replacement, replacement.length, 0);
    };

    const insertLink = function() {
        const start = descriptionTextarea.selectionStart;
        const end = descriptionTextarea.selectionEnd;
        const url = window.prompt('Link URL (http:// or https://):', 'https://');
        if (!url) return;
        const text = descriptionTextarea.value.slice(start, end) || 'link text';
        const replacement = '[' + url + ' ' + text + ']';
        replaceRange(start, end, replacement, replacement.length, 0);
    };

    descriptionToolbar.addEventListener('click', function(e) {
        const btn = e.target.closest('button');
        if (!btn) return;

        if (btn.dataset.wikiWrap) {
            wrapSelection(btn.dataset.wikiWrap);
        } else if (btn.dataset.wikiHeading) {
            toggleHeadingLine(btn.dataset.wikiHeading);
        } else if (btn.dataset.wikiIndent) {
            increaseIndent();
        } else if (btn.dataset.wikiLink) {
            insertLink();
        }
    });
}

let slugEdited = false;

if (typeof existingSchema !== 'undefined' && existingSchema) {
    document.getElementById('form_title').value = existingSchema.title || '';
    document.getElementById('form_description').value = existingSchema.description || '';
    document.getElementById('banner_image').value = existingSchema.banner_image || '';
    document.getElementById('form_type').value = typeof existingFormType !== 'undefined' ? existingFormType : '';
    slugEdited = true;
    
    if (existingSchema.fields && existingSchema.fields.length > 0) {
        existingSchema.fields.forEach(field => {
            createFieldCard(field);
        });
    } else {
        createFieldCard();
    }
} else {
    // Add initial field
    createFieldCard();
}

if (addFieldBtn) {
    addFieldBtn.addEventListener('click', () => createFieldCard());
}

// Auto-generate URL Slug from Title
const formTypeInput = document.getElementById('form_type');
if (formTypeInput) {
    formTypeInput.addEventListener('input', function() {
        slugEdited = true;
    });
}

const formTitleInput = document.getElementById('form_title');
if (formTitleInput) {
    formTitleInput.addEventListener('input', function(e) {
        if (!slugEdited) {
            const title = e.target.value;
            const slug = title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
            if (formTypeInput) formTypeInput.value = slug;
        }
    });
}

// Compile JSON on submit
if (builderForm) {
    builderForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const title = document.getElementById('form_title').value;
        const description = document.getElementById('form_description').value;
        const bannerImage = document.getElementById('banner_image').value;
        
        const schema = {
            title: title,
            description: description,
            banner_image: bannerImage,
            fields: []
        };

        const cards = document.querySelectorAll('.field-card');
        cards.forEach(card => {
            const label = card.querySelector('.field-label').value;
            const type = card.querySelector('.field-type').value;
            const isRequired = card.querySelector('.field-required').checked;
            
            // Auto generate an internal database name from the label
            const name = label.toLowerCase().replace(/[^a-z0-9]/g, '_').replace(/_+/g, '_').replace(/^_|_$/g, '');

            const fieldData = {
                name: name || 'field_' + Math.floor(Math.random() * 1000),
                label: label,
                type: type,
                required: isRequired
            };

            if (type === 'select') {
                const optionsRaw = card.querySelector('.field-options').value;
                fieldData.options = optionsRaw.split(',').map(opt => opt.trim()).filter(opt => opt.length > 0);
            }

            schema.fields.push(fieldData);
        });

        document.getElementById('schema_json_input').value = JSON.stringify(schema, null, 2);
        
        // Actually submit the form
        this.submit();
    });
}
