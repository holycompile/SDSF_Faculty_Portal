-- ============================================================
-- SDSF Faculty Portal — Database Schema
-- Database: sdsf_faculty_portal
-- NOTE: The existing `admin` table is NOT touched.
-- ============================================================

USE sdsf_faculty_portal;

-- Drop in correct FK order
DROP TABLE IF EXISTS payment_records;
DROP TABLE IF EXISTS lecture_entries;
DROP TABLE IF EXISTS faculty_course_assignments;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS faculty_members;

-- ── 1. FACULTY MEMBERS ──────────────────────────────────────
CREATE TABLE faculty_members (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  faculty_enrollment_no VARCHAR(20) UNIQUE NOT NULL,
  name                  VARCHAR(150) NOT NULL,
  email                 VARCHAR(150),
  phone                 VARCHAR(20),
  address               TEXT,
  qualification         VARCHAR(200),
  department            VARCHAR(150),
  pan_no                VARCHAR(20),
  account_no            VARCHAR(30),
  bank_name             VARCHAR(100),
  ifsc_code             VARCHAR(20),
  aadhaar_no            VARCHAR(20),
  theory_rate           DECIMAL(8,2) NOT NULL DEFAULT 800.00 COMMENT 'Per theory class rate in Rs/hr',
  practical_rate        DECIMAL(8,2) NOT NULL DEFAULT 400.00 COMMENT 'Per practical class rate in Rs/hr',
  password              VARCHAR(255) NULL,
  is_password_changed   TINYINT DEFAULT 0,
  status                ENUM('active','inactive') DEFAULT 'active',
  created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ── 2. ACADEMIC PROGRAMS & BATCHES ────────────────────────────
CREATE TABLE IF NOT EXISTS academic_programs (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  program_name    VARCHAR(150) NOT NULL,
  program_code    VARCHAR(50) NOT NULL,
  batch_year      VARCHAR(50) NOT NULL,
  total_semesters INT NOT NULL DEFAULT 4,
  status          ENUM('active', 'inactive') DEFAULT 'active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prog_batch (program_code, batch_year)
);

-- ── 2B. SEMESTER YEAR TAGS (Editable per semester) ───────────
CREATE TABLE IF NOT EXISTS semester_tags (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  program_id      INT NOT NULL,
  semester_number INT NOT NULL,
  semester_name   VARCHAR(50) NOT NULL,
  year_tag        VARCHAR(50) NOT NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prog_sem (program_id, semester_number),
  FOREIGN KEY (program_id) REFERENCES academic_programs(id) ON DELETE CASCADE
);

-- ── 3. COURSES / SUBJECTS ────────────────────────────────────
CREATE TABLE courses (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  program_id      INT NULL,
  program         VARCHAR(100) NOT NULL,
  batch_year      VARCHAR(50) NULL,
  semester        VARCHAR(50),
  semester_number INT NULL,
  subject_name    VARCHAR(150) NOT NULL,
  course_code     VARCHAR(30),
  credits         INT NOT NULL DEFAULT 4,
  lecture_hours   INT NOT NULL DEFAULT 3,
  tutorial_hours  INT NOT NULL DEFAULT 0,
  practical_hours INT NOT NULL DEFAULT 2,
  ltp_pattern     VARCHAR(30) NOT NULL DEFAULT '4(3-0-2)',
  class_type      ENUM('T','P') NOT NULL   COMMENT 'T=Theory, P=Practical',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (program_id) REFERENCES academic_programs(id) ON DELETE CASCADE
);

-- ── 3. FACULTY COURSE ASSIGNMENTS (junction/bridge) ─────────
CREATE TABLE faculty_course_assignments (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id            INT NOT NULL,
  course_id             INT NOT NULL,
  assigned_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  faculty_name          VARCHAR(150),
  faculty_enrollment_no VARCHAR(20),
  course_name           VARCHAR(150),
  course_code           VARCHAR(30),
  FOREIGN KEY (faculty_id) REFERENCES faculty_members(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
  UNIQUE KEY uq_assignment (faculty_id, course_id)
);

-- ── 4. LECTURE ENTRIES ───────────────────────────────────────
CREATE TABLE lecture_entries (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id    INT NOT NULL,
  course_id     INT NOT NULL,
  lecture_date  DATE NOT NULL,
  hours         DECIMAL(4,1) NOT NULL,
  rate_per_hour DECIMAL(8,2) NOT NULL   COMMENT 'Calculated by PHP: 800 (T) or 400 (P)',
  amount        DECIMAL(10,2) NOT NULL  COMMENT 'Calculated by PHP: hours * rate_per_hour',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (faculty_id) REFERENCES faculty_members(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
  INDEX idx_faculty_course (faculty_id, course_id),
  INDEX idx_lecture_date (lecture_date)
);

-- ── 5. PAYMENT RECORDS (PDF tracking) ───────────────────────
CREATE TABLE payment_records (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id         INT NOT NULL,
  month              TINYINT NOT NULL   COMMENT '1-12',
  year               SMALLINT NOT NULL,
  total_hours        DECIMAL(6,1),
  total_amount       DECIMAL(10,2),
  date_of_submission DATE,
  generated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (faculty_id) REFERENCES faculty_members(id) ON DELETE CASCADE
);

-- ── 6. MONTHLY REPORT SUBMISSIONS (Permanent Snapshot) ──────
CREATE TABLE IF NOT EXISTS monthly_report_submissions (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id               INT NOT NULL,
  month                    TINYINT NOT NULL   COMMENT '1-12',
  year                     SMALLINT NOT NULL,
  submission_date          DATE NOT NULL,
  attendance_register_page VARCHAR(50) DEFAULT 'Page 02 - S.No. - 19',
  cheque_no                VARCHAR(50) NULL,
  theory_hours             DECIMAL(6,1) DEFAULT 0.0,
  tutorial_hours           DECIMAL(6,1) DEFAULT 0.0,
  practical_hours          DECIMAL(6,1) DEFAULT 0.0,
  total_hours              DECIMAL(6,1) DEFAULT 0.0,
  theory_rate              DECIMAL(8,2) DEFAULT 800.00,
  practical_rate           DECIMAL(8,2) DEFAULT 400.00,
  theory_amount            DECIMAL(10,2) DEFAULT 0.00,
  practical_amount         DECIMAL(10,2) DEFAULT 0.00,
  total_amount             DECIMAL(10,2) DEFAULT 0.00,
  programs_covered         TEXT NULL,
  status                   ENUM('draft', 'submitted', 'verified', 'paid') DEFAULT 'submitted',
  created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (faculty_id) REFERENCES faculty_members(id) ON DELETE CASCADE,
  UNIQUE KEY uq_faculty_month_year (faculty_id, month, year)
);

-- ── 7. PASSWORD RESET OTPS ──────────────────────────────────
CREATE TABLE IF NOT EXISTS password_reset_otps (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  user_type   ENUM('faculty', 'admin') NOT NULL,
  identifier  VARCHAR(150) NOT NULL,
  email       VARCHAR(150) NOT NULL,
  otp         VARCHAR(10) NOT NULL,
  expires_at  DATETIME NOT NULL,
  is_used     TINYINT DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_otp (user_type, identifier, otp, is_used)
);

-- ── 8. STUDENTS & ROSTERS ────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  program_id       INT NOT NULL,
  course_id        INT NULL,
  batch_year       VARCHAR(50) NULL,
  current_semester VARCHAR(50) NOT NULL,
  roll_no          VARCHAR(50) NOT NULL,
  enrollment_no    VARCHAR(50) NULL,
  student_name     VARCHAR(150) NOT NULL,
  status           ENUM('active','inactive') DEFAULT 'active',
  created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (program_id) REFERENCES academic_programs(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  UNIQUE KEY uq_prog_sem_roll (program_id, current_semester, roll_no),
  INDEX idx_prog_sem (program_id, current_semester)
);

-- ── 9. STUDENT ATTENDANCE ───────────────────────────────────
CREATE TABLE IF NOT EXISTS student_attendance (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  lecture_id      INT NOT NULL,
  student_id      INT NOT NULL,
  attendance_date DATE NOT NULL,
  status          ENUM('present','absent') DEFAULT 'present',
  remarks         VARCHAR(255) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (lecture_id) REFERENCES lecture_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  UNIQUE KEY uq_lecture_student (lecture_id, student_id),
  INDEX idx_student_att (student_id, attendance_date)
);


-- ── 10. ARCHIVED FACULTY RECORDS (Old Records / Backup) ───────
CREATE TABLE IF NOT EXISTS archived_faculty_records (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  original_faculty_id   INT NOT NULL,
  faculty_enrollment_no VARCHAR(20) NOT NULL,
  name                  VARCHAR(150) NOT NULL,
  email                 VARCHAR(150) NULL,
  phone                 VARCHAR(20) NULL,
  address               TEXT NULL,
  qualification         VARCHAR(200) NULL,
  department            VARCHAR(150) NULL,
  pan_no                VARCHAR(20) NULL,
  account_no            VARCHAR(30) NULL,
  bank_name             VARCHAR(100) NULL,
  ifsc_code             VARCHAR(20) NULL,
  aadhaar_no            VARCHAR(20) NULL,
  theory_rate           DECIMAL(8,2) NOT NULL DEFAULT 800.00,
  practical_rate        DECIMAL(8,2) NOT NULL DEFAULT 400.00,
  password_hash         VARCHAR(255) NULL,
  is_password_changed   TINYINT DEFAULT 0,
  faculty_data_json     LONGTEXT NOT NULL,
  courses_data_json     LONGTEXT NULL,
  lectures_data_json    LONGTEXT NULL,
  attendance_data_json  LONGTEXT NULL,
  reports_data_json     LONGTEXT NULL,
  payments_data_json    LONGTEXT NULL,
  total_lectures        INT DEFAULT 0,
  total_hours           DECIMAL(8,1) DEFAULT 0.0,
  total_amount          DECIMAL(10,2) DEFAULT 0.00,
  archived_by           VARCHAR(100) DEFAULT 'admin',
  archived_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  status                ENUM('archived', 'restored') DEFAULT 'archived',
  restored_at           TIMESTAMP NULL,
  INDEX idx_arch_enroll (faculty_enrollment_no),
  INDEX idx_arch_status (status)
);


-- ── SEED DATA: Academic Programs & Batches ───────────────────
INSERT INTO academic_programs (program_name, program_code, batch_year, total_semesters) VALUES
('M.Tech AI&DS',     'MTECH-AIDS', '2022-2027', 10),
('M.Tech BDA',       'MTECH-BDA',  '2025-2027', 4),
('M.Tech DS',        'MTECH-DS',   '2025-2027', 4),
('MBA BA',           'MBA-BA',     '2025-2027', 4),
('M.Sc DSA',         'MSC-DSA',    '2025-2027', 4),
('M.Tech Executive', 'MTECH-EXEC', '2025-2027', 4)
ON DUPLICATE KEY UPDATE program_name=VALUES(program_name), total_semesters=VALUES(total_semesters);

SELECT 'Schema created successfully' AS status;
SELECT COUNT(*) AS total_programs FROM academic_programs;
SELECT COUNT(*) AS total_courses FROM courses;
