# EAES Word → JSON Converter

A standalone tool for subject teachers: upload a **Word (.docx)** or plain-text
**(.txt)** document, review the automatically-parsed questions, and download a
**JSON file** that the EAES Exam System imports directly.

No template is required. The converter understands the conventional way teachers
write questions:

- Numbered questions — `1. …`, `1) …`, `Q3. …`, `Question 3: …` (Word's automatic
  list numbering works too)
- Lettered options — `A. … B. … C. … D. …` (tables and wrapped lines supported)
- Answers — `Answer: C`, `Ans. B`, `The correct answer is A`, **bolded** options,
  or a trailing answer key (`Answers: 1-B 2-A 3-C`)
- True/False items — bare `True` / `False` lines, or a `(True/False)` tag
- Reading passages — consecutive prose paragraphs (and `[Paragraph N]` labels)
  are captured as passage blocks

**Nothing is ever stored.** The document is parsed in memory and only your
download is saved. There is no login, no license check, and no database.

---

## For teachers — three steps

1. **Open the converter** at `http://localhost/eaes_exam_system_protected/word-to-json/`
   (or wherever you deployed it).
2. **Drop in your Word document.** Review every parsed question:
   - fix the wording if needed,
   - set any missing correct answers,
   - untick questions you don't want.
   Optionally set a **subject / grade / difficulty** — they fill in automatically
   for every question.
3. **Download the JSON** and hand it to the admin, or upload it yourself in EAES:

   | Format | Use | Import path in EAES |
   |---|---|---|
   | **Question Bank JSON** | build up the shared bank | Question Bank → **Import** |
   | **Exam JSON** | create one whole exam paper | Exams → **Create/Edit exam** → attach file |

### Quick tips so parsing goes smoothly
- Put one question per numbered item; keep options on their own lines.
- Mark answers with an `Answer: C` line (or bold the correct option).
- For a reading-comprehension section, keep the passage text before its questions —
  it will be detected and saved as a passage.
- Save older Word `.doc` files as **.docx** first (File → Save As → Word Document).

### Formats
- **Question Bank JSON** allows questions without answers (Essay items, True/False,
  MCQ) — you can finish them inside the Question Bank.
- **Exam JSON** is strict: the exam engine grades from the answer key, so any
  question without a detected answer is listed and excluded. Questions that can't
  be auto-graded (Essay items) are skipped with a warning — use the Question Bank
  format for those.

---

## For admins — deployment

Copy the `word-to-json/` folder to any server running **PHP 8** (XAMPP works as-is).
When the folder sits inside the EAES tree it automatically uses the project's own
parser; when copied elsewhere it uses the bundled copy in `lib/`.

```bash
cp -r word-to-json /path/to/your/server/
```

Requirements: PHP 8 + `zlib` + `simplexml` (all bundled by default in XAMPP).

### Sample run
Open `word-to-json/index.php?demo=1` to see the review screen pre-filled with a
sample parse — no upload needed.

### Tests
```bash
php tests/converter_test.php
```

---

## How it works

- `index.php` — the whole app (upload → parse → review → download).
- `lib/DocxQuestionParser.php` — the pure-PHP Word parser (a `.docx` is a ZIP of
  XML; a tiny ZIP reader + `gzinflate` extracts `word/document.xml` — no Composer,
  no ZipArchive extension needed). This file is a byte-identical copy of
  `app/Services/DocxQuestionParser.php`; when deployed inside the project the
  project copy is used so fixes stay in one place.
- The downloaded files match exactly what EAES accepts:
  - `{"questions": […]}` for the Question Bank import,
  - a bare question/passage array for the exam module (`Validator::examJson` shape).
