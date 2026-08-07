<?php
// includes/validation.php
require_once __DIR__ . '/functions.php';

function validateReportData($data) {
    $errors = [];
    
    // Required fields
    $required_fields = ['report_date', 'category_id', 'component', 'ttl_sam_pc', 
                        'unit_smv', 'day_forecast', 'unit_carder', 'plan_hours', 
                        'worked_hours'];
    
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
        }
    }
    
    // Validate numeric fields
    $numeric_fields = ['ttl_sam_pc', 'unit_smv', 'day_forecast', 'unit_carder', 
                       'plan_hours', 'worked_hours'];
    
    foreach ($numeric_fields as $field) {
        if (isset($data[$field]) && !is_numeric($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be a number.';
        }
    }
    
    // Validate hours 1-11
    for ($i = 1; $i <= 11; $i++) {
        $hour_field = "hour_{$i}";
        if (isset($data[$hour_field]) && !is_numeric($data[$hour_field])) {
            $errors[] = "Hour {$i} must be a number.";
        }
    }
    
    // Validate date
    if (isset($data['report_date'])) {
        $date = DateTime::createFromFormat('Y-m-d', $data['report_date']);
        if (!$date || $date->format('Y-m-d') !== $data['report_date']) {
            $errors[] = 'Invalid date format.';
        }
    }
    
    // Validate category exists
    if (isset($data['category_id'])) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->bind_param("i", $data['category_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $errors[] = 'Invalid category selected.';
        }
        $stmt->close();
        $conn->close();
    }
    
    return $errors;
}

function sanitizeReportData($data) {
    $sanitized = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $sanitized[$key] = sanitizeReportData($value);
        } else {
            $sanitized[$key] = sanitizeInput($value);
        }
    }
    return $sanitized;
}
?>