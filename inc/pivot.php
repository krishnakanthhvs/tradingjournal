<?php
function calculate_pivots($high, $low, $close) {
    $pivot = ($high + $low + $close) / 3;

    return [
        'pivot' => $pivot,
        'r1' => (2 * $pivot) - $low,
        'r2' => $pivot + ($high - $low),
        's1' => (2 * $pivot) - $high,
        's2' => $pivot - ($high - $low)
    ];
}