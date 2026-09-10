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
  id          INT AUTO_INCREMENT PRIMARY KEY,
  faculty_id  INT NOT NULL,
  course_id   INT NOT NULL,
  assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  FOREIGN KEY (faculty_id) REFERENCES faculty_members(id)
);

-- ── SEED DATA: Sample Courses ────────────────────────────────
INSERT INTO courses (program, semester, subject_name, course_code, class_type) VALUES
('M.Sc',          '1st Semester', 'ADMS',                          'ADMS-101',   'T'),
('MBA',           '1st Semester', 'Business Analytics',             'MBA-BA-201', 'P'),
('M.Tech AI&DS',  '1st Semester', 'DAA',                           'DAA-301',    'T'),
('M.Tech AI&DS',  '3rd Semester', 'DAA (Theory)',                  'DAA-302T',   'T'),
('M.Tech AI&DS',  '3rd Semester', 'DAA (Practical)',               'DAA-302P',   'P'),
('MBA(MS)',        '1st Semester', 'Quantitative Techniques for Business', 'FT-110A', 'T'),
('M.Tech AI&DS',  '2nd Semester', 'Machine Learning',              'ML-401',     'T'),
('B.Sc',          '1st Semester', 'Python Programming',            'PY-101',     'P');

SELECT 'Schema created successfully' AS status;
SELECT COUNT(*) AS total_courses FROM courses;
