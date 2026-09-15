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
