<?php
header('Content-Type: application/json');
session_start();

try {
    require_once 'db_config.php';

    // Get JSON data from request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $action = isset($data['action']) ? $data['action'] : '';

    switch ($action) {
        case 'get_pollution_data':
            handleGetPollutionData($conn, $data);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function handleGetPollutionData($conn, $data) {
    try {
        $timeRange = isset($data['time_range']) ? intval($data['time_range']) : 7;
        $pollutantType = isset($data['pollutant_type']) ? $data['pollutant_type'] : 'all';

        // Get pollution data for the map
        $mapData = getMapData($conn, $timeRange, $pollutantType);
        
        // Get timeline data for line chart
        $timelineData = getTimelineData($conn, $timeRange, $pollutantType);
        
        // Get area data for bar chart
        $areaData = getAreaData($conn, $timeRange, $pollutantType);
        
        // Get distribution data for pie chart
        $distributionData = getDistributionData($conn, $timeRange);
        
        // Get trend data for area chart
        $trendData = getTrendData($conn, $timeRange, $pollutantType);

        // Get additional metrics data
        $additionalMetricsData = getAdditionalMetricsData($conn, $timeRange);

        echo json_encode([
            'success' => true,
            'map_data' => $mapData,
            'timeline_data' => $timelineData,
            'area_data' => $areaData,
            'distribution_data' => $distributionData,
            'trend_data' => $trendData,
            'additional_metrics_data' => $additionalMetricsData
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch pollution data']);
    }
}

function getMapData($conn, $timeRange, $pollutantType) {
    $timeCondition = $timeRange > 0 ? "WHERE p.datetime >= DATE_SUB(NOW(), INTERVAL " . $timeRange . " DAY)" : "";

    if ($pollutantType === 'all') {
        $sql = "SELECT 
                    ms.latitude as lat,
                    ms.longitude as lon,
                    p.pm25 as value,
                    p.datetime as timestamp,
                    ms.name as location,
                    'PM2.5' as pollutant
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                AND p.pm25 IS NOT NULL
                UNION ALL
                SELECT 
                    ms.latitude as lat,
                    ms.longitude as lon,
                    p.pm10 as value,
                    p.datetime as timestamp,
                    ms.name as location,
                    'PM10' as pollutant
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                AND p.pm10 IS NOT NULL
                UNION ALL
                SELECT 
                    ms.latitude as lat,
                    ms.longitude as lon,
                    p.no2 as value,
                    p.datetime as timestamp,
                    ms.name as location,
                    'NO2' as pollutant
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                AND p.no2 IS NOT NULL
                UNION ALL
                SELECT 
                    ms.latitude as lat,
                    ms.longitude as lon,
                    p.o3 as value,
                    p.datetime as timestamp,
                    ms.name as location,
                    'O3' as pollutant
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                AND p.o3 IS NOT NULL
                ORDER BY timestamp DESC";
    } else {
        $sql = "SELECT 
                    ms.latitude as lat,
                    ms.longitude as lon,
                    p." . $pollutantType . " as value,
                    p.datetime as timestamp,
                    ms.name as location,
                    '" . strtoupper($pollutantType) . "' as pollutant
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                AND p." . $pollutantType . " IS NOT NULL
                ORDER BY p.datetime DESC";
    }
    
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    
    return $data;
}

function getTimelineData($conn, $timeRange, $pollutantType) {
    $timeCondition = $timeRange > 0 ? "WHERE p.datetime >= DATE_SUB(NOW(), INTERVAL " . $timeRange . " DAY)" : "";

    if ($pollutantType === 'all') {
        $sql = "SELECT 
                    DATE(p.datetime) as date,
                    AVG(p.pm25) as pm25,
                    AVG(p.pm10) as pm10,
                    AVG(p.no2) as no2,
                    AVG(p.o3) as o3
                FROM polution p
                " . $timeCondition . "
                GROUP BY DATE(p.datetime)
                ORDER BY DATE(p.datetime)";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $data = [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'PM2.5',
                    'data' => [],
                    'borderColor' => getPollutantColor('pm25'),
                    'tension' => 0.1
                ],
                [
                    'label' => 'PM10',
                    'data' => [],
                    'borderColor' => getPollutantColor('pm10'),
                    'tension' => 0.1
                ],
                [
                    'label' => 'NO2',
                    'data' => [],
                    'borderColor' => getPollutantColor('no2'),
                    'tension' => 0.1
                ],
                [
                    'label' => 'O3',
                    'data' => [],
                    'borderColor' => getPollutantColor('o3'),
                    'tension' => 0.1
                ]
            ]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['date'];
            $data['datasets'][0]['data'][] = $row['pm25'];
            $data['datasets'][1]['data'][] = $row['pm10'];
            $data['datasets'][2]['data'][] = $row['no2'];
            $data['datasets'][3]['data'][] = $row['o3'];
        }
    } else {
        $sql = "SELECT 
                    DATE(p.datetime) as date,
                    AVG(p." . $pollutantType . ") as avg_value
                FROM polution p
                " . $timeCondition . "
                AND p." . $pollutantType . " IS NOT NULL
                GROUP BY DATE(p.datetime)
                ORDER BY DATE(p.datetime)";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => strtoupper($pollutantType),
                'data' => [],
                'borderColor' => getPollutantColor($pollutantType),
                'tension' => 0.1
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['date'];
            $data['datasets'][0]['data'][] = $row['avg_value'];
        }
    }
    
    return $data;
}

function getAreaData($conn, $timeRange, $pollutantType) {
    $timeCondition = $timeRange > 0 ? "WHERE p.datetime >= DATE_SUB(NOW(), INTERVAL " . $timeRange . " DAY)" : "";

    if ($pollutantType === 'all') {
        $sql = "SELECT 
                    ms.name as area,
                    AVG(p.pm25) as pm25,
                    AVG(p.pm10) as pm10,
                    AVG(p.no2) as no2,
                    AVG(p.o3) as o3
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                GROUP BY ms.name
                ORDER BY ms.name";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $data = [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'PM2.5',
                    'data' => [],
                    'backgroundColor' => getPollutantColor('pm25')
                ],
                [
                    'label' => 'PM10',
                    'data' => [],
                    'backgroundColor' => getPollutantColor('pm10')
                ],
                [
                    'label' => 'NO2',
                    'data' => [],
                    'backgroundColor' => getPollutantColor('no2')
                ],
                [
                    'label' => 'O3',
                    'data' => [],
                    'backgroundColor' => getPollutantColor('o3')
                ]
            ]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['area'];
            $data['datasets'][0]['data'][] = $row['pm25'];
            $data['datasets'][1]['data'][] = $row['pm10'];
            $data['datasets'][2]['data'][] = $row['no2'];
            $data['datasets'][3]['data'][] = $row['o3'];
        }
    } else {
        $sql = "SELECT 
                    ms.name as area,
                    AVG(p." . $pollutantType . ") as avg_value
                FROM polution p
                JOIN meteo_station ms ON p.station_id = ms.id
                " . $timeCondition . "
                AND p." . $pollutantType . " IS NOT NULL
                GROUP BY ms.name
                ORDER BY ms.name";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => strtoupper($pollutantType),
                'data' => [],
                'backgroundColor' => getPollutantColor($pollutantType)
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['area'];
            $data['datasets'][0]['data'][] = $row['avg_value'];
        }
    }
    
    return $data;
}

function getDistributionData($conn, $timeRange) {
    $timeCondition = $timeRange > 0 ? "WHERE p.datetime >= DATE_SUB(NOW(), INTERVAL " . $timeRange . " DAY)" : "";

    $sql = "SELECT 
                COUNT(CASE WHEN p.pm25 IS NOT NULL THEN 1 END) as pm25_count,
                COUNT(CASE WHEN p.pm10 IS NOT NULL THEN 1 END) as pm10_count,
                COUNT(CASE WHEN p.no2 IS NOT NULL THEN 1 END) as no2_count,
                COUNT(CASE WHEN p.o3 IS NOT NULL THEN 1 END) as o3_count
            FROM polution p
            " . $timeCondition;
    
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $row = $result->fetch_assoc();
    
    $data = [
        'labels' => ['PM2.5', 'PM10', 'NO2', 'O3'],
        'datasets' => [[
            'data' => [
                $row['pm25_count'],
                $row['pm10_count'],
                $row['no2_count'],
                $row['o3_count']
            ],
            'backgroundColor' => [
                getPollutantColor('pm25'),
                getPollutantColor('pm10'),
                getPollutantColor('no2'),
                getPollutantColor('o3')
            ]
        ]]
    ];
    
    return $data;
}

function getTrendData($conn, $timeRange, $pollutantType) {
    $timeCondition = $timeRange > 0 ? "WHERE p.datetime >= DATE_SUB(NOW(), INTERVAL " . $timeRange . " DAY)" : "";

    if ($pollutantType === 'all') {
        $sql = "SELECT 
                    DATE(p.datetime) as date,
                    AVG(p.pm25) as pm25,
                    AVG(p.pm10) as pm10,
                    AVG(p.no2) as no2,
                    AVG(p.o3) as o3
                FROM polution p
                " . $timeCondition . "
                GROUP BY DATE(p.datetime)
                ORDER BY DATE(p.datetime)";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $data = [
            'labels' => [],
            'datasets' => [
                [
                    'label' => 'PM2.5',
                    'data' => [],
                    'borderColor' => getPollutantColor('pm25'),
                    'backgroundColor' => getPollutantColor('pm25') . '40',
                    'fill' => true
                ],
                [
                    'label' => 'PM10',
                    'data' => [],
                    'borderColor' => getPollutantColor('pm10'),
                    'backgroundColor' => getPollutantColor('pm10') . '40',
                    'fill' => true
                ],
                [
                    'label' => 'NO2',
                    'data' => [],
                    'borderColor' => getPollutantColor('no2'),
                    'backgroundColor' => getPollutantColor('no2') . '40',
                    'fill' => true
                ],
                [
                    'label' => 'O3',
                    'data' => [],
                    'borderColor' => getPollutantColor('o3'),
                    'backgroundColor' => getPollutantColor('o3') . '40',
                    'fill' => true
                ]
            ]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['date'];
            $data['datasets'][0]['data'][] = $row['pm25'];
            $data['datasets'][1]['data'][] = $row['pm10'];
            $data['datasets'][2]['data'][] = $row['no2'];
            $data['datasets'][3]['data'][] = $row['o3'];
        }
    } else {
        $sql = "SELECT 
                    DATE(p.datetime) as date,
                    AVG(p." . $pollutantType . ") as avg_value
                FROM polution p
                " . $timeCondition . "
                AND p." . $pollutantType . " IS NOT NULL
                GROUP BY DATE(p.datetime)
                ORDER BY DATE(p.datetime)";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception($conn->error);
        }
        
        $data = [
            'labels' => [],
            'datasets' => [[
                'label' => strtoupper($pollutantType) . ' Trend',
                'data' => [],
                'borderColor' => getPollutantColor($pollutantType),
                'backgroundColor' => getPollutantColor($pollutantType) . '40',
                'fill' => true
            ]]
        ];
        
        while ($row = $result->fetch_assoc()) {
            $data['labels'][] = $row['date'];
            $data['datasets'][0]['data'][] = $row['avg_value'];
        }
    }
    
    return $data;
}

function getAdditionalMetricsData($conn, $timeRange) {
    $timeCondition = $timeRange > 0 ? "WHERE p.datetime >= DATE_SUB(NOW(), INTERVAL " . $timeRange . " DAY)" : "";

    $sql = "SELECT 
                DATE(p.datetime) as date,
                AVG(p.temperature) as temperature,
                AVG(p.humidity) as humidity,
                AVG(p.co) as co,
                AVG(p.no) as no
            FROM polution p
            " . $timeCondition . "
            GROUP BY DATE(p.datetime)
            ORDER BY DATE(p.datetime)";
    
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception($conn->error);
    }
    
    $data = [
        'labels' => [],
        'datasets' => [
            [
                'label' => 'Temperature (°C)',
                'data' => [],
                'borderColor' => '#e74c3c',
                'yAxisID' => 'y',
                'tension' => 0.1
            ],
            [
                'label' => 'Humidity (%)',
                'data' => [],
                'borderColor' => '#3498db',
                'yAxisID' => 'y1',
                'tension' => 0.1
            ],
            [
                'label' => 'CO (ppm)',
                'data' => [],
                'borderColor' => '#2ecc71',
                'yAxisID' => 'y2',
                'tension' => 0.1
            ],
            [
                'label' => 'NO (ppm)',
                'data' => [],
                'borderColor' => '#f1c40f',
                'yAxisID' => 'y2',
                'tension' => 0.1
            ]
        ]
    ];
    
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['date'];
        $data['datasets'][0]['data'][] = $row['temperature'];
        $data['datasets'][1]['data'][] = $row['humidity'];
        $data['datasets'][2]['data'][] = $row['co'];
        $data['datasets'][3]['data'][] = $row['no'];
    }
    
    return $data;
}

function getPollutantColor($type) {
    $colors = [
        'pm25' => '#e74c3c',
        'pm10' => '#3498db',
        'no2' => '#2ecc71',
        'o3' => '#f1c40f'
    ];
    return $colors[$type] ?? '#95a5a6';
}
?> 