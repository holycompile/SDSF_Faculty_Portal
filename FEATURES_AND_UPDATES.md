# SDSF Faculty Portal — Features, Architecture & Implementation Updates

> **School of Data Science & Forecasting (SDSF)**  
> **Devi Ahilya Vishwavidyalaya (DAVV), Indore**  
> *Visiting Faculty Remuneration & Academic Lecture Tracking System*  
> **Documentation Date:** September 2026

---

## 1. Executive Summary & Purpose

The **SDSF Faculty Portal** is a specialized academic remuneration and lecture accounting web application built for the School of Data Science & Forecasting at Devi Ahilya Vishwavidyalaya (DAVV), Indore.

The system replaces manual, paper-heavy billing with an automated, auditable, and regulatory-compliant digital workflow:
1. **Admin Faculty Onboarding & Course Mapping**: Secure faculty registration with auto-generated enrollment IDs and multi-course assignment.
2. **Lecture Delivery Logging**: Visiting faculty log daily conducted lectures with automatic rate lookup and tamper-proof server-side remuneration calculation.
3. **Monthly Audit Snapshots**: Historical snapshot persistence capturing submission dates, attendance register cross-references, and cheque numbers.
4. **Official DAVV Report Generation**: Print-ready, pixel-perfect reproductions of official DAVV documents:
   - **Annexure-IV** (Visiting Faculty Remuneration Claim Bill with UVFIN boxes and Indian currency words)
   - **Teaching Attendance Sheet** (Session-by-session sign-off log with DAVV emblem)
   - **Annexure IV-A** (Detailed Remuneration Statement)
5. **Secure Authentication & Password Management**: Dual-tab unified login, bcrypt-hashed passwords, and Brevo REST API email OTP password reset.

---

## 2. Technology Stack & System Architecture

| Layer | Technologies / Libraries | Details |
|---|---|---|
| **Backend Runtime** | PHP 8.x (XAMPP / Apache) | Procedural & modular PHP with clean separation of logic |
| **Database** | MySQL / MariaDB (`sdsf_faculty_portal`) | PDO driver with `ERRMODE_EXCEPTION`, prepared statements, transactions, FK cascades |
| **Frontend Styling** | Tailwind CSS v4 + Vanilla CSS Tokens | Compiled via `@tailwindcss/cli`, responsive flex/grid layouts, glassmorphism, micro-animations |
| **Typography** | Google Fonts Inter & Serif | Inter for web app UI; Times New Roman serif for official DAVV printable reports |
| **PDF & Report Engine** | Dynamic CSS Print Media (`@page A4`) & FPDF | Precision HTML/CSS print templates for browser PDF export + FPDF library for legacy generation |
| **Email Service** | Brevo (Sendinblue) REST API | Native PHP stream context HTTP client with fallback to standard PHP `mail()` |
| **Security & Cryptography** | `password_hash()` (bcrypt), random 6-digit OTPs | Auto-upgrade of plain text passwords to bcrypt hashes, session regeneration |

---

## 3. Database Schema Overview

The database contains 7 structured tables engineered with strict referential integrity and indexes:

```
+-------------------+       +-------------------------------+       +-------------------+
|  faculty_members  |1-----*|  faculty_course_assignments   |*-----1|      courses      |
+-------------------+       +-------------------------------+       +-------------------+
        |  1                                                                 |  1
        |                                                                    |
        +-----------------------------------+                                |
        | 1                                 | 1                              |
        v *                                 v *                              v *
+-------------------+             +-----------------------+        +--------------------+
|  lecture_entries  |*-----------*|  payment_records      |        |  lecture_entries   |
+-------------------+             +-----------------------+        +--------------------+
        | 1
        v *
+-------------------------------+       +-------------------------+
|  monthly_report_submissions   |       |   password_reset_otps   |
+-------------------------------+       +-------------------------+
```

### Table Details:
1. **`admin`**: Stores administrative credentials (`username`, `pass`).
2. **`faculty_members`**: Master registry of visiting professors (`id`, `faculty_enrollment_no` [UNIQUE], `name`, `email`, `phone`, `address`, `qualification`, `department`, `pan_no`, `account_no`, `bank_name`, `ifsc_code`, `aadhaar_no`, `password`, `is_password_changed`, `status`, `created_at`).
3. **`courses`**: Course catalog (`id`, `program`, `semester`, `subject_name`, `course_code`, `class_type` [T/P], `created_at`).
4. **`faculty_course_assignments`**: Junction table mapping professors to courses (`id`, `faculty_id`, `course_id`, `assigned_at`, cached meta fields) with a `UNIQUE(faculty_id, course_id)` constraint.
5. **`lecture_entries`**: Daily lecture logs (`id`, `faculty_id`, `course_id`, `lecture_date`, `hours`, `rate_per_hour`, `amount`, `created_at`).
6. **`payment_records`**: Legacy payment summary records (`id`, `faculty_id`, `month`, `year`, `total_hours`, `total_amount`, `date_of_submission`, `generated_at`).
7. **`monthly_report_submissions`**: Permanent auditable snapshots for monthly submissions (`id`, `faculty_id`, `month`, `year`, `submission_date`, `attendance_register_page`, `cheque_no`, `theory_hours`, `tutorial_hours`, `practical_hours`, `total_hours`, `theory_rate`, `practical_rate`, `theory_amount`, `practical_amount`, `total_amount`, `programs_covered`, `status`, timestamps) with a `UNIQUE KEY (faculty_id, month, year)`.
8. **`password_reset_otps`**: OTP storage for password recovery (`id`, `user_type` [faculty/admin], `identifier`, `email`, `otp`, `expires_at`, `is_used`, `created_at`).

---

## 4. Key Business Logic & Regulatory Rules

- **Remuneration Rates**:
  - **Theory Class (`T`)**: ₹800 per hour (`THEORY_RATE`)
  - **Practical Class (`P`)**: ₹400 per hour (`PRACTICAL_RATE`)
- **Amount Computation**:
  - `Amount = Hours × Rate`
  - Computed purely server-side in PHP during `INSERT` into `lecture_entries`; never trusted from client inputs.
- **Monthly Remuneration Ceiling**:
  - ₹30,000 monthly upper threshold.
  - Generates non-blocking warnings on lecture submission and faculty dashboard when approaching or exceeding this limit.
- **Faculty Enrollment Number Scheme**:
  - Formula: First 4 uppercase alphabetic characters of faculty name + 4-digit zero-padded sequence.
  - Examples: *Ritika Verma* &rarr; `RITI0001`, *Manisha Malviya* &rarr; `MANI0002`.
  - Collision-free sequential generator ensuring absolute uniqueness.
- **Date Verification**:
  - Future dates are prohibited during lecture entry.
  - Valid duration restricted between 0.5 and 12.0 hours.

---

## 5. Completed Features & Module Breakdown

### 5.1 Unified Entry & Authentication
- **Dual-Tab Login (`index.php`)**:
  - Seamless toggle between Visiting Faculty Login and SDSF Admin Login.
  - URL query parameter integration (`?tab=faculty` vs `?tab=admin`).
  - Active session detection with immediate dashboard redirect.
- **Faculty Authentication**:
  - Faculty logs in using Enrollment No. (`RITI0001`) and password.
  - Fallback support for default initial password (`SDSF@XXXX` or `hello`).
  - Automatic migration from plaintext to secure bcrypt hashes on successful login.
- **Email OTP Password Recovery (`forgot_password.php`)**:
  - Available for both Admin and Faculty.
  - Multi-step workflow: Enter Identifier/Email &rarr; Receive 6-digit OTP &rarr; Verify OTP &rarr; Reset Password.
  - Brevo REST API email delivery with HTML template.
- **In-App Password Management (`change_password.php`)**:
  - Direct password updates with old password verification and minimum 6-character validation.

### 5.2 Admin Control Panel (`/admin`)
- **Dashboard (`dashboard.php`)**:
  - 4 live metric counter cards (Total Faculty, Total Courses, Total Lectures, Total Remuneration Disbursed).
  - Welcome banner with live date and contextual greeting (Morning/Afternoon/Evening).
  - Quick action shortcuts (Add Course, Register Faculty, View Lectures, View Reports).
  - Recent Faculty onboarded table with course count and remuneration stats.
- **Faculty Management (`admin/faculty/`)**:
  - `register.php`: Complete onboarding form capturing contact info, educational qualifications, department, PAN, bank account details, IFSC code, and Aadhaar. Auto-generates enrollment ID.
  - `list.php`: Directory of all faculty members with search, active/inactive badges, assigned course count, and direct profile navigation.
  - `view.php`: Comprehensive faculty profile view featuring:
    - Personal and banking details card.
    - Assigned courses list with shortcut to course assignment manager.
    - Complete lecture log history table with filters and sum totals.
    - Official Reports Dropdown for 1-click access to Annexure-IV, Attendance, and Detailed sheets.
    - Modal-confirmed safe faculty deletion.
  - `manage_courses.php`: Multi-select interface for allocating courses to faculty and single-click revocation with duplicate check.
  - `delete.php`: Cascading removal of faculty records and associated data.
- **Course Catalog Management (`admin/courses/`)**:
  - `add.php`: Program selection, semester, subject name, course code, and class type (Theory vs Practical).
  - `list.php`: Searchable table displaying program, semester, course code, theory/practical rate tag, lecture count, and assigned faculty count.
  - `edit.php`: Form to modify course details.
  - `delete.php`: Transactional deletion with integrity safeguard (blocks deletion if lectures are attached to the course).
- **Lectures & Analytics (`admin/lectures/`)**:
  - `overview.php`: Global ledger of all conducted lectures across all faculty, filterable by faculty member, month, year, and course. Direct official report generator dropdown when faculty is selected.
  - `course_view.php`: Course-centric analytics showing session counts, hours logged, and remuneration breakdown per course for any selected faculty.

### 5.3 Visiting Faculty Portal (`/faculty`)
- **Faculty Dashboard (`dashboard.php`)**:
  - Overview cards: Total Lectures, Total Hours, Total Earnings, Current Month Earnings.
  - Visual monthly ceiling warning banner when earnings exceed ₹30,000.
  - Assigned courses cards with 1-click "Log Lecture" triggers.
  - Recent 6 lecture activities table.
  - Direct "Download Current Month Bill (PDF)" button.
- **Lecture Logging (`lecture_entry.php`)**:
  - Dropdown populated exclusively with the faculty's assigned courses.
  - Interactive live preview card updating hours, rate (₹800 vs ₹400), and projected payout before submission.
  - Validation: rejects future dates and out-of-range durations.
  - Tamper-proof server-side calculation and immediate monthly ceiling check.
- **Lecture History & Reports (`history.php`)**:
  - Filterable lecture history by course, month, and year.
  - Per-course summary metric cards.
  - "Official Reports" dropdown to generate Annexure-IV, Attendance Sheet, or Detailed Sheet for any selected month/year.

### 5.4 Official DAVV Reports Engine (`/admin/reports`)
- **Dynamic Report Bootstrap (`report_bootstrap.php`)**:
  - Shared aggregation engine calculating total theory, tutorial, and practical hours and amounts.
  - **Automatic Snapshot Persistence**: Automatically upserts records into `monthly_report_submissions` on report preview, tracking submission date, register page (`Page 02 - S.No. - 19`), cheque number, hours, and status (`submitted`).
  - Top sticky navigation toolbar:
    - Tab switching between Annexure-IV, Attendance, and Detailed Sheet.
    - Quick month/year selectors.
    - Inline submission date picker and attendance page number adjuster.
    - Direct "Print / Save as PDF" button (`window.print()`).
- **Annexure-IV Remuneration Bill (`annexure_iv.php`)**:
  - Exact layout of DAVV Indore Annexure-IV visiting faculty claim bill.
  - UVFIN boxes for faculty enrollment code.
  - Course-grouped lecture schedule with condensed date string notation: `DD/MM(Hrs) DD/MM(Hrs)`.
  - Automatic Indian numbering currency to words conversion (`numberToWords`).
  - Standard DAVV Notes A through F and legal undertaking paragraph.
  - Complete banking particulars table and dual signature blocks.
- **Teaching Attendance Sheet (`visiting_faculty_attendance.php`)**:
  - Official university header with DAVV emblem.
  - Chronological table of all lectures with date, course code, subject name, class type, duration, and signature lines.
  - Course in-charge and Head of Department verification blocks.
- **Detailed Remuneration Sheet (`detailed_remuneration.php`)**:
  - Annexure IV-A format itemizing theory hours, practical hours, applied rates, gross pay, register page references, and cheque assignment fields.

---

## 6. Chronological Project Updates & Git Milestones

```
* 3b9a0c7 (Commit 3) — Dynamic DAVV reports, monthly snapshot persistence, and email OTP authentication
|   ├── Added admin/reports/annexure_iv.php (Official DAVV format with UVFIN boxes)
|   ├── Added admin/reports/visiting_faculty_attendance.php (Teaching attendance sheet)
|   ├── Added admin/reports/detailed_remuneration.php (Annexure IV-A statement)
|   ├── Added admin/reports/report_bootstrap.php (Shared aggregation & snapshot upsert)
|   ├── Added monthly_report_submissions table in db.sql
|   ├── Added password_reset_otps table in db.sql
|   ├── Added includes/mailer.php & includes/mail_config.php (Brevo REST API email sender)
|   ├── Added admin/forgot_password.php & faculty/forgot_password.php
|   ├── Added admin/change_password.php & faculty/change_password.php
|   └── Refactored index.php into unified dual-tab portal with bcrypt password support
|
* 2b83d82 (Commit 2) — Feature card update for admin and faculty
|   ├── Added admin/courses/edit.php & admin/courses/delete.php (Safe deletion check)
|   ├── Added admin/faculty/manage_courses.php & admin/faculty/delete.php
|   ├── Added admin/lectures/course_view.php (Per-course analytics dashboard)
|   ├── Enhanced admin/lectures/overview.php with course filter and stats cards
|   ├── Enhanced faculty/history.php with per-course summary cards
|   ├── Enhanced helpers.php (generateEnrollmentNo sequential logic, numberToWords)
|   └── Polished course cards and visual UI components
|
* 018bbbe (Commit 1) — PDF Updates & Core Platform Foundation
    ├── Initial DB schema (admin, faculty_members, courses, assignments, lectures, payments)
    ├── Core Admin Panel (dashboard, faculty add/list/view, courses add/list, lectures)
    ├── Core Faculty Portal (dashboard, lecture_entry, history, logout)
    ├── Auth guards (requireAdmin, requireFaculty) and session handling
    ├── FPDF engine integration and assets (DAVV logo, department images)
    └── Tailwind CSS v4 setup and styling foundation
```

---

## 7. Directory & File Inventory

```
SDSF_Faculty_Portal/
│
├── index.php                         # Unified dual-tab login page (Admin / Faculty)
├── admin_login.php                   # Convenience redirect to index.php?tab=admin
├── faculty_login.php                 # Convenience redirect to index.php?tab=faculty
├── db.sql                            # Complete database schema and seed data
├── PROJECT_CONTEXT.md                # Fast context file for AI and developers
├── FEATURES_AND_UPDATES.md           # Comprehensive feature & update documentation
├── package.json / package-lock.json  # NPM dependencies for Tailwind CSS v4
│
├── admin/
│   ├── dashboard.php                 # Admin dashboard with key metrics & recent activity
│   ├── change_password.php           # In-app admin password modification
│   ├── forgot_password.php           # Email OTP password recovery for admin
│   ├── logout.php                    # Admin session destruction & logout
│   ├── courses/
│   │   ├── add.php                   # Add new course to catalog
│   │   ├── list.php                  # Course catalog listing & search
│   │   ├── edit.php                  # Edit course attributes
│   │   └── delete.php                # Safe course deletion with lecture check
│   ├── faculty/
│   │   ├── register.php              # Register new faculty with auto-enrollment ID
│   │   ├── list.php                  # Faculty directory & search
│   │   ├── view.php                  # Complete faculty profile, history & report links
│   │   ├── manage_courses.php        # Assign & unassign courses for faculty
│   │   └── delete.php                # Remove faculty member
│   ├── lectures/
│   │   ├── overview.php              # Global lecture ledger with multi-filters
│   │   └── course_view.php           # Per-course lecture breakdown & analytics
│   └── reports/
│       ├── report_bootstrap.php      # Aggregations, snapshot persistence & report toolbar
│       ├── annexure_iv.php           # Official DAVV Annexure-IV Claim Bill
│       ├── visiting_faculty_attendance.php # Official Teaching Attendance Sheet
│       ├── detailed_remuneration.php # Annexure IV-A Detailed Remuneration Sheet
│       ├── generate_html_pdf.php     # Standalone printable bill
│       └── attendance_pdf.php        # FPDF attendance PDF hook
│
├── faculty/
│   ├── dashboard.php                 # Faculty dashboard with hours, earnings & course cards
│   ├── lecture_entry.php             # Lecture logging with live preview & auto-rate
│   ├── history.php                   # Lecture history with course cards & report links
│   ├── change_password.php           # In-app faculty password modification
│   ├── forgot_password.php           # Email OTP password recovery for faculty
│   └── logout.php                    # Faculty session destruction & logout
│
├── includes/
│   ├── auth.php                      # Session guards (requireAdmin, requireFaculty) & rates
│   ├── db.php                        # PDO database connection
│   ├── helpers.php                   # Enrollment generator, Indian currency words, flash msgs
│   ├── mailer.php                    # Brevo REST API email sender with fallback
│   ├── mail_config.php               # Brevo API configuration loader
│   ├── admin_sidebar.php             # Navigation sidebar for admin interface
│   ├── faculty_sidebar.php           # Navigation sidebar for faculty interface
│   └── faculty_header.php            # Reusable faculty header
│
├── tailwind/
│   ├── input.css                     # Tailwind v4 source directives
│   └── output.css                    # Compiled CSS utility classes
│
├── assets/
│   ├── davvLogo.png                  # DAVV University crest
│   ├── davv_logo.png                 # Alternate DAVV logo asset
│   ├── departmentlogo_transparent.png# SDSF Department transparent emblem
│   └── Department Gibhli image.png   # Campus illustration asset
│
└── vendor/
    └── fpdf/                         # FPDF core class and font metric definitions
```

---

## 8. Summary of Completed Capabilities

1. **Automated Calculations**: Theory (₹800/hr) and Practical (₹400/hr) rates are applied automatically on lecture logging. No manual arithmetic required.
2. **Ceiling Safeguard**: Instant visual and message alerts when a faculty member approaches or exceeds the ₹30,000 monthly cap.
3. **Auditable Snapshots**: Generating any report saves an auditable record into `monthly_report_submissions`, tracking attendance register page numbers and cheque references.
4. **DAVV Compliance**: Generated Annexure-IV and Teaching Attendance reports match the official Devi Ahilya Vishwavidyalaya formats, including UVFIN boxes, condensed lecture date arrays, and currency in words.
5. **Modern Security & Password Recovery**: Hashed credentials, session protection, and self-service email OTP password reset via Brevo.
