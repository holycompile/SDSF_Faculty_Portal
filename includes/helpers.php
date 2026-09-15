<?php
// ─── Number to Words (Indian numbering) ──────────────────────────────────────
function numberToWords(float $num): string {
    $num = (int) round($num);
    if ($num === 0) return 'Zero';
    $ones  = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
               'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
               'Seventeen','Eighteen','Nineteen'];
    $tens  = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    function words($n, $ones, $tens) {
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[(int)($n/10)] . ($n%10 ? ' '.$ones[$n%10] : '');
        return $ones[(int)($n/100)] . ' Hundred' . ($n%100 ? ' '.words($n%100,$ones,$tens) : '');
    }
    $parts = [];
    if ($num >= 10000000) { $parts[] = words((int)($num/10000000),$ones,$tens).' Crore'; $num %= 10000000; }
    if ($num >= 100000)   { $parts[] = words((int)($num/100000),$ones,$tens).' Lakh';   $num %= 100000; }
    if ($num >= 1000)     { $parts[] = words((int)($num/1000),$ones,$tens).' Thousand'; $num %= 1000; }
    if ($num > 0)         { $parts[] = words($num,$ones,$tens); }
    return implode(' ', $parts) . ' Rupees Only';
}

// ─── Format currency ─────────────────────────────────────────────────────────
function formatCurrency(float $amount): string {
    return 'Rs.' . number_format($amount, 2);
}

// ─── Generate faculty enrollment number ──────────────────────────────────────
function generateEnrollmentNo(string $name, PDO $pdo): string {
    $clean  = preg_replace('/[^A-Za-z]/', '', $name);
    $prefix = strtoupper(substr($clean, 0, 4));
    if (strlen($prefix) < 4) {
        $prefix = str_pad($prefix, 4, 'X');
    }

    // Find the highest existing numeric suffix for this prefix
    $stmt = $pdo->prepare("SELECT faculty_enrollment_no FROM faculty_members WHERE faculty_enrollment_no LIKE ?");
    $stmt->execute([$prefix . '%']);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxNum = 0;
    foreach ($existing as $enrollment) {
        $numPart = substr($enrollment, strlen($prefix));
        if (is_numeric($numPart)) {
            $val = (int)$numPart;
            if ($val > $maxNum) {
                $maxNum = $val;
            }
        }
    }

    $nextNum = $maxNum + 1;
    // Guaranteed uniqueness: loop until a non-colliding number is found
    do {
        $candidate = $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare("SELECT COUNT(*) FROM faculty_members WHERE faculty_enrollment_no = ?");
        $check->execute([$candidate]);
        if ((int)$check->fetchColumn() === 0) {
            return $candidate;
        }
        $nextNum++;
    } while (true);
}

// ─── Month name ──────────────────────────────────────────────────────────────
function monthName(int $m): string {
    return date('F', mktime(0,0,0,$m,1));
}

// ─── Class type label ────────────────────────────────────────────────────────
function classTypeLabel(string $t): string {
    return $t === 'T' ? 'Theory' : 'Practical';
}

// ─── Flash message helpers ───────────────────────────────────────────────────
function setFlash(string $type, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash(): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ─── Get Dedicated Student Table Name ─────────────────────────────────────────
function getCohortStudentTable(string $progNameOrCode, $semester): string {
    $semNum = (int)filter_var((string)$semester, FILTER_SANITIZE_NUMBER_INT) ?: 1;
    $norm = strtolower($progNameOrCode);
    $norm = str_replace(['&', ' '], ['_', '_'], $norm);
    $norm = preg_replace('/[^a-z0-9_]/', '', $norm);
    $norm = preg_replace('/_+/', '_', $norm);
    $norm = trim($norm, '_');

    if (str_contains($norm, 'aids') || str_contains($norm, 'ai_ds')) {
        $prefix = 'mtech_aids';
    } elseif (str_contains($norm, 'bda')) {
        $prefix = 'mtech_bda';
    } elseif (str_contains($norm, 'exec')) {
        $prefix = 'mtech_exec';
    } elseif (str_contains($norm, 'dsa') || str_contains($norm, 'msc')) {
        $prefix = 'msc_dsa';
    } elseif (str_contains($norm, 'mba') || str_contains($norm, 'ba')) {
        $prefix = 'mba_ba';
    } elseif (str_contains($norm, 'ds')) {
        $prefix = 'mtech_ds';
    } else {
        $prefix = $norm;
    }
    return "students_{$prefix}_sem{$semNum}";
}

// ─── Get Dynamic Attendance Columns in Cohort Table ─────────────────────────
function getCohortAttendanceColumns(PDO $pdo, string $tableName): array {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'att_%'");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

// ─── Record Attendance Directly into Dedicated Cohort Table ─────────────────
function recordCohortAttendance(
    PDO $pdo,
    string $progName,
    $semester,
    string $date,
    array $attendanceData,
    ?int $lectureId = null,
    ?int $courseId = null
): string {
    $table = getCohortStudentTable($progName, $semester);

    // Verify table exists
    try {
        $chk = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
        if (!$chk) {
            return '';
        }
    } catch (Exception $e) {
        return '';
    }

    // Format target column name per Option 2 (e.g. att_2026_09_15)
    $datePart = date('Y_m_d', strtotime($date));
    $baseCol = "att_{$datePart}";
    $targetCol = $baseCol;

    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array($baseCol, $cols)) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$baseCol}` TINYINT(1) NOT NULL DEFAULT 0");
            $targetCol = $baseCol;
        } else {
            // Check if there is collision with another course lecture on the same date
            if ($courseId && $lectureId) {
                $otherLec = $pdo->prepare("
                    SELECT id FROM lecture_entries 
                    WHERE lecture_date = ? AND id != ? AND course_id != ? 
                    LIMIT 1
                ");
                $otherLec->execute([$date, $lectureId, $courseId]);
                if ($otherLec->fetchColumn()) {
                    $candCol = "att_{$datePart}_c{$courseId}";
                    if (!in_array($candCol, $cols)) {
                        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$candCol}` TINYINT(1) NOT NULL DEFAULT 0");
                    }
                    $targetCol = $candCol;
                }
            }
        }

        // Map attendanceData keys (student_id or roll_no) to roll_no
        $stIds = array_filter(array_keys($attendanceData), 'is_numeric');
        $rollMap = [];
        if (!empty($stIds)) {
            $inClause = implode(',', array_map('intval', $stIds));
            $rRows = $pdo->query("SELECT id, roll_no FROM students WHERE id IN ({$inClause})")->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($attendanceData as $k => $val) {
                if (isset($rRows[$k])) {
                    $rollMap[$rRows[$k]] = $val;
                } else {
                    $rollMap[$k] = $val;
                }
            }
        } else {
            $rollMap = $attendanceData;
        }

        // Update attendance values (1 for present, 0 for absent) in the dedicated table
        $upd = $pdo->prepare("UPDATE `{$table}` SET `{$targetCol}` = ? WHERE roll_no = ?");
        foreach ($rollMap as $roll => $status) {
            $val = ($status === 'present' || $status === 1 || $status === '1') ? 1 : 0;
            $upd->execute([$val, $roll]);
        }

        return $targetCol;
    } catch (Exception $e) {
        error_log("recordCohortAttendance error for {$table}: " . $e->getMessage());
        return '';
    }
}

// ─── Get Cohort Attendance Matrix Data for Table Cards ────────────────────────
function getCohortAttendanceMatrix(PDO $pdo, string $progName, $semester, ?int $courseId = null): ?array {
    $table = getCohortStudentTable($progName, $semester);
    try {
        $chk = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
        if (!$chk) return null;

        $allCols = getCohortAttendanceColumns($pdo, $table);
        $attCols = [];

        if ($courseId) {
            $courseSpecificPrefix = "_c{$courseId}";
            foreach ($allCols as $col) {
                // If column has specific course suffix or is a general date column
                if (str_ends_with($col, $courseSpecificPrefix) || !preg_match('/_c\d+$/', $col)) {
                    $attCols[] = $col;
                }
            }
        } else {
            $attCols = $allCols;
        }

        // Fetch student records from dedicated physical table
        $stmt = $pdo->query("SELECT * FROM `{$table}` ORDER BY CAST(roll_no AS UNSIGNED) ASC, roll_no ASC, student_name ASC");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate student summaries
        $totalPresentAll = 0;
        $totalSlotsAll = 0;

        foreach ($students as &$st) {
            $pCount = 0;
            $aCount = 0;
            foreach ($attCols as $col) {
                $val = $st[$col] ?? null;
                if ($val === 1 || $val === '1') {
                    $pCount++;
                } elseif ($val === 0 || $val === '0') {
                    $aCount++;
                }
            }
            $st['present_count'] = $pCount;
            $st['absent_count']  = $aCount;
            $st['total_classes'] = count($attCols);
            $st['attendance_pct'] = (count($attCols) > 0) ? round(($pCount / count($attCols)) * 100, 1) : 0;

            $totalPresentAll += $pCount;
            $totalSlotsAll += count($attCols);
        }
        unset($st);

        $avgRate = ($totalSlotsAll > 0) ? round(($totalPresentAll / $totalSlotsAll) * 100, 1) : 0;

        return [
            'table_name'     => $table,
            'attendance_cols'=> $attCols,
            'students'       => $students,
            'total_sessions' => count($attCols),
            'total_students' => count($students),
            'avg_attendance' => $avgRate
        ];
    } catch (Exception $e) {
        error_log("getCohortAttendanceMatrix error: " . $e->getMessage());
        return null;
    }
}

