# Administrator Guide

## Logging in

Go to `adminlogin.php` and sign in with the account you created during
installation. After 5 failed attempts on a given username, that account
is locked for 15 minutes (both numbers are configurable in `.env` via
`ADMIN_MAX_LOGIN_ATTEMPTS` / `ADMIN_LOCKOUT_MINUTES`).

### Forgot the admin password?

There is no email anywhere in the system, so the recovery path is the
command line, on the machine that runs the server. From the project
root, run:

```bash
php database/reset_admin_password.php                 # list admin accounts
php database/reset_admin_password.php admin           # random temp password (printed once)
php database/reset_admin_password.php admin 'NewPass' # set a specific password (min 8 chars)
```

The reset also **clears any failed-attempt lockout** on that account,
so it covers a locked admin too. The temporary password is shown once
— log in and change it right away. If you have terminal access to the
machine, this always works, no matter how badly the account is locked.

### Managing admin accounts (Admin → Settings)

The **Settings** tab in the admin header manages admin accounts from
the web, so one administrator can always rescue another without
needing the command line:

- **Create Admin Account** — username (min 3 chars, must be unique),
  optional full name, optional password (min 8 chars). Leave the
  password blank and a one-time temporary password is generated and
  shown once; the new admin changes it after first login.
- **Reset password** — on any *other* admin's row. Generates a
  one-time temporary password, shown once, and **clears that admin's
  login lockout** in the same step. You cannot reset your own account
  here — use **Change Password** in the top-right menu.
- **Delete** — permanently removes another admin's account. Blocked
  for your own account and for the **last remaining admin**, so the
  system can never be left without a working login.

Every create / reset / delete is written to the audit log. The command
line tool above remains the fallback if *all* web access is locked out.

## Managing students

Students **do not register themselves** — accounts are created and
managed exclusively from the **Students** tab in the admin header:

- **Add Student** — opens a modal (full name, roll number, stream,
  section, optional password). Leave the password blank and a random
  one-time temporary password is generated and shown to you once;
  hand it to the student. A roll number that's already registered is
  rejected, and a legacy "no password yet" record is activated instead
  of duplicated.
- **Import CSV / Excel** — upload a whole class list (`full_name,
  roll_number,stream,section`, one student per row; the modal includes
  a downloadable template). You can upload a **.csv**, **.txt**, or an
  **Excel .xlsx** file directly — no need to convert or follow a strict
  template (column names like `name`/`roll` are accepted too, and the
  stream column is forgiving: any spelling of "Natural Science" /
  "Social Science" works — lower/upper case, extra spaces, dashes,
  abbreviations like `NS`/`SS` or `N. Science`, one-word forms, and the
  Amharic labels are all recognized). Every row gets a random temporary
  password and you receive a one-time
  **credentials CSV** to download and hand out (the plaintext passwords
  exist nowhere else — they're cleared after download and never
  logged). Brand-new rolls are created, legacy "no password yet"
  records are activated when the import identity matches, and rows that
  are invalid or already registered are reported and skipped — an
  already-registered roll's password is **never** overwritten by a
  re-import.
- **Reset Password** — generates a fresh one-time temporary password,
  shown once; use it when a student forgets their credentials.
- **Remove Student** — removal now **archives** (soft-delete): the
  account leaves the active list and the student can no longer log in,
  but the row — and their attempt history — is fully restorable.
  Removal is **refused while the student is mid-exam on a live exam**
  — stop the exam first.
- **Remove by List** — the bulk mirror of import: upload a **.csv**,
  **.txt**, or **Excel .xlsx** listing the roll numbers to remove (one
  per line, or a `roll_number[,stream]` header; the stream column is
  optional — without it every student with that roll is removed).
  Uploading shows a **confirm screen first**: the exact students that
  will be archived (name, roll, stream, section, attempt count), plus
  the rows that will be skipped (not found, or mid-exam on a live
  exam). Nothing is touched until you click **Yes — archive N
  student(s)**, and the preview expires after 30 minutes; the archived
  students stay restorable from the Archived tab.
- **Archived tab** — every removed student lands here with the date
  they were archived. **Restore** brings them back (attempt history
  included, login works again); **Delete permanently** is the only way
  to truly erase a student, and it warns how many attempt records will
  cascade. An archived roll number can't be re-added until it's
  restored or purged.

Students then log in at `slogin.php` with their roll number, stream,
and password. If they forget their password, they can recover it
through the identity-verified **Forgot password?** link on the login
page (name + section must match), or you can reset it here.

## Creating an exam profile

1. From the **Exams** tab, click **Create New Exam Profile**.
2. Fill in the profile name, target stream (Natural Science / Social
   Science), duration (HH:MM:SS), a header color, and attach a
   **question JSON file**.
3. Click **Save Profile**.

### Question JSON format

The uploaded file must be a JSON array. Each item is either a question
or a reading-passage block:

```json
[
  {
    "type": "passage",
    "id": "I",
    "content": "Read the following passage and answer questions 1-3..."
  },
  {
    "question_number": 1,
    "paragraph_text": "",
    "question_text": "What is the derivative of $x^2$?",
    "option_a": "$x$",
    "option_b": "$2x$",
    "option_c": "$x^2$",
    "option_d": "$2$",
    "correct_answer": "b"
  }
]
```

Notes:
- `type` defaults to `"question"` if omitted.
- `correct_answer` must be one of `a`, `b`, `c`, `d` (case-insensitive).
- Wrap math in `$...$` (inline) or `$$...$$` (block) — MathJax renders it
  automatically in the exam portal.
- The file is validated before anything is written to the database; if
  any item is malformed you'll see a specific error and nothing is
  imported.
- Max upload size: 5MB.

## Anti-cheating: per-student question shuffling

Each exam profile has two independent checkboxes in the create/edit modal:

- **Shuffle question order** — every student sees the exam's questions in
  their own randomly-generated order.
- **Shuffle answer choice order** — every student sees each question's
  A/B/C/D options in their own randomly-generated order.

Both are off by default (fully backward compatible with existing exams).
A student's order is generated **once**, the very first time they open
`examportal.php` for that exam, using a cryptographically secure random
number generator on the server. From that point on it is permanent for
that student's attempt — refreshing the page, losing connection,
resuming later, or a server restart will never reshuffle it. The Review
screen shown before final submission always reflects the same order the
student saw during the exam.

If a passage-based reading comprehension block exists (`"type":
"passage"` in the question JSON), its sub-questions always move together
as a single unit when question order is shuffled — they are never
scattered away from their passage.

Grading is entirely unaffected: every answer is still stored and graded
by the question's original identity (`question_number` and, for choices,
the original `a`/`b`/`c`/`d` letter), never by its shuffled display
position. Existing reports (score CSVs, question-difficulty analysis) are
unaffected for the same reason.

If you replace an exam's question set (re-upload a new `.json` over an
existing profile), any previously-generated student orders for that exam
are cleared automatically — they no longer match the new question set —
and regenerate fresh the next time each student opens the exam.

## Going live

Each exam profile has a **Start Exam** / **Stop Exam** toggle. Only one
exam can be live at a time — starting a new one automatically stops any
other. The moment you click **Start Exam**, every student sitting in the
waiting room (`waite.php`) is redirected into the portal within ~2
seconds, and their personal timer starts server-side at that instant.

## Downloading results

Once an exam is stopped (or has at least one attempt), each card offers:

- **Download Results (.csv)** — one row per student: score, percentage,
  status (Completed / Auto-submitted / In progress), timestamps, and
  integrity violation count/status (see below).
- **Question Analysis (.csv)** — one row per question: how many students
  answered it, how many got it right, and the resulting accuracy
  percentage. Use this to spot ambiguous or mis-keyed questions.

## Exam lockdown & integrity monitoring

While an exam is live, the student's browser is placed in a monitored
fullscreen mode. The system records (and, past a threshold, flags) these
events per student:

- Exiting fullscreen
- Switching to another tab or window
- Attempting to copy, cut, paste, right-click, or open devtools

Each event is written to the audit trail, and any exam card with flagged
attempts shows a 🚩 badge with the count. The scoreboard CSV includes a
per-student violation count and a Clean/Flagged status column so you can
review before finalizing grades — flags are a signal to check, not an
automatic penalty.

This is configured in `.env` (see `.env.example` for the full list):

- `INTEGRITY_LOCKDOWN_ENABLED` — turn the whole feature on/off.
- `INTEGRITY_FLAG_THRESHOLD` — violations before an attempt shows as
  flagged (default 3).
- `INTEGRITY_AUTO_SUBMIT_THRESHOLD` — if set above 0, an attempt is
  automatically submitted once it hits this many violations. This is
  **off by default**; most schools should review flagged attempts
  manually for a while before enabling automatic submission.

Like any browser-based proctoring, this deters casual, in-browser
cheating and gives you a reviewable record — it isn't a substitute for
in-room supervision if that's a requirement for a given exam.

## Analytics dashboard

The **Analytics** tab shows platform-wide totals (exams, students,
attempts, completion rate, average score), a per-exam performance table,
and a score-distribution chart for whichever exam you select.

## Changing your password

**Change Password** is under your profile icon (top right). You'll need
your current password; the new one must be at least 8 characters.

## License status

The **License** tab shows the active signed license and provides the hardware
ID plus upload form needed to activate a `license.lic` file.

## Editing or deleting an exam

Hover over an exam card to reveal ✏️ (edit) and 🗑️ (delete) buttons.
Editing lets you change the name/duration/stream/color without
touching questions, or attach a new JSON file to fully replace the
question set. Deleting an exam permanently removes its questions and
all attempt history for that exam — you'll be asked to confirm.

## Question Bank

The **Question Bank** tab is a standalone, reusable pool of questions
that is independent of any single exam. From here you can manage
questions once and attach them to exam papers as needed.

### Browsing & filtering

- **Search** matches the question text, subject, topic and tags.
- Filter by **Subject**, **Grade**, **Difficulty** (Easy/Medium/Hard)
  and **Type** (MCQ / True/False / Essay).
- Narrow by **creation date range** and optionally "Only my questions".
- The **Active / Archived** tabs switch between live questions and the
  recycle bin. The list is paginated (15 per page).

### Creating & editing questions

Click **New Question** and fill in the form:

- **MCQ** — four options (at least two non-empty) and a correct answer
  A/B/C/D.
- **True/False** — options are fixed to True/False; just pick the
  correct statement.
- **Essay** — free text only. Essay questions are stored for reference
  and review, but the exam engine cannot auto-grade them, so **they
  cannot be assigned to exam papers**.

Editing a question never changes the copies already sitting inside
exams — exam papers keep the exact wording students saw when the exam
was assembled.

### Archiving (soft delete)

🗑 **Archive** hides a question from the bank without destroying anything,
and it can always be restored later.
Data-integrity rules:

- A question assigned to a **live** exam cannot be archived at all —
  you'll be told which exam is live.
- A question assigned only to non-live exams can be archived, with a
  warning listing those exams. The copies already inside them stay
  untouched; only future assignment is blocked.

There is no hard delete from the Question Bank UI — everything is
recoverable via the **Archived** tab → ♻ Restore.

### Assigning questions to an exam

Select questions (checkboxes, or the header checkbox for the whole
page) and click **📎 Assign to Exam**, then pick an exam and a point
value. Every assignment:

- Places a copy of the question into the exam at the end of its
  question order, so it appears in the exam portal immediately and is
  graded automatically.
- Records the source question, its points and position — visible under
  **🗂 Assignments**, where you can fine-tune marks per question or
  remove a question from an exam.

Rules you'll hit in practice:

- A question can only be assigned to a given exam once.
- **Live exams are locked** — you cannot assign, unassign or change
  marks while students are taking the exam.
- Only MCQ and True/False questions can be assigned.

### Import & export

**⬆ Import** accepts a **CSV** or **JSON** file (max 5 MB) and inserts
all valid rows, reporting any skipped rows with their line number and
reason. CSV columns (first row is the header):

```
question,type,difficulty,subject,grade,topic,tags,option_a,option_b,option_c,option_d,correct_answer
```

- `type` ∈ `MCQ`, `True/False`, `Essay` (defaults to `MCQ`).
- `difficulty` ∈ `easy`, `medium`, `hard` (optional).
- `correct_answer` ∈ `a`–`d` for MCQ, `true`/`false` or `a`/`b` for
  True/False.
- A default subject/grade entered on the import dialog is applied to
  rows where the column is empty.

JSON uses the same keys in an array under `"questions"`. Download a
starter **template** from the ⬇ Export menu. **Export** writes the
current filtered view to CSV or JSON.

### Technical notes for maintainers

- Bank rows live in the `questions` table with `exam_id IS NULL`;
  assigning one materializes a copy with `exam_id` set and
  `source_question_id` pointing back to the bank row. The
  `exam_question_assignments` table tracks points/position.
- All bank mutations go through `api_questions.php` (CSRF-protected,
  admin-session only) backed by `App\Repositories\QuestionBankRepository`.
- Integration coverage: `tests/question_bank_api_test.php` (78 checks)
  runs against a scratch database and also verifies the migration file
  is idempotent.
