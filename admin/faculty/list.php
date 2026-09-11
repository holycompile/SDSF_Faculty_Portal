<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$flash = getFlash();

$sql = "SELECT f.*, 
        COALESCE(ca.course_count, 0) AS course_count,
        COALESCE(le.total_hours, 0) AS total_hours,
        COALESCE(le.total_earnings, 0) AS total_earnings
        FROM faculty_members f
        LEFT JOIN (
            SELECT faculty_id, COUNT(DISTINCT course_id) AS course_count
            FROM faculty_course_assignments
            GROUP BY faculty_id
        ) ca ON ca.faculty_id = f.id
        LEFT JOIN (
            SELECT faculty_id, SUM(hours) AS total_hours, SUM(amount) AS total_earnings
            FROM lecture_entries
            GROUP BY faculty_id
        ) le ON le.faculty_id = f.id
        ORDER BY f.created_at DESC";
$facultyList = $pdo->query($sql)->fetchAll();

$active_nav = 'faculty-list';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Members — SDSF Admin</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
    <?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <a href="<?= BASE_URL ?>/admin/dashboard.php" style="color:#94a3b8;text-decoration:none;">Dashboard</a>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Faculty Members</span>
        </div>
        <div class="tb-right">
            <a href="<?= BASE_URL ?>/admin/faculty/register.php" class="btn btn-primary btn-sm">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Register Faculty
            </a>
        </div>
    </header>

    <div class="page">
        <div class="page-header fade-up" style="display:flex;align-items:flex-end;justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h1>Faculty Members</h1>
                <p>Manage visiting faculty, view profiles, course assignments, and generate remuneration bills (<?= count($facultyList) ?> total)</p>
            </div>
            <div>
                <input type="text" id="facultySearch" placeholder="Search by name, enroll no, dept..." class="form-input" style="width:280px;padding:9px 14px;font-size:13.5px;" oninput="filterFaculty()">
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] ?> fade-up">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Registered Faculty Directory</div>
                    <div class="card-sub">Click on a faculty member to view full profile, lecture history, and generate PDF bills</div>
                </div>
                <span class="c-badge"><?= count($facultyList) ?> Members</span>
            </div>

            <?php if (empty($facultyList)): ?>
                <div style="padding:60px 20px;text-align:center;color:#94a3b8;">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 16px;display:block;opacity:.35">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <div style="font-weight:700;font-size:16px;color:#334155;margin-bottom:6px;">No faculty members registered yet</div>
                    <p style="font-size:14px;margin-bottom:20px;color:#94a3b8;">Add your first visiting faculty member to start assigning courses and tracking lectures.</p>
                    <a href="<?= BASE_URL ?>/admin/faculty/register.php" class="btn btn-primary">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Register New Faculty
                    </a>
                </div>
            <?php else: ?>
                <table class="dt" id="facultyTable">
                    <thead>
                        <tr>
                            <th>Faculty Name</th>
                            <th>Enrollment No</th>
                            <th>Department</th>
                            <th>Contact</th>
                            <th>Courses</th>
                            <th>Total Lectures/Hrs</th>
                            <th>Total Earned</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facultyList as $f): 
                            $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $f['name']), 0, 2));
                        ?>
                        <tr class="faculty-row" data-search="<?= strtolower(htmlspecialchars($f['name'].' '.$f['faculty_enrollment_no'].' '.$f['department'].' '.$f['email'].' '.$f['phone'])) ?>">
                            <td>
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#e0e7ff,#c7d2fe);color:#4338ca;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <?= $initials ?>
                                    </div>
                                    <div>
                                        <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $f['id'] ?>" style="font-weight:600;color:#0f172a;text-decoration:none;font-size:14.5px;" class="hover:underline">
                                            <?= htmlspecialchars($f['name']) ?>
                                        </a>
                                        <div style="font-size:12px;color:#94a3b8;margin-top:2px;">
                                            <?= htmlspecialchars($f['qualification'] ?? 'Visiting Faculty') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span style="font-family:monospace;font-size:12.5px;font-weight:600;background:#eef2ff;color:#4f46e5;padding:4px 10px;border-radius:6px;border:1px solid #c7d2fe;">
                                    <?= htmlspecialchars($f['faculty_enrollment_no']) ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size:13.5px;color:#475569;font-weight:500;">
                                    <?= htmlspecialchars($f['department']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-size:13px;color:#334155;"><?= htmlspecialchars($f['phone']) ?></div>
                                <div style="font-size:11.5px;color:#94a3b8;"><?= htmlspecialchars($f['email'] ?: 'No email') ?></div>
                            </td>
                            <td>
                                <span class="badge badge-blue">
                                    <?= $f['course_count'] ?> course<?= $f['course_count'] != 1 ? 's' : '' ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight:600;color:#334155;"><?= (float)$f['total_hours'] ?> hrs</span>
                            </td>
                            <td>
                                <span style="font-weight:700;color:#047857;">&#8377;<?= number_format((float)$f['total_earnings'], 2) ?></span>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:6px;">
                                    <a href="<?= BASE_URL ?>/admin/faculty/view.php?id=<?= $f['id'] ?>" class="btn btn-outline btn-sm" title="View Profile &amp; History">
                                        View
                                    </a>
                                    <a href="<?= BASE_URL ?>/admin/reports/annexure_iv.php?faculty_id=<?= $f['id'] ?>" target="_blank" class="btn btn-primary btn-sm" title="Generate Official Annexure-IV Bill">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                                        Reports
                                    </a>
                                    <button
                                        class="btn btn-danger btn-sm"
                                        title="Delete Faculty"
                                        onclick="confirmDelete(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($f['faculty_enrollment_no'], ENT_QUOTES) ?>')"
                                    >
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
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


<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,0.6);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:36px 32px;max-width:440px;width:90%;box-shadow:0 24px 64px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
        <!-- Warning icon -->
        <div style="width:64px;height:64px;border-radius:18px;background:#fef2f2;border:2px solid #fecaca;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </div>
        <h2 style="font-size:19px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 8px;">Delete Faculty Member?</h2>
        <p style="font-size:13.5px;color:#64748b;text-align:center;margin:0 0 18px;line-height:1.6;">You are about to permanently delete:</p>
        <!-- Faculty identity box -->
        <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;text-align:center;">
            <div id="modal-name" style="font-size:16px;font-weight:800;color:#0f172a;"></div>
            <div id="modal-enroll" style="font-size:12px;font-family:monospace;font-weight:700;color:#4f46e5;margin-top:4px;background:#eef2ff;display:inline-block;padding:2px 10px;border-radius:6px;margin-top:6px;"></div>
        </div>
        <!-- Cascade warning -->
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 16px;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="2" style="flex-shrink:0;margin-top:1px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <span style="font-size:12.5px;color:#92400e;line-height:1.55;">
                This will <strong>permanently delete</strong> all their course assignments, lecture entries, and payment records.
                <strong>This action cannot be undone.</strong>
            </span>
        </div>
        <!-- Buttons -->
        <div style="display:flex;gap:12px;">
            <button onclick="closeDeleteModal()"
                style="flex:1;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-size:14px;font-weight:600;color:#475569;cursor:pointer;font-family:'Inter',sans-serif;transition:background .16s;"
                onmouseenter="this.style.background='#f8fafc';" onmouseleave="this.style.background='#fff';">
                Cancel
            </button>
            <form id="deleteForm" method="POST" action="<?= BASE_URL ?>/admin/faculty/delete.php" style="flex:1;margin:0;">
                <input type="hidden" name="faculty_id" id="modal-faculty-id">
                <button type="submit"
                    style="width:100%;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff;font-size:14px;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;box-shadow:0 2px 10px rgba(220,38,38,0.28);transition:all .18s;"
                    onmouseenter="this.style.boxShadow='0 4px 18px rgba(220,38,38,0.45)';"
                    onmouseleave="this.style.boxShadow='0 2px 10px rgba(220,38,38,0.28)';">
                    Yes, Delete Permanently
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(id, name, enroll) {
    document.getElementById('modal-faculty-id').value = id;
    document.getElementById('modal-name').textContent = name;
    document.getElementById('modal-enroll').textContent = enroll;
    const modal = document.getElementById('deleteModal');
    modal.style.display = 'flex';
    // slight delay so display:flex registers before animation
    setTimeout(() => modal.querySelector('div').style.opacity = '1', 10);
}
function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}
// Backdrop click to close
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
// Escape key to close
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeDeleteModal();
});
// Faculty search filter
function filterFaculty() {
    const q = document.getElementById('facultySearch').value.toLowerCase().trim();
    document.querySelectorAll('.faculty-row').forEach(r => {
        const text = r.getAttribute('data-search') || '';
        r.style.display = text.includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>
