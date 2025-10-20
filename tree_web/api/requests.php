<?php
// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

header('Content-Type: application/json');
session_start();

try {
    require_once 'db_config.php';

    // Handle CORS
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        exit(0);
    }

    // Get JSON data from request body
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $action = isset($data['action']) ? $data['action'] : '';

    switch ($action) {
        case 'submit_request':
            handleSubmitRequest($conn, $data);
            break;
        case 'get_user_requests':
            handleGetUserRequests($conn);
            break;
        case 'get_request':
            handleGetRequest($conn, $data);
            break;
        case 'get_all_admin_requests':
            handleGetAllAdminRequests($conn);
            break;
        case 'update_request_status':
            handleUpdateRequestStatus($conn, $data);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit();
}

function handleSubmitRequest($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        // Validate required fields
        if (!isset($data['type']) || !isset($data['description'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        // Validate request type
        $validTypes = ['TREE', 'PROFILE', 'OTHER'];
        if (!in_array($data['type'], $validTypes)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request type']);
            return;
        }

        // Insert new request
        $sql = "INSERT INTO requests (user_id, type, target_id, description, status, created_at) 
                VALUES (?, ?, ?, ?, 'PENDING', NOW())";
        $stmt = $conn->prepare($sql);
        
        $targetId = isset($data['target_id']) ? $data['target_id'] : null;
        $stmt->bind_param("isis", $_SESSION['user_id'], $data['type'], $targetId, $data['description']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Request submitted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to submit request']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to submit request']);
    }
}

function handleGetUserRequests($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        $sql = "SELECT r.*, 
                CASE 
                    WHEN r.type = 'TREE' THEN t.name 
                    WHEN r.type = 'PROFILE' THEN u2.username 
                    ELSE NULL 
                END as target_name 
                FROM requests r 
                LEFT JOIN tree t ON r.type = 'TREE' AND r.target_id = t.id 
                LEFT JOIN user u2 ON r.type = 'PROFILE' AND r.target_id = u2.id 
                WHERE r.user_id = ? 
                ORDER BY r.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }

        echo json_encode(['success' => true, 'requests' => $requests]);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch requests']);
    }
}

function handleGetRequest($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        if (!isset($data['request_id'])) {
            echo json_encode(['success' => false, 'message' => 'Request ID is required']);
            return;
        }

        $sql = "SELECT r.*, 
                CASE 
                    WHEN r.type = 'TREE' THEN t.name 
                    WHEN r.type = 'PROFILE' THEN u2.username 
                    ELSE NULL 
                END as target_name 
                FROM requests r 
                LEFT JOIN tree t ON r.type = 'TREE' AND r.target_id = t.id 
                LEFT JOIN user u2 ON r.type = 'PROFILE' AND r.target_id = u2.id 
                WHERE r.id = ? AND r.target_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $data['request_id'], $data['target_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $request = $result->fetch_assoc();
            echo json_encode(['success' => true, 'request' => $request]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Request not found']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch request']);
    }
}

function handleGetAllAdminRequests($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        // Check if user is admin
        $sql = "SELECT role FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user['role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            return;
        }
        $stmt->close();

        // Get all requests with user and target information
        $sql = "SELECT r.*, u.username, 
                CASE 
                    WHEN r.type = 'TREE' THEN t.name 
                    WHEN r.type = 'PROFILE' THEN u2.username 
                    ELSE NULL 
                END as target_name 
                FROM requests r 
                LEFT JOIN user u ON r.user_id = u.id 
                LEFT JOIN tree t ON r.type = 'TREE' AND r.target_id = t.id 
                LEFT JOIN user u2 ON r.type = 'PROFILE' AND r.target_id = u2.id 
                ORDER BY r.created_at DESC";
        $result = $conn->query($sql);
        
        $requests = [];
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }

        echo json_encode(['success' => true, 'requests' => $requests]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch requests']);
    }
}

function handleUpdateRequestStatus($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        // Check if user is admin
        $sql = "SELECT role FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user['role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            return;
        }
        $stmt->close();

        // Validate required fields
        if (!isset($data['request_id']) || !isset($data['status'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        // Validate status
        $validStatuses = ['PENDING', 'OPEN', 'CLOSED'];
        if (!in_array($data['status'], $validStatuses)) {
            echo json_encode(['success' => false, 'message' => 'Invalid status']);
            return;
        }

        // Get current request status
        $sql = "SELECT status FROM requests WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['request_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $currentRequest = $result->fetch_assoc();
        $stmt->close();

        // Prepare update SQL based on new status
        if ($data['status'] === 'OPEN' && $currentRequest['status'] !== 'OPEN') {
            $sql = "UPDATE requests SET status = ?, opened_at = NOW() WHERE id = ?";
        } else if ($data['status'] === 'CLOSED' && $currentRequest['status'] !== 'CLOSED') {
            $sql = "UPDATE requests SET status = ?, resolved_at = NOW() WHERE id = ?";
        } else {
            $sql = "UPDATE requests SET status = ? WHERE id = ?";
        }

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $data['status'], $data['request_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Request status updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update request status']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update request status']);
    }
}
?> 