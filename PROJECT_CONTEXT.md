# SDSF Faculty Portal — Project Context File
Last Updated: 2026-09-09
Purpose: Allows any AI session to immediately understand the project and resume work.

## Project Goal
Visiting Faculty Remuneration Management System for SDSF (Devi Ahilya Vishwavidyalaya, Indore).
Automates: Faculty registration -> Course assignment -> Lecture entry -> Auto remuneration calculation -> Annexure-IV PDF.

## Tech Stack
PHP (XAMPP), MySQL (sdsf_faculty_portal DB), Tailwind CSS v4 (tailwind/output.css), FPDF (vendor/fpdf/fpdf.php), Google Fonts Inter.
URL: http://localhost/SDSF_Faculty_Portal/

## MySQL Connection
Host: localhost, DB: sdsf_faculty_portal, User: root, Pass: (empty)
PDO with ERRMODE_EXCEPTION and FETCH_ASSOC.

## Tables (DO NOT touch the admin table)
1. admin (username, pass) - EXISTING, plain text passwords
2. faculty_members (id, faculty_enrollment_no UNIQUE, name, email, phone, address, qualification, department, pan_no, account_no, bank_name, ifsc_code, aadhaar_no, theory_rate, practical_rate, status, created_at)
3. courses (id, program, semester, subject_name, course_code, class_type ENUM T/P, created_at) — Currently M.Tech AI&DS across 9 Semesters
4. faculty_course_assignments (id, faculty_id FK, course_id FK, assigned_at) UNIQUE(faculty_id, course_id)
5. lecture_entries (id, faculty_id FK, course_id FK, lecture_date, hours, rate_per_hour, amount, created_at)
6. payment_records (id, faculty_id FK, month, year, total_hours, total_amount, date_of_submission, generated_at)
7. monthly_report_submissions (id, faculty_id FK, month, year, submission_date, attendance_register_page, cheque_no, theory_hours, practical_hours, total_hours, theory_rate, practical_rate, total_amount, status)

## Business Rules
THEORY_RATE = default 800 Rs/hr (class_type = T, customizable per faculty)
PRACTICAL_RATE = default 400 Rs/hr (class_type = P, customizable per faculty)
MAX_MONTHLY = 30000 Rs (warning only)
Amount = hours x faculty_rate (calculated in PHP BEFORE insert, never user-entered)

## Authentication
Admin: username+pass -> $_SESSION[admin_username], guard: requireAdmin()
Faculty: enrollment number ONLY (no password) -> $_SESSION[faculty_id, faculty_enrollment_no, faculty_name], guard: requireFaculty()

## Enrollment Number Format
First 4 uppercase chars of name + 4-digit sequence
e.g. Ritika Verma = RITI0001, Manisha Malviya = MANI0002

## File Structure
/ index.php (DONE), admin_login.php (DONE), faculty_login.php (Phase 3)
/includes/ db.php (DONE), auth.php (Phase 1 - add requireFaculty), helpers.php (Phase 1)
/vendor/fpdf/fpdf.php (Phase 1)
/admin/ logout.php (DONE), dashboard.php (Phase 2 REWRITE)
/admin/faculty/ list.php, register.php, view.php, edit.php (Phase 2)
/admin/courses/ list.php, add.php (Phase 2)
/admin/lectures/ overview.php (Phase 2)
/admin/reports/ generate_pdf.php (Annexure IV), attendance_pdf.php (Phase 4)
/faculty/ dashboard.php, lecture_entry.php, history.php, logout.php (Phase 3)

## PDF Format (Annexure IV - DAVV)
Header: ANNEXURE-IV, DEVI AHILYA VISHWAVIDYALAYA INDORE, SDSF department
Faculty block: UVFIN boxes, Name, Address, Mobile, Qualification, Month, Year, Date of Submission
Main table columns: Program | Semester | Subject | Dates with Duration (Hrs.) | Total Hrs. | Rate | Amount
  NOTE: Multiple dates per row formatted as DD/MM(Hrs) DD/MM(Hrs) - NOT one row per date
Footer: Total Hours, Total Amount, Amount in Words
Notes A-F (standard DAVV text)
Undertaking paragraph (standard)
Bottom: Banking details box (PAN, A/c, Bank, IFSC, Aadhaar) + Signature lines
Second document: Attendance sheet with Date/Code/Name/Theory-Practice/Hours table

## Design System
Light mode. Background: #f1f5f9. Sidebar: white. Cards: white.
Admin accent: Indigo #4f46e5. Faculty accent: Teal #0d9488.
Google Fonts Inter loaded from CDN.
CSS path from admin/ subdir: ../tailwind/output.css

## Implementation Progress
Phase 1 (Foundation): DONE (DB schema, courses seeded, auth guards, helpers, FPDF + core fonts installed, BOM stripped)
Phase 2 (Admin Panel): DONE (Dashboard, Courses add/list, Faculty register/list/view, Lectures overview)
Phase 3 (Faculty Portal): DONE (faculty_login with enrollment-only, dashboard, lecture_entry with live preview & auto-remuneration calculation, history, logout)
Phase 4 (PDF Generation): DONE (Official DAVV Annexure-IV remuneration bill PDF & Teaching attendance PDF with DAVV emblem)
Phase 5 (Polish & Verification): DONE (End-to-end verified, clean light theme, UTF-8 clean)

## Important Notes
- Write large PHP files via scratch dir then Copy-Item (avoid PS length limits)
- Scratch dir: C:\Users\User\.gemini\antigravity-ide\brain\8d06f6a5-8a81-4607-9804-3c1f150ed26e\scratch\
- faculty_members table was minimal before - DROP and RECREATE it with full schema
- PDF library: FPDF (not TCPDF). No Composer needed.
