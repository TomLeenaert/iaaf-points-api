<?php
require_once __DIR__ . '/vendor/autoload.php';

use GlaivePro\IaafPoints\IaafCalculator;

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit();
}

// Determine direction: performance_to_points (default) or points_to_performance
$direction = $input['direction'] ?? 'performance_to_points';

// Validate required fields based on direction
if ($direction === 'points_to_performance') {
    if (!isset($input['event']) || !isset($input['points']) || !isset($input['gender'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required fields for points_to_performance',
            'required' => ['event', 'points', 'gender']
        ]);
        exit();
    }
} else {
    if (!isset($input['event']) || !isset($input['performance']) || !isset($input['gender'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Missing required fields for performance_to_points',
            'required' => ['event', 'performance', 'gender']
        ]);
        exit();
    }
}

try {
    // Extract common variables
    $gender = strtoupper($input['gender']);
    $event = $input['event'];
    $indoor = isset($input['indoor']) ? (bool)$input['indoor'] : false;
    
    // Set options for calculator
    $options = [
        'gender' => $gender,
        'venueType' => $indoor ? 'indoor' : 'outdoor',
        'discipline' => $event,
        'edition' => '2017',
        'electronicMeasurement' => true,
    ];
    
    $calculator = new IaafCalculator($options);
    
    if ($direction === 'points_to_performance') {
        // REVERSE: Points to Performance using bisection search
        $targetPoints = floatval($input['points']);
        
        // Define search range based on event type
        // For running events (times), smaller is better
        // For field events (distances), larger is better
        $isRunningEvent = in_array($event, ['100m', '200m', '400m', '800m', '1500m', '3000m', '5000m', '10000m', '100mH', '110mH', '400mH', '3000mSC']);
        
        if ($isRunningEvent) {
            // For running: fast time (low) to slow time (high)
            $minPerf = 5.0;  // very fast
            $maxPerf = 300.0; // very slow
        } else {
            // For field events: low distance to high distance
            $minPerf = 0.1;
            $maxPerf = 100.0;
        }
        
        // Bisection search
        $tolerance = 0.01;
        $maxIterations = 100;
        $iterations = 0;
        $foundPerformance = null;
        
        while ($iterations < $maxIterations && ($maxPerf - $minPerf) > $tolerance) {
            $midPerf = ($minPerf + $maxPerf) / 2;
            $calculatedPoints = $calculator->evaluate($midPerf);
            
            if ($calculatedPoints === null) {
                // Use fallback if library fails
                if ($event === '100m' && $gender === 'M' && !$indoor) {
                    $calculatedPoints = round(25.4347 * pow(18 - $midPerf, 1.81));
                } else {
                    break; // Cannot calculate
                }
            }
            
            if (abs($calculatedPoints - $targetPoints) < 1) {
                $foundPerformance = $midPerf;
                break;
            }
            
            if ($isRunningEvent) {
                // For running: lower time = more points
                if ($calculatedPoints > $targetPoints) {
                    $minPerf = $midPerf; // need slower time (fewer points)
                } else {
                    $maxPerf = $midPerf; // need faster time (more points)
                }
            } else {
                // For field events: higher distance = more points
                if ($calculatedPoints > $targetPoints) {
                    $maxPerf = $midPerf; // need lower distance (fewer points)
                } else {
                    $minPerf = $midPerf; // need higher distance (more points)
                }
            }
            
            $iterations++;
        }
        
        if ($foundPerformance !== null) {
            echo json_encode([
                'event' => $event,
                'points' => $targetPoints,
                'performance' => round($foundPerformance, 2),
                'units' => $input['units'] ?? 'seconds',
                'gender' => $gender,
                'indoor' => $indoor,
                'direction' => 'points_to_performance',
                'table_version' => 'WorldAthletics-2022'
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'error' => 'Could not find performance for given points',
                'details' => 'Bisection search failed or event not supported'
            ]);
        }
        
    } else {
        // FORWARD: Performance to Points (original behavior)
        $performance = floatval($input['performance']);
        $points = $calculator->evaluate($performance);
        
        // Fallback: If library returns null, use approximate calculation
        if ($points === null) {
            if ($event === '100m' && $gender === 'M' && !$indoor) {
                $points = round(25.4347 * pow(18 - $performance, 1.81));
            }
        }
        
        echo json_encode([
            'event' => $event,
            'performance' => $performance,
            'units' => $input['units'] ?? 'seconds',
            'gender' => $gender,
            'indoor' => $indoor,
            'points' => $points,
            'direction' => 'performance_to_points',
            'table_version' => 'WorldAthletics-2022'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Calculation error',
        'details' => $e->getMessage()
    ]);
}
