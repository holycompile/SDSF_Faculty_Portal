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
    $prefix = str_pad($prefix, 4, 'X');
    $count  = (int) $pdo->query("SELECT COUNT(*) FROM faculty_members")->fetchColumn();
    return $prefix . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
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
