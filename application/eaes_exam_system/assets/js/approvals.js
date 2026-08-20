const approvalQueue = {
    init() {
        this.fetchPending();
    },

    async fetchPending() {
        try {
            const res = await fetch('api_approval.php?action=pending');
            const data = await res.json();
            
            const tbody = document.getElementById('pending-queue');
            if (data.success) {
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4">Your queue is empty.</td></tr>';
                    return;
                }
                
                tbody.innerHTML = data.data.map(item => `
                    <tr>
                        <td>${item.id}</td>
                        <td><span class="badge bg-secondary">Question</span></td>
                        <td class="text-truncate" style="max-width: 250px;">${item.question}</td>
                        <td>${item.submitter_name || 'System / Unknown'}</td>
                        <td>
                            <button class="btn btn-sm btn-primary" onclick="approvalQueue.openModal(${item.id})">Review</button>
                        </td>
                    </tr>
                `).join('');
            } else {
                tbody.innerHTML = `<tr><td colspan="5" class="text-danger text-center">Error: ${data.error}</td></tr>`;
            }
        } catch (e) {
            console.error('Failed to load queue', e);
        }
    },

    openModal(entityId) {
        document.getElementById('modal-entity-id').value = entityId;
        document.getElementById('modal-comments').value = '';
        const modal = new bootstrap.Modal(document.getElementById('decisionModal'));
        modal.show();
    },

    async processDecision(decision) {
        const entityId = document.getElementById('modal-entity-id').value;
        const comments = document.getElementById('modal-comments').value;

        if (decision === 'reject' && comments.trim() === '') {
            alert('Comments are required when rejecting an item.');
            return;
        }

        try {
            const res = await fetch('api_approval.php?action=decision', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    entity_id: entityId,
                    entity_type: 'question',
                    decision: decision,
                    comments: comments
                })
            });
            const data = await res.json();

            if (data.success) {
                // Close modal (assuming bootstrap is loaded globally)
                const modalEl = document.getElementById('decisionModal');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                modalInstance.hide();
                
                // Refresh list
                this.fetchPending();
            } else {
                alert('Workflow Error: ' + data.error);
            }
        } catch (e) {
            alert('Failed to process decision.');
        }
    }
};

document.addEventListener('DOMContentLoaded', () => approvalQueue.init());