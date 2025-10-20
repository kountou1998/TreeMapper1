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
        case 'get_profile':
            handleGetProfile($conn);
            break;
        case 'update_username':
            handleUpdateUsername($conn, $data);
            break;
        case 'change_password':
            handleChangePassword($conn, $data);
            break;
        case 'get_all_users':
            handleGetAllUsers($conn);
            break;
        case 'get_user':
            handleGetUser($conn, $data);
            break;
        case 'update_user':
            handleUpdateUser($conn, $data);
            break;
        case 'report_user':
            handleReportUser($conn, $data);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit();
}

function handleGetProfile($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        $sql = "SELECT username, email, role, status, created_at FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("i", $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'user' => [
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'status' => $user['status'],
                    'created_at' => $user['created_at']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch profile data']);
    }
}

function handleUpdateUsername($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        $new_username = $data['new_username'];
        $current_password = $data['current_password'];

        // First verify the current password
        $sql = "SELECT password FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("i", $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!password_verify($current_password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            return;
        }
        $stmt->close();

        // Check if new username is already taken
        $sql = "SELECT id FROM user WHERE username = ? AND id != ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("si", $new_username, $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Username is already taken']);
            return;
        }
        $stmt->close();

        // Update username
        $sql = "UPDATE user SET username = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("si", $new_username, $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $_SESSION['username'] = $new_username; // Update session
        echo json_encode(['success' => true, 'message' => 'Username updated successfully']);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update username']);
    }
}

function handleChangePassword($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        $current_password = $data['current_password'];
        $new_password = $data['new_password'];

        // First verify the current password
        $sql = "SELECT password FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("i", $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!password_verify($current_password, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
            return;
        }
        $stmt->close();

        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $sql = "UPDATE user SET password = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("si", $hashed_password, $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to change password']);
    }
}

function handleGetAllUsers($conn) {
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

        // Get all users
        $sql = "SELECT id, username, email, status, role, created_at FROM user ORDER BY created_at DESC";
        $result = $conn->query($sql);
        
        $users = [];
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        echo json_encode(['success' => true, 'users' => $users]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch users']);
    }
}

function handleGetUser($conn, $data) {
    try {
        if (!isset($data['username'])) {
            echo json_encode(['success' => false, 'message' => 'Username is required']);
            return;
        }

        // Get user info
        $sql = "SELECT u.id, u.username, u.created_at,
                (SELECT COUNT(*) FROM tree WHERE inserted_by = u.username) as uploads_count,
                (SELECT COUNT(*) FROM favorite WHERE user_id = u.id) as favorites_count
                FROM user u 
                WHERE u.username = ? AND u.status = 'ACTIVE'";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("s", $data['username']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            echo json_encode([
                'success' => true,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'created_at' => $user['created_at'],
                    'uploads_count' => $user['uploads_count'],
                    'favorites_count' => $user['favorites_count']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'User not found']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch user data']);
    }
}

function handleUpdateUser($conn, $data) {
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
        $admin = $result->fetch_assoc();
        
        if ($admin['role'] !== 'admin') {
            echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
            return;
        }
        $stmt->close();

        // Update user
        $sql = "UPDATE user SET status = ?, role = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $data['status'], $data['role'], $data['user_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'User updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update user']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update user']);
    }
}

function handleReportUser($conn, $data) {
    try {
        // Check if user is logged in
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'You must be logged in to report a user']);
            return;
        }

        if (!isset($data['target_id']) || !isset($data['reason'])) {
            echo json_encode(['success' => false, 'message' => 'Target user ID and reason are required']);
            return;
        }

        // Check if the reported user exists
        $sql = "SELECT id FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("i", $data['target_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Target user not found']);
            $stmt->close();
            return;
        }
        $stmt->close();

        // Insert the report as a request
        $sql = "INSERT INTO requests (user_id, type, target_id, description, status, created_at) 
                VALUES (?, 'PROFILE', ?, ?, 'PENDING', NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("iis", $_SESSION['user_id'], $data['target_id'], $data['reason']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);
        } else {
            throw new Exception('Failed to submit report');
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to submit report: ' . $e->getMessage()]);
    }
}
?> 