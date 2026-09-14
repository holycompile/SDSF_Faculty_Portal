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

-- ── 2. COURSES ───────────────────────────────────────────────
CREATE TABLE courses (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  program      VARCHAR(100) NOT NULL,
  semester     VARCHAR(50),
  subject_name VARCHAR(150) NOT NULL,
  course_code  VARCHAR(30),
  class_type   ENUM('T','P') NOT NULL   COMMENT 'T=Theory Rs800/hr, P=Practical Rs400/hr',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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

-- ── SEED DATA: M.Tech AI&DS 9-Semester Courses ───────────────
INSERT INTO courses (program, semester, subject_name, course_code, class_type) VALUES
-- 1st Semester
('M.Tech AI&DS', '1st Semester', 'Advanced Data Structures & Algorithms', 'MT-101', 'T'),
('M.Tech AI&DS', '1st Semester', 'Mathematical Foundations of Data Science', 'MT-102', 'T'),
('M.Tech AI&DS', '1st Semester', 'Python Programming for AI Lab', 'MT-103P', 'P'),
-- 2nd Semester
('M.Tech AI&DS', '2nd Semester', 'Machine Learning Techniques', 'MT-201', 'T'),
('M.Tech AI&DS', '2nd Semester', 'Advanced Database Management Systems', 'MT-202', 'T'),
('M.Tech AI&DS', '2nd Semester', 'Machine Learning Lab', 'MT-203P', 'P'),
-- 3rd Semester
('M.Tech AI&DS', '3rd Semester', 'Deep Learning Architectures', 'MT-301', 'T'),
('M.Tech AI&DS', '3rd Semester', 'Natural Language Processing', 'MT-302', 'T'),
('M.Tech AI&DS', '3rd Semester', 'Deep Learning & NLP Lab', 'MT-303P', 'P'),
-- 4th Semester
('M.Tech AI&DS', '4th Semester', 'Big Data Analytics & Engineering', 'MT-401', 'T'),
('M.Tech AI&DS', '4th Semester', 'Computer Vision', 'MT-402', 'T'),
('M.Tech AI&DS', '4th Semester', 'Big Data Analytics Lab', 'MT-403P', 'P'),
-- 5th Semester
('M.Tech AI&DS', '5th Semester', 'Reinforcement Learning & Optimization', 'MT-501', 'T'),
('M.Tech AI&DS', '5th Semester', 'Cloud Computing & MLOps', 'MT-502', 'T'),
('M.Tech AI&DS', '5th Semester', 'Cloud AI Deployment Lab', 'MT-503P', 'P'),
-- 6th Semester
('M.Tech AI&DS', '6th Semester', 'AI in IoT & Healthcare', 'MT-601', 'T'),
('M.Tech AI&DS', '6th Semester', 'Research Methodology & Ethics', 'MT-602', 'T'),
('M.Tech AI&DS', '6th Semester', 'Applied AI Capstone Lab', 'MT-603P', 'P'),
-- 7th Semester
('M.Tech AI&DS', '7th Semester', 'Data Mining & Knowledge Discovery', 'MT-701', 'T'),
('M.Tech AI&DS', '7th Semester', 'Information Retrieval', 'MT-702', 'T'),
('M.Tech AI&DS', '7th Semester', 'Minor Project & Seminar', 'MT-703P', 'P'),
-- 8th Semester
('M.Tech AI&DS', '8th Semester', 'Industrial Training & Research Seminar', 'MT-801', 'T'),
('M.Tech AI&DS', '8th Semester', 'Major Project Phase-I Lab', 'MT-802P', 'P'),
-- 9th Semester
('M.Tech AI&DS', '9th Semester', 'Comprehensive Viva & Defense', 'MT-901', 'T'),
('M.Tech AI&DS', '9th Semester', 'Major Project Phase-II / Dissertation', 'MT-902P', 'P');

SELECT 'Schema created successfully' AS status;
SELECT COUNT(*) AS total_courses FROM courses;
