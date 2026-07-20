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
            <button type="button" class="btn-danger" onclick="removeField(${fieldCounter})">Trash</button>
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
