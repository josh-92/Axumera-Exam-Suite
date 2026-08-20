const generatorApp = {
    currentExamId: null,

    async generateNew() {
        const bpId = document.getElementById('gen-blueprint-id').value;
        if (!bpId) return alert('Please enter a Blueprint ID');

        if (!confirm('This will trigger an intelligent pull against the Question Bank. Proceed?')) return;

        try {
            // Can pass excluded_teachers array here if integrating with a UI multiselect
            const payload = { blueprint_id: bpId, excluded_teachers: [] }; 
            
            const res = await fetch('api_generator.php?action=generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                alert('Exam generated successfully!');
                this.loadExam(data.exam_id);
            } else {
                alert('Generation Error:\n\n' + data.error);
            }
        } catch (e) {
            alert('Failed to connect to generator engine.');
        }
    },

    async loadExam(examId) {
        try {
            const res = await fetch(`api_generator.php?action=view&exam_id=${examId}`);
            const data = await res.json();
            
            if (data.success) {
                this.currentExamId = data.exam.id;
                this.renderExam(data.exam);
            } else {
                alert(data.error);
            }
        } catch (e) {
            alert('Failed to load exam data.');
        }
    },

    renderExam(exam) {
        document.getElementById('exam-viewer').style.display = 'block';
        document.getElementById('view-exam-name').textContent = exam.exam_name;
        document.getElementById('view-exam-qs').textContent = exam.total_questions;
        document.getElementById('view-exam-marks').textContent = exam.total_marks;

        const isLocked = parseInt(exam.is_locked) === 1;
        const statusBadge = document.getElementById('view-exam-status');
        const btnLock = document.getElementById('btn-lock');
        const btnRegen = document.getElementById('btn-regen');

        if (isLocked) {
            statusBadge.className = 'badge bg-danger text-white mr-3';
            statusBadge.textContent = 'LOCKED (Production Ready)';
            btnLock.style.display = 'none';
            btnRegen.style.display = 'none';
        } else {
            statusBadge.className = 'badge bg-warning text-dark mr-3';
            statusBadge.textContent = 'UNLOCKED (Draft)';
            btnLock.style.display = 'inline-block';
            btnRegen.style.display = 'inline-block';
        }

        const container = document.getElementById('questions-container');
        container.innerHTML = exam.questions.map((q, idx) => `
            <div class="card mb-3 border-secondary">
                <div class="card-header bg-light d-flex justify-content-between">
                    <strong>Q${idx + 1}: ${q.topic} (${q.type} - ${q.difficulty})</strong>
                    <span>Marks: ${q.allocated_marks}</span>
                </div>
                <div class="card-body">
                    <p class="mb-2" style="font-size:1.1em;">${q.question}</p>
                    <div class="alert alert-info p-2 mt-3" style="font-size:0.9em; margin-bottom:0;">
                        <strong>Selection Reasoning:</strong> ${q.selection_reason}
                    </div>
                </div>
            </div>
        `).join('');
    },

    async lockExam() {
        if (!this.currentExamId) return;
        if (!confirm('Locking this exam makes it permanent and disables regeneration. Are you sure?')) return;

        try {
            const res = await fetch('api_generator.php?action=lock', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ exam_id: this.currentExamId })
            });
            const data = await res.json();
            
            if (data.success) {
                alert('Exam is now locked.');
                this.loadExam(this.currentExamId);
            } else {
                alert('Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to lock exam.');
        }
    },

    async regenerateExam() {
        if (!this.currentExamId) return;
        if (!confirm('This will wipe current questions and fetch a new set based on the Blueprint. Proceed?')) return;

        try {
            const res = await fetch('api_generator.php?action=regenerate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ exam_id: this.currentExamId })
            });
            const data = await res.json();
            
            if (data.success) {
                alert('Exam regenerated successfully!');
                this.loadExam(data.exam_id);
            } else {
                alert('Regeneration Error:\n\n' + data.error);
            }
        } catch (e) {
            alert('Failed to regenerate exam.');
        }
    }
};