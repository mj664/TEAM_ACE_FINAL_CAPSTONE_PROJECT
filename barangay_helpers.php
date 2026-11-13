<?php
/**
 * Shared barangay labels/options so register, profile, and dashboard stay in sync.
 */

function barangay_label($barangay) {
    $value = trim((string)$barangay);
    if ($value === '') {
        return 'Barangay not set';
    }

    // Already a full display string from older data
    if (stripos($value, 'barangay') !== false || stripos($value, 'bagong') !== false) {
        return $value;
    }

    $number = preg_replace('/\D+/', '', $value);
    if ($number === '') {
        return $value;
    }

    if ($number === '176') {
        return 'Barangay 176 - Bagong Silang';
    }

    return 'Barangay ' . $number;
}

function barangay_options_html($selected = '') {
    $selected = trim((string)$selected);
    $selected_number = preg_replace('/\D+/', '', $selected);
    $html = '';

    for ($i = 1; $i <= 188; $i++) {
        $val = (string)$i;
        $label = ($i === 176) ? '176 - Bagong Silang' : ('Barangay ' . $i);
        $is_selected = ($selected_number === $val || $selected === $val) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($val, ENT_QUOTES) . '"' . $is_selected . '>'
            . htmlspecialchars($label, ENT_QUOTES) . '</option>';
    }

    return $html;
}
