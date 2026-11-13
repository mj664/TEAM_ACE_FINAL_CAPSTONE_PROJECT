<?php
/**
 * South Caloocan district layout + approximate map coordinates for the live admin map.
 * Barangay numbers follow the South Caloocan planning map (approx. 1–164).
 */

function south_caloocan_districts() {
    return [
        'Sangandaan' => [
            'color' => '#c0392b',
            'center' => [14.6568, 120.9720],
            'barangays' => [1, 2, 3, 4, 5, 6, 7],
        ],
        'Dagat-Dagatan' => [
            'color' => '#1a5276',
            'center' => [14.6485, 120.9685],
            'barangays' => [8, 9, 12, 14, 28],
        ],
        'Poblacion' => [
            'color' => '#f4d03f',
            'center' => [14.6520, 120.9755],
            'barangays' => [10, 11, 13, 15, 16, 17, 18, 19],
        ],
        'Kaunlaran Village' => [
            'color' => '#e67e22',
            'center' => [14.6545, 120.9790],
            'barangays' => [20, 21, 22, 23, 24],
        ],
        'Maypajo' => [
            'color' => '#922b21',
            'center' => [14.6455, 120.9785],
            'barangays' => [25, 26, 27, 29, 30, 31, 32, 33, 34, 35],
        ],
        'Marulas' => [
            'color' => '#f1c40f',
            'center' => [14.6430, 120.9835],
            'barangays' => [36, 37],
        ],
        'Grace Park West' => [
            'color' => '#27ae60',
            'center' => [14.6505, 120.9885],
            'barangays' => range(38, 76),
        ],
        'University Hills' => [
            'color' => '#d35400',
            'center' => [14.6575, 120.9840],
            'barangays' => [77, 78, 79, 80],
        ],
        'Morning Breeze' => [
            'color' => '#f4d03f',
            'center' => [14.6590, 120.9910],
            'barangays' => [81, 82, 83, 84, 85],
        ],
        'Grace Park East' => [
            'color' => '#5dade2',
            'center' => [14.6525, 120.9975],
            'barangays' => array_values(array_diff(range(86, 125), [95, 99])),
        ],
        'Balintawak' => [
            'color' => '#1e8449',
            'center' => [14.6578, 121.0025],
            'barangays' => [95, 99],
        ],
        'Barrio San Jose' => [
            'color' => '#f7dc6f',
            'center' => [14.6455, 120.9985],
            'barangays' => [126, 127, 128, 129, 130, 131],
        ],
        'Bagong Barrio West' => [
            'color' => '#c0392b',
            'center' => [14.6555, 121.0080],
            'barangays' => range(132, 155),
        ],
        'Bagong Barrio East' => [
            'color' => '#e67e22',
            'center' => [14.6585, 121.0155],
            'barangays' => [156, 157],
        ],
        'Libis Baesa / Reparo' => [
            'color' => '#196f3d',
            'center' => [14.6625, 121.0120],
            'barangays' => [158, 159, 160, 161],
        ],
        'Santa Quiteria' => [
            'color' => '#f4d03f',
            'center' => [14.6655, 121.0185],
            'barangays' => [162, 163],
        ],
        'Talipapa' => [
            'color' => '#1a5276',
            'center' => [14.6690, 121.0240],
            'barangays' => [164],
        ],
    ];
}

function south_caloocan_barangay_points() {
    $points = [];
    foreach (south_caloocan_districts() as $district => $info) {
        $barangays = array_values($info['barangays']);
        $count = count($barangays);
        $cols = max(2, (int)ceil(sqrt($count)));
        [$baseLat, $baseLng] = $info['center'];

        foreach ($barangays as $index => $number) {
            $row = intdiv($index, $cols);
            $col = $index % $cols;
            $lat = $baseLat + ($row * 0.00115) - (($count > 1 ? intdiv($count - 1, $cols) : 0) * 0.00055);
            $lng = $baseLng + ($col * 0.00125) - (($cols - 1) * 0.0006);
            [$x, $y] = south_caloocan_marker_xy($district, $index, $count);
            $points[(string)$number] = [
                'number' => (int)$number,
                'district' => $district,
                'color' => $info['color'],
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'x' => $x,
                'y' => $y,
            ];
        }
    }
    return $points;
}

function south_caloocan_districts_payload() {
    $payload = [];
    foreach (south_caloocan_districts() as $name => $info) {
        $payload[$name] = [
            'color' => $info['color'],
            'center' => $info['center'],
            'barangays' => array_values($info['barangays']),
        ];
    }
    return $payload;
}

/**
 * Approximate marker positions on assets/south-caloocan-barangay-map.png (percent of image).
 */
function south_caloocan_district_boxes() {
    return [
        'Sangandaan' => [14, 34, 16, 12],
        'Dagat-Dagatan' => [3, 44, 16, 16],
        'Poblacion' => [16, 46, 16, 14],
        'Kaunlaran Village' => [18, 58, 16, 12],
        'Maypajo' => [12, 68, 20, 16],
        'Marulas' => [28, 84, 14, 10],
        'Grace Park West' => [28, 42, 20, 22],
        'University Hills' => [36, 30, 16, 10],
        'Morning Breeze' => [42, 36, 16, 10],
        'Grace Park East' => [46, 44, 22, 22],
        'Balintawak' => [64, 46, 12, 10],
        'Barrio San Jose' => [48, 70, 20, 16],
        'Bagong Barrio West' => [52, 20, 22, 22],
        'Bagong Barrio East' => [70, 26, 12, 10],
        'Libis Baesa / Reparo' => [62, 14, 18, 12],
        'Santa Quiteria' => [68, 8, 16, 10],
        'Talipapa' => [74, 2, 14, 8],
    ];
}

function south_caloocan_marker_xy($district, $index, $count) {
    $boxes = south_caloocan_district_boxes();
    $box = $boxes[$district] ?? [48, 48, 10, 10];
    [$left, $top, $width, $height] = $box;
    $cols = max(1, (int)ceil(sqrt(max(1, $count))));
    $rows = max(1, (int)ceil($count / $cols));
    $row = intdiv($index, $cols);
    $col = $index % $cols;
    $padX = $width * 0.12;
    $padY = $height * 0.18;
    $innerW = max(0.5, $width - ($padX * 2));
    $innerH = max(0.5, $height - ($padY * 2));
    $x = $left + $padX + ($cols === 1 ? $innerW / 2 : ($col + 0.5) * ($innerW / $cols));
    $y = $top + $padY + ($rows === 1 ? $innerH / 2 : ($row + 0.5) * ($innerH / $rows));
    return [round($x, 2), round($y, 2)];
}

/**
 * Build per-barangay stock status from the latest equipment report of each approved staff user.
 * Low stock rule matches the app: Medical Supplies quantity <= 5.
 */
function build_barangay_stock_status(mysqli $conn) {
    $medical_fields = [
        'facemask' => 'Facemask',
        'elastic_bandage' => 'Elastic Bandage',
        'gloves' => 'Gloves',
        'betadine_dropper' => 'Betadine',
        'surgical_tape' => 'Surgical Tape',
        'gauze_pad' => 'Gauze Pad',
        'nasal_cannula' => 'Nasal Cannula',
        'oxygen_mask' => 'Oxygen Mask',
    ];

    $status = [];
    foreach (south_caloocan_barangay_points() as $number => $point) {
        $status[$number] = [
            'number' => (int)$number,
            'district' => $point['district'],
            'color' => $point['color'],
            'lat' => $point['lat'],
            'lng' => $point['lng'],
            'label' => barangay_label($number),
            'has_report' => false,
            'low_stock' => false,
            'level' => 'idle',
            'low_items' => [],
            'warning_items' => [],
            'supplies' => [
                'facemask' => null,
                'elastic_bandage' => null,
                'betadine_dropper' => null,
                'surgical_tape' => null,
                'gauze_pad' => null,
                'nasal_cannula' => null,
                'oxygen_mask' => null,
            ],
            'reporter' => '',
            'staff_user_id' => 0,
            'submitted_at' => '',
            'x' => $point['x'] ?? 50,
            'y' => $point['y'] ?? 50,
        ];
    }

    $sql = "SELECT u.barangay, u.fullname, er.*
            FROM equipment_reports er
            INNER JOIN users u ON u.id = er.user_id
            INNER JOIN (
                SELECT user_id, MAX(submitted_at) AS latest
                FROM equipment_reports
                GROUP BY user_id
            ) latest ON latest.user_id = er.user_id AND latest.latest = er.submitted_at
            WHERE u.status = 'approved'
            ORDER BY er.submitted_at DESC";

    $result = $conn->query($sql);
    if (!$result) {
        return array_values($status);
    }

    while ($row = $result->fetch_assoc()) {
        $raw = trim((string)($row['barangay'] ?? ''));
        $number = preg_replace('/\D+/', '', $raw);
        if ($number === '' || !isset($status[$number])) {
            // Still track non-South numbers (e.g. 176) as floating alerts
            if ($number === '') continue;
            if (!isset($status[$number])) {
                $status[$number] = [
                    'number' => (int)$number,
                    'district' => 'Other Caloocan',
                    'color' => '#7f8c8d',
                    'lat' => 14.6700,
                    'lng' => 121.0300,
                    'label' => barangay_label($number),
                    'has_report' => false,
                    'low_stock' => false,
                    'level' => 'idle',
                    'low_items' => [],
                    'warning_items' => [],
                    'supplies' => [
                        'facemask' => null,
                        'elastic_bandage' => null,
                        'betadine_dropper' => null,
                        'surgical_tape' => null,
                        'gauze_pad' => null,
                        'nasal_cannula' => null,
                        'oxygen_mask' => null,
                    ],
                    'reporter' => '',
                    'staff_user_id' => 0,
                    'submitted_at' => '',
                    'x' => 88,
                    'y' => 8,
                ];
            }
        }

        // Keep the most recent report already ordered DESC; skip if already filled
        if ($status[$number]['has_report']) {
            continue;
        }

        $low_items = [];      // 0-5 (critical); a 0 is flagged 'out' => true ("Out of stock")
        $warning_items = [];  // 6-15
        $reported = 0;        // medical items that actually have a quantity in the report
        $supplies = $status[$number]['supplies'];
        foreach ($medical_fields as $field => $label) {
            // A blank field means "not reported": it is skipped, not counted as 0.
            $raw = trim((string)($row[$field] ?? ''));
            if ($raw === '' || !is_numeric($raw)) continue;
            $qty = max(0, (int)$raw);
            $reported++;
            if (array_key_exists($field, $supplies)) {
                $supplies[$field] = $qty;
            }
            $item = [
                'name' => $label,
                'quantity' => $qty,
                'out' => $qty === 0,
            ];
            if ($qty <= 5) {
                $low_items[] = $item;
            } elseif ($qty <= 15) {
                $warning_items[] = $item;
            }
        }

        $status[$number]['has_report'] = true;
        $status[$number]['low_stock'] = count($low_items) > 0;
        $status[$number]['out_of_stock'] = count(array_filter($low_items, function ($i) { return $i['out']; })) > 0;
        $status[$number]['low_items'] = $low_items;
        $status[$number]['warning_items'] = $warning_items;
        $status[$number]['supplies'] = $supplies;
        // Blank fields can't be rated. A report with no medical quantities at all is "No supplies reported"; one with
        // some blanks can still be red/yellow for the items it did report, but never "All good" ("Incomplete report").
        $complete = $reported === count($medical_fields);
        $status[$number]['no_supplies'] = $reported === 0;
        $status[$number]['incomplete'] = $reported > 0 && !$complete;
        $status[$number]['level'] = $reported === 0 ? 'idle'
            : (count($low_items) ? 'critical' : (count($warning_items) ? 'warning' : ($complete ? 'optimal' : 'idle')));
        $status[$number]['reporter'] = $row['fullname'] ?? '';
        $status[$number]['staff_user_id'] = (int)($row['user_id'] ?? 0);
        $status[$number]['submitted_at'] = $row['submitted_at'] ?? '';
    }

    return array_values($status);
}
