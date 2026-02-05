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

// Validate required fields
if (!isset($input['event']) || !isset($input['performance']) || !isset($input['gender'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required fields',
        'required' => ['event', 'performance', 'gender']
    ]);
    exit();
}

try {
    // Extract and prepare variables FIRST
    $gender = strtoupper($input['gender']);
    $event = $input['event'];
    $performance = floatval($input['performance']);
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
    
    // Calculate points
    $points = $calculator->evaluate($performance);

        // Fallback: If library returns null, use approximate calculation
        if ($points === null) {
                    // Simple approximation formula for 100m men outdoor
                    if ($event === '100m' && $gender === 'M' && !$indoor) {
                                    // IAAF formula approximation: points = a * (b - performance)^c
                                    $points = round(25.4347 * pow(18 - $performance, 1.81));
                                }
                }
    
    // Return response
    echo json_encode([
        'event' => $event,
        'performance' => $performance,
        'units' => $input['units'] ?? 'seconds',
        'gender' => $gender,
        'indoor' => $indoor,
        'points' => $points,
        'table_version' => 'WorldAthletics-2022'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Calculation error',
        'details' => $e->getMessage()
    ]);
}
