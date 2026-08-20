const examSession = {
    sessionId: null,
    questions: [],
    currentIndex: 0,
    responses: {},
    startTime: null,

    async init(examId) {
        try {
            const res = await fetch('api_exam_attempt.php?action=start', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ exam_id: examId })
            });
            const data = await res.json();
            
            if (data.success) {
                this.sessionId = data.data.session_id;
                this.questions = data.data.questions;
                document.getElementById('ui-exam-name').textContent = data.data.exam_name;
                this.startTime = Date.now();
                this.renderQuestion();
            } else {
                alert(data.error);
            }
        } catch (e) {
            alert('Failed to initialize exam session.');
        }
    },

    renderQuestion() {
        if (this.questions.length === 0) return;
        const q = this.questions[this.currentIndex];
        const container = document.getElementById('question-box');
        
        container.innerHTML = `
            <h5>Question ${this.currentIndex + 1} of ${this.questions.length}</h5>
            <p class="lead mt-3">${q.question}</p>
            <div class="form-check mt-3">
                <input type="radio" name="ans" value="A" class="form-check-input" ${this.responses[q.id] === 'A' ? 'checked' : ''} onchange="examSession.saveAnswer(${q.id}, 'A')"> A) ${q.option_a}
            </div>
            <div class="form-check mt-2">
                <input type="radio" name="ans" value="B" class="form-check-input" ${this.responses[q.id] === 'B' ? 'checked' : ''} onchange="examSession.saveAnswer(${q.id}, 'B')"> B) ${q.option_b}
            </div>
            <div class="form-check mt-2">
                <input type="radio" name="ans" value="C" class="form-check-input" ${this.responses[q.id] === 'C' ? 'checked' : ''} onchange="examSession.saveAnswer(${q.id}, 'C')"> C) ${q.option_c}
            </div>
            <div class="form-check mt-2">
                <input type="radio" name="ans" value="D" class="form-check-input" ${this.responses[q.id] === 'D' ? 'checked' : ''} onchange="examSession.saveAnswer(${q.id}, 'D')"> D) ${q.option_d}
            </div>
        `;

        document.getElementById('btn-next').style.display = this.currentIndex === this.questions.length - 1 ? 'none' : 'inline-block';
        document.getElementById('btn-submit').style.display = this.currentIndex === this.questions.length - 1 ? 'inline-block' : 'none';
    },

    saveAnswer(qId, val) {
        this.responses[qId] = val;
    },

    nextQuestion() {
        if (this.currentIndex < this.questions.length - 1) {
            this.currentIndex++;
            this.renderQuestion();
        }
    },

    prevQuestion() {
        if (this.currentIndex > 0) {
            this.currentIndex--;
            this.renderQuestion();
        }
    },

    async submitExam() {
        if (!confirm('Are you sure you want to submit your exam?')) return;

        const formattedResponses = this.questions.map(q => ({
            question_id: q.id,
            selected_answer: this.responses[q.id] || null,
            time_spent_seconds: Math.floor((Date.now() - this.startTime) / 1000)
        }));

        try {
            const res = await fetch('api_exam_attempt.php?action=submit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: this.sessionId, responses: formattedResponses })
            });
            const data = await res.json();

            if (data.success) {
                alert(`Exam Submitted Successfully!\nYour Score: ${data.score} / ${data.total_marks}`);
                window.location.href = 'student_dashboard.php';
            } else {
                alert('Submission Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to submit exam.');
        }
    }
};