const recommendationApp = {
    currentExamId: null,

    async analyzeExam() {
        const examId = document.getElementById('rec-exam-id').value;
        if (!examId) return alert('Enter Exam ID.');
        this.currentExamId = examId;

        document.getElementById('optimization-container').style.display = 'block';
        document.getElementById('recommendations-list').innerHTML = '<div class="text-center p-4">Analyzing heuristics...</div>';

        try {
            const res = await fetch(`api_recommendations.php?action=analyze&exam_id=${examId}`);
            const data = await res.json();
            
            if (data.success) {
                this.renderRecommendations(data.data);
            } else {
                alert('Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to generate insights.');
        }
    },

    renderRecommendations(data) {
        document.getElementById('exam-balance-status').innerHTML = `
            <strong>Overall Difficulty Balance:</strong> ${data.difficulty_status} (Avg p-value: ${data.average_difficulty})
        `;

        const container = document.getElementById('recommendations-list');
        if (data.recommendations.length === 0) {
            container.innerHTML = `<div class="alert alert-success">Exam is perfectly optimized! No recommendations generated.</div>`;
            return;
        }

        container.innerHTML = data.recommendations.map(rec => {
            let badge = '';
            let btn = '';
            
            if (rec.type === 'CRITICAL_REPLACE') badge = '<span class="badge bg-danger">Critical: Retire Question</span>';
            if (rec.type === 'SUGGEST_REPLACE') badge = '<span class="badge bg-warning text-dark">Optimization: Swap Question</span>';
            if (rec.type === 'NEEDS_VALIDATION') badge = '<span class="badge bg-info">Insight: Needs Validation</span>';
            if (rec.type === 'CURRICULUM_GAP') badge = '<span class="badge bg-secondary">Curriculum Gap</span>';
            if (rec.type === 'EXAM_BALANCE') badge = '<span class="badge bg-primary">Overall Balance</span>';

            if (rec.has_alternative) {
                btn = `<button class="btn btn-sm btn-success mt-2" onclick="recommendationApp.applySwap(${rec.question_id}, ${rec.alternative_id})">Apply Recommendation (Swap)</button>`;
            }

            return `
                <div class="list-group-item list-group-item-action flex-column align-items-start">
                    <div class="d-flex w-100 justify-content-between">
                        <h5 class="mb-1">${badge}</h5>
                    </div>
                    <p class="mb-1 mt-2"><strong>Insight:</strong> ${rec.explanation}</p>
                    ${rec.text ? `<p class="text-muted small mb-1"><strong>Current:</strong> [ID: ${rec.question_id}] ${rec.text}</p>` : ''}
                    ${rec.has_alternative ? `<p class="text-success small mb-1"><strong>Suggested Replacement:</strong> [ID: ${rec.alternative_id}] ${rec.alternative_text} <br> <em>${rec.alternative_explanation}</em></p>` : ''}
                    ${btn}
                </div>
            `;
        }).join('');
    },

    async applySwap(oldId, newId) {
        if (!confirm('Apply this AI recommendation and swap the question automatically?')) return;

        try {
            const res = await fetch('api_recommendations.php?action=apply_swap', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    exam_id: this.currentExamId, 
                    old_question_id: oldId, 
                    new_question_id: newId,
                    reason: 'AI Heuristic Optimization Applied'
                })
            });
            const data = await res.json();
            
            if (data.success) {
                alert('Success: ' + data.message);
                this.analyzeExam(); // Refresh the list
            } else {
                alert('Swap Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to apply recommendation.');
        }
    }
};