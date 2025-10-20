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

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    switch ($action) {
        case 'signin':
            handleSignIn($conn);
            break;
        case 'signup':
            handleSignUp($conn);
            break;
        case 'logout':
            handleLogout();
            break;
        case 'get_profile':
            handleGetProfile($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit();
}

function handleSignIn($conn) {
    try {
        $username = $conn->real_escape_string($_POST['username']);
        $password = $_POST['password'];

        $sql = "SELECT id, username, password, role, status FROM user WHERE username = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("s", $username);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Check user status first
            if ($user['status'] !== 'ACTIVE') {
                $message = '';
                switch ($user['status']) {
                    case 'PENDING':
                        $message = 'Your account is pending activation';
                        break;
                    case 'SUSPENDED':
                        $message = 'Your account has been suspended';
                        break;
                    default:
                        $message = 'Account is not active';
                }
                echo json_encode(['success' => false, 'message' => $message]);
                $stmt->close();
                return;
            }

            // Verify password only if account is active
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                echo json_encode(['success' => true, 'message' => 'Login successful', 'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'], 
                    'role' => $user['role'],
                    'status' => $user['status']
                ]]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Login failed']);
    }
}

function handleSignUp($conn) {
    try {
        $email = $conn->real_escape_string($_POST['email']);
        $username = $conn->real_escape_string($_POST['username']);
        $password = $_POST['password'];

        // Check if email or username already exists
        $sql = "SELECT id FROM user WHERE email = ? OR username = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("ss", $email, $username);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Email or username already exists']);
            $stmt->close();
            return;
        }
        $stmt->close();

        // Create new user
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $sql = "INSERT INTO user (email, username, password) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("sss", $email, $username, $hashedPassword);
        if (!$stmt->execute()) {
            throw new Exception($stmt->error);
        }

        echo json_encode(['success' => true, 'message' => 'Registration successful']);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
    }
}

function handleLogout() {
    try {
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logout successful']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Logout failed']);
    }
}

function handleGetProfile($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        $sql = "SELECT username, email, role, created_at FROM user WHERE id = ?";
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
?> 