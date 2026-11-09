<?php
// src/format.php - display helpers shared by the rule engine and the review page.

/** "2026-11-09" -> "9th Nov 2026". */
function niceDate(string $ymd): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d ? $d->format('jS M Y') : $ymd;
}

/** "2026-10-19" -> "3 weeks ago", relative to $today (default: today). */
function ago(string $ymd, ?DateTimeImmutable $today = null): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$d) {
        return '';
    }
    $days = (int) $d->diff($today ?? new DateTimeImmutable('today'))->format('%r%a');
    $plural = fn(int $n, string $unit) => "$n $unit" . ($n === 1 ? '' : 's') . ' ago';
    return match (true) {
        $days <= 0  => 'today',
        $days === 1 => 'yesterday',
        $days < 14  => $plural($days, 'day'),
        $days < 60  => $plural(intdiv($days, 7), 'week'),
        $days < 730 => $plural(intdiv($days, 30), 'month'),
        default     => $plural(intdiv($days, 365), 'year'),
    };
}

/** Gigabytes as "12.3 GB" or "1.25 TB". */
function gb(float $v): string
{
    return $v >= 1024 ? number_format($v / 1024, 2) . ' TB' : number_format($v, 1) . ' GB';
}
