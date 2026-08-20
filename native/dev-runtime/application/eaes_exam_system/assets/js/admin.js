function closeModal() { document.getElementById("examModal").style.display = "none"; }

function selectColorTheme(colorHex, element) {
    document.getElementById("selectedColorInput").value = colorHex;
    document.querySelectorAll(".color-dot").forEach(dot => dot.classList.remove("selected"));
    if (element) {
        element.classList.add("selected");
    } else {
        const target = document.querySelector(`.color-dot[data-color="${colorHex}"]`);
        if (target) target.classList.add("selected");
    }
}

function openModalForCreate() {
    document.getElementById("modalActionInput").value = "create";
    document.getElementById("modalExamIdInput").value = "";
    document.getElementById("modalTitleLabel").textContent = "Create Exam Profile";
    document.getElementById("modalSubmitBtnLabel").textContent = "Save Profile";
    document.getElementById("examName").value = "";
    document.getElementById("timeHH").value = "2";
    document.getElementById("timeMM").value = "0";
    document.getElementById("timeSS").value = "0";
    document.getElementById("streamSelect").value = "Natural Science";
    document.getElementById("shuffleQuestionsCheckbox").checked = false;
    document.getElementById("shuffleChoicesCheckbox").checked = false;

    document.getElementById("editFileStatusBadge").style.display = "none";
    document.getElementById("examJsonFile").required = true;
    document.getElementById("examJsonFile").value = "";
    document.getElementById("fileLabelContainer").textContent = "Upload Exam File (.json / .docx)";
    document.getElementById("fileFieldInstructionText").textContent = "Select a .json question array, a Word (.docx) document, or a .txt file.";

    selectColorTheme('#ff4a71', null);
    document.getElementById("examModal").style.display = "flex";
}

function triggerEditModal(data, event) {
    event.stopPropagation();
    document.getElementById("modalActionInput").value = "edit";
    document.getElementById("modalExamIdInput").value = data.id;
    document.getElementById("modalTitleLabel").textContent = "Modify Exam Parameters";
    document.getElementById("modalSubmitBtnLabel").textContent = "Update Profile";
    document.getElementById("examName").value = data.name;
    document.getElementById("timeHH").value = data.hh;
    document.getElementById("timeMM").value = data.mm;
    document.getElementById("timeSS").value = "0";
    document.getElementById("streamSelect").value = data.stream;
    document.getElementById("shuffleQuestionsCheckbox").checked = !!data.shuffleQuestions;
    document.getElementById("shuffleChoicesCheckbox").checked = !!data.shuffleChoices;

    document.getElementById("editFileStatusBadge").style.display = "block";
    document.getElementById("attachedFileName").textContent = data.filename;

    document.getElementById("examJsonFile").required = false;
    document.getElementById("examJsonFile").value = "";
    document.getElementById("fileLabelContainer").textContent = "Change / Replace Document (.json / .docx)";
    document.getElementById("fileFieldInstructionText").textContent = "Optional: choose a file only if you want to overwrite the current questions.";

    selectColorTheme(data.color, null);
    document.getElementById("examModal").style.display = "flex";
}

document.addEventListener('DOMContentLoaded', () => {
    const fileInput = document.getElementById("examJsonFile");
    if (fileInput) {
        fileInput.addEventListener("change", function () {
            if (this.files && this.files.length > 0) {
                document.getElementById("editFileStatusBadge").style.display = "none";
            }
        });
    }
});

let pendingDeleteId = null;
function triggerDeletePopup(id, examName, event) {
    event.stopPropagation();
    pendingDeleteId = id;
    document.getElementById("displayTargetExamName").textContent = examName;
    document.getElementById("safetyPopupContainer").classList.add("open");
    document.getElementById("confirmDeleteExecuteBtn").onclick = function () {
        window.location.href = "adminpanel.php?confirm_delete=" + pendingDeleteId + "&csrf_token=" + encodeURIComponent(window.EAES_CSRF || '');
    };
}
function closeDeletePopup() {
    document.getElementById("safetyPopupContainer").classList.remove("open");
    pendingDeleteId = null;
}

function downloadExamResults(examId) { window.location.href = "download_results.php?exam_id=" + examId; }
function downloadQuestionReport(examId) { window.location.href = "download_report.php?exam_id=" + examId; }
