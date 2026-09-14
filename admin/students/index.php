<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

setFlash('warning', 'Student Roster module is currently disabled.');
header('Location: ' . BASE_URL . '/admin/courses/list.php');
exit;
$programs = $pStmt->fetchAll();

$progMap = [];
foreach ($programs as $p) {
    $progMap[$p['id']] = $p;
    $progMap[$p['program_name']] = $p;
}

// Active program selection
$activeProgName = trim($_GET['program'] ?? '');
$activeProg = null;

if (!empty($activeProgName) && isset($progMap[$activeProgName])) {
    $activeProg = $progMap[$activeProgName];
} else {
    $activeProg = $programs[0] ?? null;
}

$progId = $activeProg ? (int)$activeProg['id'] : 0;
$progName = $activeProg ? $activeProg['program_name'] : '';
$batchYear = $activeProg ? $activeProg['batch_year'] : '';
$totalSems = $activeProg ? (int)$activeProg['total_semesters'] : 4;

// Filter semester
$filterSem = trim($_GET['semester'] ?? 'all');

// Build query
$query = "
    SELECT s.*, ap.program_name, ap.batch_year AS prog_batch
    FROM students s
    JOIN academic_programs ap ON ap.id = s.program_id
    WHERE s.program_id = ?
";
$params = [$progId];

if ($filterSem !== 'all' && !empty($filterSem)) {
    $query .= " AND (s.current_semester = ? OR s.current_semester = ?)";
    $semNum = (int)filter_var($filterSem, FILTER_SANITIZE_NUMBER_INT);
    $params[] = $filterSem;
    $params[] = (string)$semNum;
}

$query .= " ORDER BY CAST(s.current_semester AS UNSIGNED) ASC, s.current_semester ASC, CAST(s.roll_no AS UNSIGNED) ASC, s.roll_no ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Count per semester for tabs
$countStmt = $pdo->prepare("
    SELECT current_semester, COUNT(*) AS count
    FROM students
    WHERE program_id = ?
    GROUP BY current_semester
");
$countStmt->execute([$progId]);
$semCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$totalStudentsCount = array_sum($semCounts);

// Fetch semester year tags for this program
$tagStmt = $pdo->prepare("SELECT semester_number, year_tag FROM semester_tags WHERE program_id = ?");
$tagStmt->execute([$progId]);
$semesterYearTags = $tagStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$active_nav = 'students-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($progName) ?> Student Rosters — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.prog-pills-bar {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 12px;
    margin-bottom: 22px;
    border-bottom: 1.5px solid #e2e8f0;
}
.prog-pill {
    padding: 10px 18px;
    border-radius: 12px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    color: #334155;
    font-size: 13.5px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .2s ease;
}
.prog-pill:hover {
    border-color: #c7d2fe;
    color: #4f46e5;
    background: #f8fafc;
}
.prog-pill.active {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
    box-shadow: 0 6px 18px rgba(79,70,229,0.28);
}
.prog-pill .year-tag {
    font-size: 11px;
    background: rgba(255,255,255,0.25);
    padding: 2px 7px;
    border-radius: 6px;
}
.prog-pill:not(.active) .year-tag {
    background: #f1f5f9;
    color: #64748b;
}

.sem-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 8px;
    margin-bottom: 22px;
}
.sem-tab-btn {
    padding: 8px 16px;
    border-radius: 10px;
    border: 1.5px solid #e2e8f0;
    background: #ffffff;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s;
    white-space: nowrap;
}
.sem-tab-btn:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.sem-tab-btn.active {
    background: #1e3a8a;
    color: #ffffff;
    border-color: #1e3a8a;
}
.sem-tab-count {
    font-size: 11px;
    padding: 1px 7px;
    border-radius: 10px;
    background: rgba(0,0,0,0.08);
}
.sem-tab-btn.active .sem-tab-count {
    background: rgba(255,255,255,0.28);
}

.roll-avatar {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: #eef2ff;
    color: #4338ca;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
    font-weight: 800;
}
</style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Students</span>
            <span class="tb-sep">/</span>
            <span class="tb-crumb"><?= htmlspecialchars($progName) ?> Cohort Rosters</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/admin/students/attendance.php" class="btn btn-outline btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                Attendance Logs
            </a>
            <a href="<?= BASE_URL ?>/admin/students/bulk_import.php?program_id=<?= $progId ?>" class="btn btn-outline btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Bulk Import
            </a>
            <a href="<?= BASE_URL ?>/admin/students/add.php?program_id=<?= $progId ?>" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                + Add Student
            </a>
        </div>
    </header>

    <div class="page">
        <!-- Page Title & Metrics -->
        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h1>Student Cohort Rosters</h1>
                <p>Manage student enrollments and rosters by academic program, batch tag, and semester.</p>
            </div>
            <div style="display:flex;gap:10px;">
                <span class="badge badge-blue" style="font-size:13px;padding:6px 14px;">
                    Total Enrolled: <?= $totalStudentsCount ?> Students
                </span>
            </div>
        </div>

        <?php $f = getFlash(); if ($f): ?>
            <div class="alert alert-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'warning' ? 'info' : 'error') ?> fade-up">
                <?= $f['message'] ?>
            </div>
        <?php endif; ?>

        <!-- Program Selector Tabs -->
        <div class="prog-pills-bar fade-up">
            <?php foreach ($programs as $prg): ?>
                <?php $isActive = ($prg['id'] == $progId); ?>
                <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($prg['program_name']) ?>"
                   class="prog-pill <?= $isActive ? 'active' : '' ?>">
                    <span><?= htmlspecialchars($prg['program_name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Semester Tabs -->
        <div class="sem-tabs fade-up">
            <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=all"
               class="sem-tab-btn <?= $filterSem === 'all' ? 'active' : '' ?>">
                <span>All Semesters</span>
                <span class="sem-tab-count"><?= $totalStudentsCount ?></span>
            </a>
            <?php for ($s = 1; $s <= $totalSems; $s++): 
                $suf = 'th';
                if ($s === 1) $suf = 'st';
                elseif ($s === 2) $suf = 'nd';
                elseif ($s === 3) $suf = 'rd';
                $sName = "{$s}{$suf} Semester";
                $c = $semCounts[$sName] ?? ($semCounts[(string)$s] ?? 0);
                $isSemActive = ($filterSem === $sName || $filterSem == (string)$s);
            ?>
                <a href="<?= BASE_URL ?>/admin/students/index.php?program=<?= urlencode($progName) ?>&semester=<?= urlencode($sName) ?>"
                   class="sem-tab-btn <?= $isSemActive ? 'active' : '' ?>">
                    <span>Sem <?= $s ?></span>
                    <span class="sem-tab-count"><?= $c ?></span>
                </a>
            <?php endfor; ?>
        </div>

        <!-- Search Bar -->
        <div class="card fade-up" style="margin-bottom:20px;padding:12px 18px;">
            <div style="display:flex;align-items:center;gap:10px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="studentSearch" placeholder="Search students in <?= htmlspecialchars($progName) ?> by name, roll no, or enrollment no..."
                       oninput="searchStudents()"
                       style="border:none;outline:none;font-size:14px;width:100%;background:transparent;color:#0f172a;">
            </div>
        </div>

        <!-- Student Roster Table -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title"><?= htmlspecialchars($progName) ?> — <?= $filterSem === 'all' ? 'All Semesters' : htmlspecialchars($filterSem) ?></div>
                    <div class="card-sub">Showing <?= count($students) ?> enrolled student<?= count($students) === 1 ? '' : 's' ?></div>
                </div>
                <div>
                    <a href="<?= BASE_URL ?>/admin/courses/list.php?program=<?= urlencode($progName) ?>" class="btn btn-outline btn-sm">
                        View Curriculum &rarr;
                    </a>
                </div>
            </div>

            <?php if (empty($students)): ?>
                <div style="padding:60px 20px;text-align:center;">
                    <div style="font-size:36px;margin-bottom:12px;">🎓</div>
                    <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 6px;">No Students Enrolled Here Yet</h3>
                    <p style="font-size:14px;color:#64748b;margin:0 0 16px;">Add individual students or bulk-import the cohort roster for this semester.</p>
                    <div style="display:flex;gap:10px;justify-content:center;">
                        <a href="<?= BASE_URL ?>/admin/students/add.php?program_id=<?= $progId ?>&semester=<?= urlencode($filterSem !== 'all' ? $filterSem : '1st Semester') ?>" class="btn btn-primary btn-sm">
                            + Add Student
                        </a>
                        <a href="<?= BASE_URL ?>/admin/students/bulk_import.php?program_id=<?= $progId ?>&semester=<?= urlencode($filterSem !== 'all' ? $filterSem : '1st Semester') ?>" class="btn btn-outline btn-sm">
                            Bulk Import Roster
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <table class="dt" id="studentsTable">
                    <thead>
                        <tr>
                            <th style="width:70px;">Roll</th>
                            <th style="width:140px;">Enrollment No</th>
                            <th>Student Name</th>
                            <th>Program &amp; Batch</th>
                            <th>Semester</th>
                            <th>Status</th>
                            <th style="text-align:right;width:150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $st): ?>
                        <tr data-name="<?= strtolower(htmlspecialchars($st['student_name'])) ?>"
                            data-roll="<?= strtolower(htmlspecialchars($st['roll_no'])) ?>"
                            data-enroll="<?= strtolower(htmlspecialchars($st['enrollment_no'])) ?>">
                            <td>
                                <span class="roll-avatar"><?= htmlspecialchars($st['roll_no']) ?></span>
                            </td>
                            <td>
                                <span style="font-family:monospace;font-size:12.5px;color:#475569;font-weight:600;"><?= htmlspecialchars($st['enrollment_no']) ?></span>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= htmlspecialchars($st['student_name']) ?></div>
                            </td>
                            <td>
                                <span style="font-size:13px;font-weight:600;color:#334155;"><?= htmlspecialchars($st['program_name']) ?></span>
                            </td>
                            <td>
                                <div style="display:inline-flex;align-items:center;gap:6px;">
                                    <span style="font-size:12px;font-weight:700;color:#4f46e5;background:#eef2ff;padding:3px 9px;border-radius:6px;">
                                        <?= htmlspecialchars($st['current_semester']) ?>
                                    </span>
                                    <?php if (!empty($st['batch_year'])): ?>
                                        <span style="font-size:11px;background:#f8fafc;color:#475569;padding:2px 7px;border-radius:4px;border:1px solid #e2e8f0;font-weight:600;">
                                            <?= htmlspecialchars($st['batch_year']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if ($st['status'] === 'active'): ?>
                                    <span class="badge badge-green">&#10003; Active</span>
                                <?php else: ?>
                                    <span class="badge badge-red">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:6px;">
                                    <a href="<?= BASE_URL ?>/admin/students/edit.php?id=<?= $st['id'] ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">
                                        Edit
                                    </a>
                                    <button type="button" class="btn btn-danger btn-sm" style="padding:4px 10px;font-size:12px;" onclick="confirmDelete(<?= $st['id'] ?>, '<?= htmlspecialchars(addslashes($st['student_name'])) ?>')">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:9999;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:18px;max-width:440px;width:100%;padding:28px;box-shadow:0 20px 40px rgba(0,0,0,0.2);">
        <h3 style="font-size:18px;font-weight:800;color:#0f172a;margin:0 0 8px;">Delete Student Record</h3>
        <p style="font-size:14px;color:#64748b;margin:0 0 20px;" id="delPrompt">Are you sure you want to remove this student from the cohort?</p>
        <form method="POST" action="<?= BASE_URL ?>/admin/students/delete.php">
            <input type="hidden" name="id" id="delStudentId" value="">
            <input type="hidden" name="return_url" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-outline" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn btn-danger">Confirm Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function searchStudents() {
    const q = document.getElementById('studentSearch').value.trim().toLowerCase();
    const rows = document.querySelectorAll('#studentsTable tbody tr');
    rows.forEach(r => {
        const name = r.getAttribute('data-name') || '';
        const roll = r.getAttribute('data-roll') || '';
        const enroll = r.getAttribute('data-enroll') || '';
        if (!q || name.includes(q) || roll.includes(q) || enroll.includes(q)) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

function confirmDelete(id, name) {
    document.getElementById('delStudentId').value = id;
    document.getElementById('delPrompt').innerHTML = `Are you sure you want to remove <strong>${name}</strong>? Any past attendance links for this student will also be removed.`;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
</script>
</body>
</html>
