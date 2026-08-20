const curriculumApp = {
    async loadReport() {
        const examId = document.getElementById('ci-exam-id').value;
        if (!examId) return alert('Please enter an Exam ID.');

        try {
            const res = await fetch(`api_curriculum.php?action=coverage&exam_id=${examId}`);
            const data = await res.json();

            if (data.success) {
                this.renderReport(data.report);
            } else {
                alert('Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to fetch curriculum intelligence report.');
        }
    },

    renderReport(report) {
        document.getElementById('ci-report-container').style.display = 'block';

        const warningsDiv = document.getElementById('ci-warnings');
        if (report.uncovered_chapters.length > 0) {
            warningsDiv.innerHTML = `
                <div class="alert alert-danger">
                    <strong>Critical Warning:</strong> Uncovered chapters detected: <strong>${report.uncovered_chapters.join(', ')}</strong>. Exam deviates from curriculum standards!
                </div>`;
        } else {
            warningsDiv.innerHTML = `
                <div class="alert alert-success">
                    <strong>Curriculum Compliance Optimal:</strong> All chapters are represented in this exam instance.
                </div>`;
        }

        const barsContainer = document.getElementById('coverage-bars');
        barsContainer.innerHTML = report.chapters_breakdown.map(chap => `
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <strong>${chap.chapter_name}</strong>
                    <span>Target: ${chap.target_weight}% | Actual: ${chap.actual_percentage}% (${chap.actual_marks} Marks)</span>
                </div>
                <div class="progress" style="height: 22px;">
                    <div class="progress-bar ${chap.actual_percentage >= chap.target_weight ? 'bg-success' : 'bg-info'}" 
                         role="progressbar" 
                         style="width: ${Math.min(chap.actual_percentage, 100)}%;" 
                         aria-valuenow="${chap.actual_percentage}" aria-valuemin="0" aria-valuemax="100">
                         ${chap.actual_percentage}%
                    </div>
                </div>
            </div>
        `).join('');

        const recsList = document.getElementById('recommendations-list');
        if (report.recommendations.length === 0) {
            recsList.innerHTML = `<li class="list-group-item text-success">No recommendations needed. Perfect curriculum match!</li>`;
        } else {
            recsList.innerHTML = report.recommendations.map(rec => `
                <li class="list-group-item list-group-item-warning">${rec}</li>
            `).join('');
        }
    }
};