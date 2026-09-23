<?php
// includes/archive_helper.php — Faculty Archive & Restoration Engine

/**
 * Ensures the archived_faculty_records table exists.
 */
function ensureArchiveTableExists(PDO $pdo): void {
    $sql = "CREATE TABLE IF NOT EXISTS archived_faculty_records (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    try {
        $pdo->exec($sql);
    } catch (Exception $e) {
        error_log("ensureArchiveTableExists error: " . $e->getMessage());
    }
}

/**
 * Archive a faculty member and all related records, then remove from active tables.
 */
function archiveFaculty(PDO $pdo, int $facultyId, string $archivedBy = 'admin'): array {
    ensureArchiveTableExists($pdo);

    // 1. Fetch Faculty Profile
    $stmt = $pdo->prepare("SELECT * FROM faculty_members WHERE id = ?");
    $stmt->execute([$facultyId]);
    $faculty = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$faculty) {
        return ['success' => false, 'message' => 'Faculty member not found.'];
    }

    // 2. Fetch Assigned Courses
    $stmt = $pdo->prepare("
        SELECT fca.*, c.subject_name, c.course_code, c.program, c.semester, c.class_type, c.batch_year
        FROM faculty_course_assignments fca
        JOIN courses c ON c.id = fca.course_id
        WHERE fca.faculty_id = ?
    ");
    $stmt->execute([$facultyId]);
    $assignedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch Lecture Entries
    $stmt = $pdo->prepare("
        SELECT le.*, c.subject_name, c.course_code, c.program, c.semester, c.class_type AS course_class_type, le.class_type
        FROM lecture_entries le
        JOIN courses c ON c.id = le.course_id
        WHERE le.faculty_id = ?
        ORDER BY le.lecture_date ASC, le.id ASC
    ");
    $stmt->execute([$facultyId]);
    $lectures = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lectureIds = array_column($lectures, 'id');

    // 4. Fetch Student Attendance records for these lectures
    $attendances = [];
    if (!empty($lectureIds)) {
        $inLec = implode(',', array_map('intval', $lectureIds));
        $stmt = $pdo->query("
            SELECT sa.*, s.roll_no, s.student_name, s.enrollment_no
            FROM student_attendance sa
            LEFT JOIN students s ON s.id = sa.student_id
            WHERE sa.lecture_id IN ({$inLec})
            ORDER BY sa.lecture_id ASC, s.roll_no ASC
        ");
        $attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 5. Fetch Monthly Report Submissions & Payment Records
    $stmt = $pdo->prepare("SELECT * FROM monthly_report_submissions WHERE faculty_id = ? ORDER BY year DESC, month DESC");
    $stmt->execute([$facultyId]);
    $monthlyReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM payment_records WHERE faculty_id = ? ORDER BY year DESC, month DESC");
    $stmt->execute([$facultyId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary calculations
    $totalLectures = count($lectures);
    $totalHours = 0.0;
    $totalAmount = 0.0;
    foreach ($lectures as $l) {
        $totalHours += (float)$l['hours'];
        $totalAmount += (float)$l['amount'];
    }

    // Execute atomic archive & deletion
    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare("
            INSERT INTO archived_faculty_records (
                original_faculty_id, faculty_enrollment_no, name, email, phone, address,
                qualification, department, pan_no, account_no, bank_name, ifsc_code, aadhaar_no,
                theory_rate, practical_rate, password_hash, is_password_changed,
                faculty_data_json, courses_data_json, lectures_data_json, attendance_data_json,
                reports_data_json, payments_data_json,
                total_lectures, total_hours, total_amount, archived_by, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?,
                ?, ?, ?, ?, 'archived'
            )
        ");

        $ins->execute([
            $faculty['id'],
            $faculty['faculty_enrollment_no'],
            $faculty['name'],
            $faculty['email'] ?? null,
            $faculty['phone'] ?? null,
            $faculty['address'] ?? null,
            $faculty['qualification'] ?? null,
            $faculty['department'] ?? null,
            $faculty['pan_no'] ?? null,
            $faculty['account_no'] ?? null,
            $faculty['bank_name'] ?? null,
            $faculty['ifsc_code'] ?? null,
            $faculty['aadhaar_no'] ?? null,
            $faculty['theory_rate'] ?? 800.00,
            $faculty['practical_rate'] ?? 400.00,
            $faculty['password'] ?? null,
            $faculty['is_password_changed'] ?? 0,
            json_encode($faculty, JSON_UNESCAPED_UNICODE),
            json_encode($assignedCourses, JSON_UNESCAPED_UNICODE),
            json_encode($lectures, JSON_UNESCAPED_UNICODE),
            json_encode($attendances, JSON_UNESCAPED_UNICODE),
            json_encode($monthlyReports, JSON_UNESCAPED_UNICODE),
            json_encode($payments, JSON_UNESCAPED_UNICODE),
            $totalLectures,
            $totalHours,
            $totalAmount,
            $archivedBy
        ]);

        $archiveId = (int)$pdo->lastInsertId();

        // Safely clean up active tables for this faculty member
        if (!empty($lectureIds)) {
            $inLec = implode(',', array_map('intval', $lectureIds));
            $pdo->exec("DELETE FROM student_attendance WHERE lecture_id IN ({$inLec})");
        }
        $pdo->prepare("DELETE FROM lecture_entries WHERE faculty_id = ?")->execute([$facultyId]);
        $pdo->prepare("DELETE FROM faculty_course_assignments WHERE faculty_id = ?")->execute([$facultyId]);
        $pdo->prepare("DELETE FROM monthly_report_submissions WHERE faculty_id = ?")->execute([$facultyId]);
        $pdo->prepare("DELETE FROM payment_records WHERE faculty_id = ?")->execute([$facultyId]);
        $pdo->prepare("DELETE FROM faculty_members WHERE id = ?")->execute([$facultyId]);

        $pdo->commit();

        return [
            'success'     => true,
            'archive_id'  => $archiveId,
            'name'        => $faculty['name'],
            'enrollment'  => $faculty['faculty_enrollment_no'],
            'total_lec'   => $totalLectures,
            'total_hrs'   => $totalHours,
            'total_amt'   => $totalAmount
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Restore an archived faculty member and all related records back into active tables.
 */
function restoreFaculty(PDO $pdo, int $archiveId): array {
    ensureArchiveTableExists($pdo);

    $stmt = $pdo->prepare("SELECT * FROM archived_faculty_records WHERE id = ?");
    $stmt->execute([$archiveId]);
    $archive = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$archive) {
        return ['success' => false, 'message' => 'Archive record not found.'];
    }

    $enrollmentNo = $archive['faculty_enrollment_no'];

    // Check if an active faculty already has this enrollment number
    $chk = $pdo->prepare("SELECT id, name FROM faculty_members WHERE faculty_enrollment_no = ?");
    $chk->execute([$enrollmentNo]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        return [
            'success' => false,
            'message' => "Cannot restore: An active faculty member (\"{$existing['name']}\") already exists with enrollment number {$enrollmentNo}."
        ];
    }

    $facultyData = json_decode($archive['faculty_data_json'] ?? '{}', true);
    $coursesData = json_decode($archive['courses_data_json'] ?? '[]', true);
    $lecturesData = json_decode($archive['lectures_data_json'] ?? '[]', true);
    $attendanceData = json_decode($archive['attendance_data_json'] ?? '[]', true);
    $reportsData = json_decode($archive['reports_data_json'] ?? '[]', true);
    $paymentsData = json_decode($archive['payments_data_json'] ?? '[]', true);

    try {
        $pdo->beginTransaction();

        // 1. Re-insert faculty member
        // Try to preserve original id if available and not taken
        $origId = (int)$archive['original_faculty_id'];
        $idCheck = $pdo->prepare("SELECT id FROM faculty_members WHERE id = ?");
        $idCheck->execute([$origId]);
        $targetId = ($idCheck->fetchColumn()) ? null : $origId;

        if ($targetId) {
            $insF = $pdo->prepare("
                INSERT INTO faculty_members (
                    id, faculty_enrollment_no, name, email, phone, address, qualification,
                    department, pan_no, account_no, bank_name, ifsc_code, aadhaar_no,
                    theory_rate, practical_rate, password, is_password_changed, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'active', ?
                )
            ");
            $insF->execute([
                $targetId,
                $archive['faculty_enrollment_no'],
                $archive['name'],
                $archive['email'] ?? $facultyData['email'] ?? null,
                $archive['phone'] ?? $facultyData['phone'] ?? null,
                $archive['address'] ?? $facultyData['address'] ?? null,
                $archive['qualification'] ?? $facultyData['qualification'] ?? null,
                $archive['department'] ?? $facultyData['department'] ?? 'SDSF',
                $archive['pan_no'] ?? $facultyData['pan_no'] ?? null,
                $archive['account_no'] ?? $facultyData['account_no'] ?? null,
                $archive['bank_name'] ?? $facultyData['bank_name'] ?? null,
                $archive['ifsc_code'] ?? $facultyData['ifsc_code'] ?? null,
                $archive['aadhaar_no'] ?? $facultyData['aadhaar_no'] ?? null,
                $archive['theory_rate'] ?? $facultyData['theory_rate'] ?? 800.00,
                $archive['practical_rate'] ?? $facultyData['practical_rate'] ?? 400.00,
                $archive['password_hash'] ?? $facultyData['password'] ?? null,
                $archive['is_password_changed'] ?? $facultyData['is_password_changed'] ?? 0,
                $facultyData['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $newFacultyId = $targetId;
        } else {
            $insF = $pdo->prepare("
                INSERT INTO faculty_members (
                    faculty_enrollment_no, name, email, phone, address, qualification,
                    department, pan_no, account_no, bank_name, ifsc_code, aadhaar_no,
                    theory_rate, practical_rate, password, is_password_changed, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, 'active', ?
                )
            ");
            $insF->execute([
                $archive['faculty_enrollment_no'],
                $archive['name'],
                $archive['email'] ?? $facultyData['email'] ?? null,
                $archive['phone'] ?? $facultyData['phone'] ?? null,
                $archive['address'] ?? $facultyData['address'] ?? null,
                $archive['qualification'] ?? $facultyData['qualification'] ?? null,
                $archive['department'] ?? $facultyData['department'] ?? 'SDSF',
                $archive['pan_no'] ?? $facultyData['pan_no'] ?? null,
                $archive['account_no'] ?? $facultyData['account_no'] ?? null,
                $archive['bank_name'] ?? $facultyData['bank_name'] ?? null,
                $archive['ifsc_code'] ?? $facultyData['ifsc_code'] ?? null,
                $archive['aadhaar_no'] ?? $facultyData['aadhaar_no'] ?? null,
                $archive['theory_rate'] ?? $facultyData['theory_rate'] ?? 800.00,
                $archive['practical_rate'] ?? $facultyData['practical_rate'] ?? 400.00,
                $archive['password_hash'] ?? $facultyData['password'] ?? null,
                $archive['is_password_changed'] ?? $facultyData['is_password_changed'] ?? 0,
                $facultyData['created_at'] ?? date('Y-m-d H:i:s')
            ]);
            $newFacultyId = (int)$pdo->lastInsertId();
        }

        // 2. Restore Course Assignments
        if (!empty($coursesData)) {
            $insAssign = $pdo->prepare("
                INSERT INTO faculty_course_assignments (
                    faculty_id, course_id, assigned_at, faculty_name,
                    faculty_enrollment_no, course_name, course_code
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE assigned_at = VALUES(assigned_at)
            ");
            foreach ($coursesData as $ca) {
                // Ensure course still exists in courses table
                $cChk = $pdo->prepare("SELECT id, subject_name, course_code FROM courses WHERE id = ?");
                $cChk->execute([(int)$ca['course_id']]);
                $cRow = $cChk->fetch(PDO::FETCH_ASSOC);
                if ($cRow) {
                    $insAssign->execute([
                        $newFacultyId,
                        (int)$ca['course_id'],
                        $ca['assigned_at'] ?? date('Y-m-d H:i:s'),
                        $archive['name'],
                        $archive['faculty_enrollment_no'],
                        $cRow['subject_name'],
                        $cRow['course_code']
                    ]);
                }
            }
        }

        // 3. Restore Lecture Entries & Map IDs
        $lectureIdMap = []; // old_lecture_id => new_lecture_id
        if (!empty($lecturesData)) {
            foreach ($lecturesData as $lec) {
                // Ensure course exists
                $cChk = $pdo->prepare("SELECT id FROM courses WHERE id = ?");
                $cChk->execute([(int)$lec['course_id']]);
                if (!$cChk->fetchColumn()) continue;

                $oldLecId = (int)$lec['id'];

                // Check if old lecture id can be retained
                $chkLec = $pdo->prepare("SELECT id FROM lecture_entries WHERE id = ?");
                $chkLec->execute([$oldLecId]);
                $canUseOld = !$chkLec->fetchColumn();

                $lecClassType = !empty($lec['class_type']) ? $lec['class_type'] : 'T';
                if ($canUseOld) {
                    $insLec = $pdo->prepare("
                        INSERT INTO lecture_entries (
                            id, faculty_id, course_id, lecture_date, hours, class_type, rate_per_hour, amount, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insLec->execute([
                        $oldLecId,
                        $newFacultyId,
                        (int)$lec['course_id'],
                        $lec['lecture_date'],
                        $lec['hours'],
                        $lecClassType,
                        $lec['rate_per_hour'],
                        $lec['amount'],
                        $lec['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                    $newLecId = $oldLecId;
                } else {
                    $insLec = $pdo->prepare("
                        INSERT INTO lecture_entries (
                            faculty_id, course_id, lecture_date, hours, class_type, rate_per_hour, amount, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insLec->execute([
                        $newFacultyId,
                        (int)$lec['course_id'],
                        $lec['lecture_date'],
                        $lec['hours'],
                        $lecClassType,
                        $lec['rate_per_hour'],
                        $lec['amount'],
                        $lec['created_at'] ?? date('Y-m-d H:i:s')
                    ]);
                    $newLecId = (int)$pdo->lastInsertId();
                }

                $lectureIdMap[$oldLecId] = $newLecId;
            }
        }

        // 4. Restore Student Attendance
        if (!empty($attendanceData)) {
            $insAtt = $pdo->prepare("
                INSERT INTO student_attendance (
                    lecture_id, student_id, attendance_date, status, remarks, created_at
                ) VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            foreach ($attendanceData as $att) {
                $oldLecId = (int)$att['lecture_id'];
                $restoredLecId = $lectureIdMap[$oldLecId] ?? null;
                if (!$restoredLecId) continue;

                // Ensure student still exists
                $stChk = $pdo->prepare("SELECT id FROM students WHERE id = ?");
                $stChk->execute([(int)$att['student_id']]);
                if (!$stChk->fetchColumn()) continue;

                $insAtt->execute([
                    $restoredLecId,
                    (int)$att['student_id'],
                    $att['attendance_date'],
                    $att['status'] ?? 'present',
                    $att['remarks'] ?? null,
                    $att['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        // 5. Restore Monthly Report Submissions
        if (!empty($reportsData)) {
            $insRep = $pdo->prepare("
                INSERT INTO monthly_report_submissions (
                    faculty_id, month, year, submission_date, attendance_register_page, cheque_no,
                    theory_hours, tutorial_hours, practical_hours, total_hours,
                    theory_rate, practical_rate, theory_amount, practical_amount, total_amount,
                    programs_covered, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    submission_date = VALUES(submission_date),
                    total_amount = VALUES(total_amount),
                    status = VALUES(status)
            ");
            foreach ($reportsData as $rep) {
                $insRep->execute([
                    $newFacultyId,
                    (int)$rep['month'],
                    (int)$rep['year'],
                    $rep['submission_date'] ?? date('Y-m-d'),
                    $rep['attendance_register_page'] ?? 'Page 02 - S.No. - 19',
                    $rep['cheque_no'] ?? null,
                    $rep['theory_hours'] ?? 0,
                    $rep['tutorial_hours'] ?? 0,
                    $rep['practical_hours'] ?? 0,
                    $rep['total_hours'] ?? 0,
                    $rep['theory_rate'] ?? 800,
                    $rep['practical_rate'] ?? 400,
                    $rep['theory_amount'] ?? 0,
                    $rep['practical_amount'] ?? 0,
                    $rep['total_amount'] ?? 0,
                    $rep['programs_covered'] ?? null,
                    $rep['status'] ?? 'submitted',
                    $rep['created_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        // 6. Restore Payment Records
        if (!empty($paymentsData)) {
            $insPay = $pdo->prepare("
                INSERT INTO payment_records (
                    faculty_id, month, year, total_hours, total_amount, date_of_submission, generated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($paymentsData as $pay) {
                $insPay->execute([
                    $newFacultyId,
                    (int)$pay['month'],
                    (int)$pay['year'],
                    $pay['total_hours'] ?? 0,
                    $pay['total_amount'] ?? 0,
                    $pay['date_of_submission'] ?? null,
                    $pay['generated_at'] ?? date('Y-m-d H:i:s')
                ]);
            }
        }

        // 7. Update Archive status
        $updArch = $pdo->prepare("
            UPDATE archived_faculty_records 
            SET status = 'restored', restored_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $updArch->execute([$archiveId]);

        $pdo->commit();

        return [
            'success'    => true,
            'faculty_id' => $newFacultyId,
            'name'       => $archive['name'],
            'enrollment' => $archive['faculty_enrollment_no']
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
