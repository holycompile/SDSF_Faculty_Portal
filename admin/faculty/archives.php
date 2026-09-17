<?php
define('ROOT', dirname(dirname(dirname(__FILE__))));
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/helpers.php';
require_once ROOT . '/includes/archive_helper.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

ensureArchiveTableExists($pdo);

// Search & filter parameters
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? 'all'); // 'all', 'archived', 'restored'

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(name LIKE ? OR faculty_enrollment_no LIKE ? OR email LIKE ? OR department LIKE ?)";
    $sTerm = "%{$search}%";
    $params = array_merge($params, [$sTerm, $sTerm, $sTerm, $sTerm]);
}

if ($filterStatus === 'archived') {
    $where[] = "status = 'archived'";
} elseif ($filterStatus === 'restored') {
    $where[] = "status = 'restored'";
}

$sql = "SELECT * FROM archived_faculty_records WHERE " . implode(' AND ', $where) . " ORDER BY archived_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$archives = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall summary metrics
$statStmt = $pdo->query("
    SELECT 
        COUNT(*) AS total_archived,
        COALESCE(SUM(total_lectures), 0) AS total_lectures,
        COALESCE(SUM(total_hours), 0) AS total_hours,
        COALESCE(SUM(total_amount), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN status = 'restored' THEN 1 ELSE 0 END), 0) AS total_restored
    FROM archived_faculty_records
");
$stats = $statStmt->fetch(PDO::FETCH_ASSOC);

$active_nav = 'old-records';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Old Records (Faculty Backup &amp; Restore) — SDSF Admin</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/tailwind/output.css">
<?php require_once ROOT . '/includes/admin_sidebar.php'; ?>
<style>
.stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 20px 22px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    transition: transform .18s ease, box-shadow .18s ease;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0,0,0,0.06);
}
.stat-label {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #64748b;
}
.stat-val {
    font-size: 26px;
    font-weight: 800;
    color: #0f172a;
    margin-top: 5px;
    line-height: 1.1;
}
.stat-sub {
    font-size: 12px;
    color: #94a3b8;
    margin-top: 4px;
}
.restore-modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(15,23,42,0.65);
    backdrop-filter: blur(5px);
    align-items: center;
    justify-content: center;
    padding: 20px;
}
</style>
</head>
<body>
    <header class="topbar">
        <div class="tb-left">
            <span class="tb-crumb">Records</span>
            <span class="tb-sep">/</span>
            <span class="tb-crumb">Old Records (Backup &amp; Restore)</span>
        </div>
        <div class="tb-right">
            <span class="tb-date"><?= date('l, d F Y') ?></span>
            <a href="<?= BASE_URL ?>/admin/faculty/list.php" class="btn btn-outline btn-sm">
                Active Faculty Directory &rarr;
            </a>
        </div>
    </header>

    <div class="page">
        <!-- Header -->
        <div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
            <div>
                <h1>Old Records &amp; Faculty Archives</h1>
                <p>Secure database backup repository of removed faculty members, course assignments, lecture logs, student attendances, and bills.</p>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <a href="<?= BASE_URL ?>/admin/faculty/register.php" class="btn btn-primary btn-sm">
                    + Onboard New Faculty
                </a>
            </div>
        </div>

        <?php $flash = getFlash(); if ($flash): ?>
            <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?> fade-up">
                <?= $flash['message'] ?? $flash['msg'] ?? '' ?>
            </div>
        <?php endif; ?>

        <!-- Stat Metric Cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px;" class="fade-up">
            <div class="stat-card">
                <div class="stat-label">Archived Faculty</div>
                <div class="stat-val"><?= (int)($stats['total_archived'] ?? 0) ?></div>
                <div class="stat-sub"><?= (int)($stats['total_restored'] ?? 0) ?> previously restored</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Preserved Lectures</div>
                <div class="stat-val" style="color:#4f46e5;"><?= (int)($stats['total_lectures'] ?? 0) ?></div>
                <div class="stat-sub"><?= number_format((float)($stats['total_hours'] ?? 0), 1) ?> total teaching hours</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Preserved Remuneration</div>
                <div class="stat-val" style="color:#047857;">&#8377;<?= number_format((float)($stats['total_amount'] ?? 0), 2) ?></div>
                <div class="stat-sub">Historical claims &amp; disbursements</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Backup Integrity</div>
                <div class="stat-val" style="color:#16a34a;display:flex;align-items:center;gap:6px;">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                    100%
                </div>
                <div class="stat-sub">Full attendance &amp; profile clones</div>
            </div>
        </div>

        <!-- Filter & Search Bar -->
        <div class="card fade-up" style="padding:16px 20px;margin-bottom:24px;">
            <form method="GET" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <div style="flex:1;min-width:260px;position:relative;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2.2" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" name="search" class="form-input" style="padding-left:38px;font-size:13.5px;"
                           placeholder="Search by faculty name, enrollment code, department, email..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <div style="width:170px;">
                    <select name="status" class="form-select" style="font-size:13.5px;">
                        <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>All Statuses</option>
                        <option value="archived" <?= $filterStatus === 'archived' ? 'selected' : '' ?>>Archived (Active Backup)</option>
                        <option value="restored" <?= $filterStatus === 'restored' ? 'selected' : '' ?>>Restored</option>
                    </select>
                </div>

                <div style="display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary" style="padding:10px 18px;font-size:13.5px;">Filter</button>
                    <?php if ($search !== '' || $filterStatus !== 'all'): ?>
                        <a href="<?= BASE_URL ?>/admin/faculty/archives.php" class="btn btn-outline" style="padding:10px 14px;font-size:13.5px;">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Archives Table -->
        <div class="card fade-up">
            <div class="card-head">
                <div>
                    <div class="card-title">Archived Faculty Dossiers</div>
                    <div class="card-sub">Showing <?= count($archives) ?> backup record<?= count($archives) === 1 ? '' : 's' ?></div>
                </div>
            </div>

            <?php if (empty($archives)): ?>
                <div style="padding:60px 20px;text-align:center;color:#94a3b8;">
                    <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 14px;display:block;opacity:.4;"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><line x1="10" y1="12" x2="14" y2="12"/></svg>
                    <div style="font-size:16px;font-weight:700;color:#334155;">No Archived Records Found</div>
                    <p style="font-size:13.5px;color:#64748b;margin-top:4px;">
                        <?= ($search !== '' || $filterStatus !== 'all') ? 'No archives match your filter criteria.' : 'When a faculty member is deleted from the active directory, their complete backup dossier will automatically appear here.' ?>
                    </p>
                </div>
            <?php else: ?>
                <table class="dt">
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Faculty Name</th>
                            <th>Enrollment No</th>
                            <th>Department &amp; Contact</th>
                            <th>Archived Date</th>
                            <th>Preserved Lectures</th>
                            <th>Preserved Remuneration</th>
                            <th style="text-align:center;">Status</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archives as $idx => $arch): 
                            $coursesCount = count(json_decode($arch['courses_data_json'] ?? '[]', true));
                            $isRestored = ($arch['status'] === 'restored');
                        ?>
                        <tr>
                            <td style="color:#94a3b8;font-size:12px;"><?= $idx + 1 ?></td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;font-size:14px;"><?= htmlspecialchars($arch['name']) ?></div>
                                <div style="font-size:11.5px;color:#64748b;"><?= htmlspecialchars($arch['qualification'] ?? 'Visiting Faculty') ?></div>
                            </td>
                            <td>
                                <code style="background:#eef2ff;color:#4338ca;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;border:1px solid #c7d2fe;">
                                    <?= htmlspecialchars($arch['faculty_enrollment_no']) ?>
                                </code>
                            </td>
                            <td>
                                <div style="font-size:13px;font-weight:600;color:#334155;"><?= htmlspecialchars($arch['department'] ?: 'SDSF') ?></div>
                                <div style="font-size:11.5px;color:#64748b;"><?= htmlspecialchars($arch['phone'] ?: ($arch['email'] ?: '—')) ?></div>
                            </td>
                            <td>
                                <div style="font-weight:600;color:#0f172a;"><?= date('d M Y', strtotime($arch['archived_at'])) ?></div>
                                <div style="font-size:11px;color:#94a3b8;">By: <?= htmlspecialchars($arch['archived_by'] ?? 'admin') ?></div>
                            </td>
                            <td>
                                <div style="font-weight:700;color:#0f172a;"><?= (int)$arch['total_lectures'] ?> sessions</div>
                                <div style="font-size:11.5px;color:#64748b;"><?= number_format((float)$arch['total_hours'], 1) ?> hrs &bull; <?= $coursesCount ?> subjects</div>
                            </td>
                            <td>
                                <span style="font-weight:800;color:#047857;">&#8377;<?= number_format((float)$arch['total_amount'], 2) ?></span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($isRestored): ?>
                                    <span class="badge badge-green" title="Restored on <?= date('d M Y', strtotime($arch['restored_at'])) ?>">
                                        ✓ Restored
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-blue">
                                        Archived (Backup)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex;gap:6px;align-items:center;">
                                    <a href="<?= BASE_URL ?>/admin/faculty/archive_view.php?id=<?= $arch['id'] ?>" class="btn btn-outline btn-sm" style="font-size:12px;padding:5px 12px;">
                                        View Dossier &rarr;
                                    </a>
                                    <?php if (!$isRestored): ?>
                                        <button type="button" class="btn btn-primary btn-sm" style="font-size:12px;padding:5px 12px;background:linear-gradient(135deg, #059669, #047857);"
                                                onclick="openRestoreModal(<?= $arch['id'] ?>, '<?= htmlspecialchars(addslashes($arch['name'])) ?>', '<?= htmlspecialchars($arch['faculty_enrollment_no']) ?>')">
                                            Restore
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Restore Confirmation Modal -->
    <div id="restoreModal" class="restore-modal-overlay">
        <div style="background:#fff;border-radius:20px;padding:34px 30px;max-width:480px;width:95%;box-shadow:0 24px 60px rgba(0,0,0,0.22);animation:fadeUp .25s ease both;">
            <div style="width:58px;height:58px;border-radius:16px;background:#ecfdf5;border:2px solid #a7f3d0;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.3"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
            </div>
            <h2 style="font-size:19px;font-weight:800;color:#0f172a;text-align:center;margin:0 0 8px;">Restore Faculty Member?</h2>
            <p style="font-size:13.5px;color:#64748b;text-align:center;margin:0 0 18px;line-height:1.55;">
                You are about to restore this faculty member back into the active SDSF directory:
            </p>
            <div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 18px;margin-bottom:18px;text-align:center;">
                <div id="modal-res-name" style="font-size:16px;font-weight:800;color:#0f172a;"></div>
                <div id="modal-res-enroll" style="font-size:12px;font-family:monospace;font-weight:700;color:#4f46e5;background:#eef2ff;display:inline-block;padding:2px 10px;border-radius:6px;margin-top:6px;"></div>
            </div>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;margin-bottom:24px;display:flex;gap:10px;align-items:flex-start;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.2" style="flex-shrink:0;margin-top:1px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <div style="font-size:12.5px;color:#166534;line-height:1.5;">
                    Restoring will reinstate all <strong>assigned courses</strong>, <strong>lecture entries</strong>, <strong>student attendance logs</strong>, and login access. The teacher will be able to log in immediately with their existing password.
                </div>
            </div>
            <div style="display:flex;gap:12px;">
                <button type="button" onclick="closeRestoreModal()"
                    style="flex:1;padding:12px;border-radius:10px;border:1.5px solid #e2e8f0;background:#fff;font-size:14px;font-weight:600;color:#475569;cursor:pointer;">
                    Cancel
                </button>
                <form method="POST" action="<?= BASE_URL ?>/admin/faculty/restore.php" style="flex:1;margin:0;">
                    <input type="hidden" name="archive_id" id="modal-res-archive-id">
                    <button type="submit"
                        style="width:100%;padding:12px;border-radius:10px;border:none;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 2px 10px rgba(5,150,105,0.28);">
                        Yes, Restore Faculty
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openRestoreModal(archiveId, name, enroll) {
        document.getElementById('modal-res-archive-id').value = archiveId;
        document.getElementById('modal-res-name').textContent = name;
        document.getElementById('modal-res-enroll').textContent = enroll;
        document.getElementById('restoreModal').style.display = 'flex';
    }
    function closeRestoreModal() {
        document.getElementById('restoreModal').style.display = 'none';
    }
    document.getElementById('restoreModal').addEventListener('click', function(e) {
        if (e.target === this) closeRestoreModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeRestoreModal();
    });
    </script>
</body>
</html>
