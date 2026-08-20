const blueprintApp = {
    rules: [],

    init() {
        this.addRule(); // Add initial empty rule
    },

    addRule() {
        const id = Date.now();
        this.rules.push({ id, topic: '', difficulty: 'medium', question_type: 'MCQ', source: '', count: 1, marks: 1 });
        this.renderRules();
    },

    removeRule(id) {
        this.rules = this.rules.filter(r => r.id !== id);
        this.renderRules();
    },

    updateRule(id, field, value) {
        const rule = this.rules.find(r => r.id === id);
        if (rule) {
            rule[field] = field === 'count' || field === 'marks' ? Number(value) : value;
            this.recalculateTotals();
        }
    },

    recalculateTotals() {
        let totalQs = 0;
        let totalMarks = 0;
        this.rules.forEach(r => {
            totalQs += (r.count || 0);
            totalMarks += ((r.count || 0) * (r.marks || 0));
        });
        
        document.getElementById('bp-total-qs').value = totalQs;
        document.getElementById('bp-total-marks').value = totalMarks;
    },

    renderRules() {
        const container = document.getElementById('rules-container');
        container.innerHTML = this.rules.map(rule => `
            <tr>
                <td><input type="text" class="form-control" placeholder="e.g. Algebra" value="${rule.topic}" onchange="blueprintApp.updateRule(${rule.id}, 'topic', this.value)"></td>
                <td>
                    <select class="form-control" onchange="blueprintApp.updateRule(${rule.id}, 'difficulty', this.value)">
                        <option value="easy" ${rule.difficulty === 'easy' ? 'selected' : ''}>Easy</option>
                        <option value="medium" ${rule.difficulty === 'medium' ? 'selected' : ''}>Medium</option>
                        <option value="hard" ${rule.difficulty === 'hard' ? 'selected' : ''}>Hard</option>
                    </select>
                </td>
                <td>
                    <select class="form-control" onchange="blueprintApp.updateRule(${rule.id}, 'question_type', this.value)">
                        <option value="MCQ" ${rule.question_type === 'MCQ' ? 'selected' : ''}>MCQ</option>
                        <option value="True/False" ${rule.question_type === 'True/False' ? 'selected' : ''}>True/False</option>
                    </select>
                </td>
                <td>
                    <select class="form-control" onchange="blueprintApp.updateRule(${rule.id}, 'source', this.value)">
                        <option value="">Entire Bank</option>
                        <option value="1">My Questions Only</option>
                    </select>
                </td>
                <td><input type="number" min="1" class="form-control" value="${rule.count}" oninput="blueprintApp.updateRule(${rule.id}, 'count', this.value)"></td>
                <td><input type="number" min="1" class="form-control" value="${rule.marks}" oninput="blueprintApp.updateRule(${rule.id}, 'marks', this.value)"></td>
                <td><button type="button" class="btn btn-sm btn-danger" onclick="blueprintApp.removeRule(${rule.id})">X</button></td>
            </tr>
        `).join('');
        this.recalculateTotals();
    },

    async save() {
        const payload = {
            id: document.getElementById('bp-id').value || null,
            name: document.getElementById('bp-name').value,
            subject: document.getElementById('bp-subject').value,
            grade: document.getElementById('bp-grade').value,
            semester: document.getElementById('bp-semester').value,
            total_questions: parseInt(document.getElementById('bp-total-qs').value),
            total_marks: parseFloat(document.getElementById('bp-total-marks').value),
            time_limit_minutes: parseInt(document.getElementById('bp-time').value),
            shuffle_questions: document.getElementById('bp-shuff-q').checked ? 1 : 0,
            shuffle_choices: document.getElementById('bp-shuff-c').checked ? 1 : 0,
            rules: this.rules.map(r => ({
                topic: r.topic,
                difficulty: r.difficulty,
                question_type: r.question_type,
                source_teacher_id: r.source ? parseInt(r.source) : null,
                question_count: r.count,
                marks_per_question: r.marks
            }))
        };

        try {
            const res = await fetch('api_blueprints.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            
            if (data.success) {
                alert(`Blueprint Saved! Version: ${data.version}`);
                document.getElementById('bp-id').value = data.id;
            } else {
                alert('Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to save blueprint.');
        }
    },

    async generate() {
        const bpId = document.getElementById('bp-id').value;
        if (!bpId) return alert('Save the blueprint first before generating an exam.');
        
        try {
            const res = await fetch(`api_blueprints.php?action=generate&id=${bpId}`);
            const data = await res.json();
            
            if (data.success) {
                document.getElementById('generation-preview').style.display = 'block';
                document.getElementById('exam-output').textContent = JSON.stringify(data.exam, null, 2);
                alert('Exam generated successfully from the question bank!');
            } else {
                alert('Generation Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to generate exam.');
        }
    },
    
    duplicate() {
        document.getElementById('bp-id').value = '';
        document.getElementById('bp-name').value += ' (Copy)';
        alert('Blueprint duplicated. Ready to save as a new instance.');
    }
};

document.addEventListener('DOMContentLoaded', () => blueprintApp.init());