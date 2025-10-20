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

    // Get action from either POST data or JSON input
    $action = '';
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
    } else {
        // Try to get from JSON input
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        $action = isset($data['action']) ? $data['action'] : '';
    }

    switch ($action) {
        case 'get_all_trees':
            handleGetAllTrees($conn);
            break;
        case 'get_tree_by_id':
            handleGetTreeById($conn);
            break;
        case 'get_tree_types':
            handleGetTreeTypes($conn);
            break;
        case 'get_tree_type':
            handleGetTreeType($conn);
            break;
        case 'get_tree':
            handleGetTree($conn);
            break;
        case 'get_locations':
            handleGetLocations($conn);
            break;
        case 'add_tree':
            handleAddTree($conn);
            break;
        case 'add_tree_type':
            handleAddTreeType($conn, $data);
            break;
        case 'update_tree_type':
            handleUpdateTreeType($conn, $data);
            break;
        case 'delete_tree_type':
            handleDeleteTreeType($conn, $data);
            break;
        case 'update_tree':
            handleUpdateTree($conn, $data);
            break;
        case 'delete_tree':
            handleDeleteTree($conn, $data);
            break;
        case 'check_duplicate_tree_type':
            handleCheckDuplicateTreeType($conn, $data);
            break;
        case 'add_favorite':
            handleAddFavorite($conn, $data);
            break;
        case 'remove_favorite':
            handleRemoveFavorite($conn, $data);
            break;
        case 'get_favorites':
            handleGetFavorites($conn);
            break;
        case 'get_user_trees':
            handleGetUserTrees($conn, $data);
            break;
        case 'get_user_favorites':
            handleGetUserFavorites($conn, $data);
            break;
        default:        
            echo json_encode(['success' => false, 'message' => 'Invalid action']);

    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit();
}

function handleGetAllTrees($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        // Check if user is active
        $sql = "SELECT status FROM user WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare user status check: " . $conn->error);
        }
        $stmt->bind_param("i", $_SESSION['user_id']);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute user status check: " . $stmt->error);
        }
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if ($user['status'] !== 'ACTIVE') {
            echo json_encode(['success' => false, 'message' => 'Only active users can access the tree map']);
            return;
        }
        $stmt->close();

        // Get all trees with their type and location information
        $sql = "SELECT t.id, t.type_code, t.name, t.absolute_position_x, t.absolute_position_y, 
                       t.lat, t.lon, t.inserted_at, t.inserted_by, t.url, t.location_id,
                       tt.greek_name, tt.scientific_name, tt.amount,
                       l.tax_code, l.street_name, l.street_number, l.area_id
                FROM tree t
                INNER JOIN tree_type tt ON t.type_code = tt.id 
                LEFT JOIN location l ON t.location_id = l.id
                ORDER BY t.inserted_at DESC";
        
        $result = $conn->query($sql);
        if (!$result) {
            throw new Exception("Failed to execute tree query: " . $conn->error);
        }
        
        $trees = [];
        while ($row = $result->fetch_assoc()) {
            // Add error checking for null values
            $measurements = [];
            if ($row['amount'] !== null) {
                $measurements['amount'] = $row['amount'];
            }

            $trees[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'absolute_position_x' => $row['absolute_position_x'],
                'absolute_position_y' => $row['absolute_position_y'],
                'lat' => $row['lat'],
                'lon' => $row['lon'],
                'inserted_at' => $row['inserted_at'],
                'inserted_by' => $row['inserted_by'],
                'url' => $row['url'],
                'location' => [
                    'id' => $row['location_id'],
                    'tax_code' => $row['tax_code'],
                    'street_name' => $row['street_name'],
                    'street_number' => $row['street_number'],
                    'area_id' => $row['area_id']
                ],
                'type' => [
                    'id' => $row['type_code'],
                    'greek_name' => $row['greek_name'],
                    'scientific_name' => $row['scientific_name'],
                    'measurements' => $measurements
                ]
            ];
        }
        
        echo json_encode(['success' => true, 'trees' => $trees]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch trees: ' . $e->getMessage()]);
    }
}

function handleGetTreeById($conn) {
    try {
        // Fetch tree by id
        $sql = "SELECT id, type_code, name, absolute_position_x, absolute_position_y, 
                       lat, lon, inserted_at, url, location_id 
                FROM tree 
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $treeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $tree = $result->fetch_assoc();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch tree']);
    }
}

function handleGetTreeTypes($conn) {
    try {
        $sql = "SELECT id, greek_name, scientific_name, amount, type_id FROM tree_type";
        $result = $conn->query($sql);
        $treeTypes = [];
        while ($row = $result->fetch_assoc()) {
            $treeTypes[] = $row;
        }
        echo json_encode(['success' => true, 'tree_types' => $treeTypes]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch tree types: ' . $e->getMessage()]);
    }
}

function handleGetTreeType($conn) {
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

        // Get tree type data
        $sql = "SELECT * FROM tree_type WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $treeType = $result->fetch_assoc();
            echo json_encode(['success' => true, 'tree_type' => $treeType]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Tree type not found']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch tree type']);
    }
}

function handleGetTree($conn) {
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

        // Get tree data
        $sql = "SELECT * FROM tree WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $tree = $result->fetch_assoc();
            echo json_encode(['success' => true, 'tree' => $tree]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Tree not found']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch tree']);
    }
}

function handleGetLocations($conn) {
    try {
        $sql = "SELECT id, street_name, street_number, tax_code, area_id FROM location ORDER BY street_name, street_number";
        $result = $conn->query($sql);
        $locations = [];
        while ($row = $result->fetch_assoc()) {
            $locations[] = $row;
        }
        echo json_encode(['success' => true, 'locations' => $locations]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch locations: ' . $e->getMessage()]);
    }
}

function handleAddTree($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        // Debug: Print session data
        error_log("Session data: " . print_r($_SESSION, true));
        error_log("POST data: " . print_r($_POST, true));

        // Validate required fields
        if (!isset($_POST['name']) || !isset($_POST['type_code']) || 
            !isset($_POST['lat']) || !isset($_POST['lon'])) {
            $missing = [];
            if (!isset($_POST['name'])) $missing[] = 'name';
            if (!isset($_POST['type_code'])) $missing[] = 'type_code';
            if (!isset($_POST['lat'])) $missing[] = 'lat';
            if (!isset($_POST['lon'])) $missing[] = 'lon';
            echo json_encode(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missing)]);
            return;
        }

        // Handle location
        $location_id = null;
        if (isset($_POST['location_id']) && !empty($_POST['location_id'])) {
            // Use existing location
            $location_id = $_POST['location_id'];
        } else if (isset($_POST['street_name']) && isset($_POST['street_number'])) {
            // Create new location
            $sql = "INSERT INTO location (street_name, street_number, tax_code, area_id) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Failed to prepare location statement: " . $conn->error);
            }
            
            $stmt->bind_param("ssss", 
                $_POST['street_name'],
                $_POST['street_number'],
                $_POST['tax_code'],
                $_POST['area_id']
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create location: " . $stmt->error);
            }
            
            $location_id = $stmt->insert_id;
            $stmt->close();
        }

        // Handle image upload
        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            error_log("File upload data: " . print_r($file, true));
            
            // Validate file size (5MB max)
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Image size must be less than 5MB']);
                return;
            }

            // Validate file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowed_types)) {
                echo json_encode(['success' => false, 'message' => 'Invalid image format. Only JPG, PNG, and GIF are allowed']);
                return;
            }

            // Create upload directory if it doesn't exist
            $upload_dir = '../uploads/trees/';
            if (!file_exists($upload_dir)) {
                if (!mkdir($upload_dir, 0777, true)) {
                    echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
                    return;
                }
            }

            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('tree_') . '.' . $extension;
            $image_path = 'uploads/trees/' . $filename;

            // Move uploaded file
            if (!move_uploaded_file($file['tmp_name'], '../' . $image_path)) {
                $upload_error = error_get_last();
                echo json_encode(['success' => false, 'message' => 'Failed to upload image: ' . ($upload_error['message'] ?? 'Unknown error')]);
                return;
            }
        }

        // Insert new tree
        $sql = "INSERT INTO tree (name, type_code, lat, lon, url, inserted_by, location_id, inserted_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        // Debug: Print values being bound
        error_log("Binding values: " . print_r([
            'name' => $_POST['name'],
            'type_code' => $_POST['type_code'],
            'lat' => $_POST['lat'],
            'lon' => $_POST['lon'],
            'image_path' => $image_path,
            'username' => $_SESSION['username'],
            'location_id' => $location_id
        ], true));

        $stmt->bind_param("sidissi", 
            $_POST['name'],
            $_POST['type_code'],
            $_POST['lat'],
            $_POST['lon'],
            $image_path,
            $_SESSION['username'],
            $location_id
        );

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree added successfully']);
        } else {
            // If insert fails, delete uploaded image
            if ($image_path && file_exists('../' . $image_path)) {
                unlink('../' . $image_path);
            }
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        // Clean up uploaded file if an error occurs
        if (isset($image_path) && file_exists('../' . $image_path)) {
            unlink('../' . $image_path);
        }
        echo json_encode(['success' => false, 'message' => 'Failed to add tree: ' . $e->getMessage()]);
        error_log("Tree addition error: " . $e->getMessage());
    }
}

function handleUpdateTreeType($conn, $data) {
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

        // Update tree type without changing amount
        $sql = "UPDATE tree_type SET greek_name = ?, scientific_name = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", 
            $data['greek_name'],
            $data['scientific_name'],
            $data['id']
        );
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree type updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update tree type']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update tree type']);
    }
}

function handleCheckDuplicateTreeType($conn, $data) {
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

        // Check for duplicates excluding the current tree type
        $sql = "SELECT COUNT(*) as count FROM tree_type 
                WHERE (greek_name = ? OR scientific_name = ?) 
                AND id != ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", 
            $data['greek_name'],
            $data['scientific_name'],
            $data['id']
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];
        
        echo json_encode([
            'success' => true,
            'duplicate' => $count > 0
        ]);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to check for duplicates']);
    }
}

function handleDeleteTreeType($conn, $data) {
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

        // Check if tree type is in use
        $sql = "SELECT COUNT(*) as count FROM tree WHERE type_code = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = $result->fetch_assoc()['count'];
        
        if ($count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete tree type that is in use']);
            return;
        }
        $stmt->close();

        // Delete tree type
        $sql = "DELETE FROM tree_type WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree type deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete tree type']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete tree type']);
    }
}

function handleUpdateTree($conn, $data) {
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

        // Update tree
        $sql = "UPDATE tree SET name = ?, type_code = ?, lat = ?, lon = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("siddi", 
            $data['name'],
            $data['type_code'],
            $data['lat'],
            $data['lon'],
            $data['id']
        );
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update tree']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to update tree']);
    }
}

function handleDeleteTree($conn, $data) {
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

        // Delete tree image if exists
        $sql = "SELECT url FROM tree WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $tree = $result->fetch_assoc();
        
        if ($tree['url'] && file_exists('../' . $tree['url'])) {
            unlink('../' . $tree['url']);
        }
        $stmt->close();

        // Delete tree
        $sql = "DELETE FROM tree WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $data['id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete tree']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to delete tree']);
    }
}

function handleAddTreeType($conn, $data) {
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
        if (!isset($data['greek_name']) || !isset($data['scientific_name'])) {
            echo json_encode(['success' => false, 'message' => 'Missing required fields']);
            return;
        }

        // Check for duplicates
        $sql = "SELECT id FROM tree_type WHERE greek_name = ? OR scientific_name = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $data['greek_name'], $data['scientific_name']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'A tree type with this name already exists']);
            return;
        }
        $stmt->close();

        // Insert new tree type
        $sql = "INSERT INTO tree_type (greek_name, scientific_name) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $data['greek_name'], $data['scientific_name']);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true, 
                'message' => 'Tree type added successfully',
                'tree_type_id' => $stmt->insert_id
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add tree type']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to add tree type']);
    }
}

function handleAddFavorite($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        if (!isset($data['tree_id'])) {
            echo json_encode(['success' => false, 'message' => 'Tree ID is required']);
            return;
        }

        // Check if already favorited
        $sql = "SELECT id FROM favorite WHERE user_id = ? AND tree_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $_SESSION['user_id'], $data['tree_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'Tree is already in favorites']);
            return;
        }
        $stmt->close();

        // Add to favorites
        $sql = "INSERT INTO favorite (user_id, tree_id, created_at) VALUES (?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $_SESSION['user_id'], $data['tree_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree added to favorites']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add tree to favorites']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to add favorite']);
    }
}

function handleRemoveFavorite($conn, $data) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        if (!isset($data['tree_id'])) {
            echo json_encode(['success' => false, 'message' => 'Tree ID is required']);
            return;
        }

        $sql = "DELETE FROM favorite WHERE user_id = ? AND tree_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $_SESSION['user_id'], $data['tree_id']);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Tree removed from favorites']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to remove tree from favorites']);
        }
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to remove favorite']);
    }
}

function handleGetFavorites($conn) {
    try {
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Not authenticated']);
            return;
        }

        $sql = "SELECT t.id, t.name, t.lat, t.lon, t.inserted_at, t.inserted_by, t.url,
                       tt.greek_name, tt.scientific_name,
                       l.street_name, l.street_number,
                       f.created_at as favorited_at
                FROM favorite f
                INNER JOIN tree t ON f.tree_id = t.id
                INNER JOIN tree_type tt ON t.type_code = tt.id
                LEFT JOIN location l ON t.location_id = l.id
                WHERE f.user_id = ?
                ORDER BY f.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $favorites = [];
        while ($row = $result->fetch_assoc()) {
            $favorites[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'lat' => $row['lat'],
                'lon' => $row['lon'],
                'inserted_at' => $row['inserted_at'],
                'inserted_by' => $row['inserted_by'],
                'url' => $row['url'],
                'favorited_at' => $row['favorited_at'],
                'type' => [
                    'greek_name' => $row['greek_name'],
                    'scientific_name' => $row['scientific_name']
                ],
                'location' => [
                    'street_name' => $row['street_name'],
                    'street_number' => $row['street_number']
                ]
            ];
        }
        
        echo json_encode(['success' => true, 'favorites' => $favorites]);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch favorites']);
    }
}

function handleGetUserTrees($conn, $data) {
    try {
        if (!isset($data['username'])) {
            echo json_encode(['success' => false, 'message' => 'Username is required']);
            return;
        }

        // Get all trees with their type and location information
        $sql = "SELECT t.id, t.type_code, t.name, t.absolute_position_x, t.absolute_position_y, 
                       t.lat, t.lon, t.inserted_at, t.inserted_by, t.url, t.location_id,
                       tt.greek_name, tt.scientific_name, tt.amount,
                       l.tax_code, l.street_name, l.street_number, l.area_id
                FROM tree t
                INNER JOIN tree_type tt ON t.type_code = tt.id 
                LEFT JOIN location l ON t.location_id = l.id
                WHERE t.inserted_by = ?
                ORDER BY t.inserted_at DESC";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Failed to prepare statement: " . $conn->error);
        }

        $stmt->bind_param("s", $data['username']); // Using username parameter
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement: " . $stmt->error);
        }
        
        $result = $stmt->get_result();
        
        $trees = [];
        while ($row = $result->fetch_assoc()) {
            // Add error checking for null values
            $measurements = [];
            if ($row['amount'] !== null) {
                $measurements['amount'] = $row['amount'];
            }

            $trees[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'absolute_position_x' => $row['absolute_position_x'],
                'absolute_position_y' => $row['absolute_position_y'],
                'lat' => $row['lat'],
                'lon' => $row['lon'],
                'inserted_at' => $row['inserted_at'],
                'inserted_by' => $row['inserted_by'],
                'url' => $row['url'],
                'location' => [
                    'id' => $row['location_id'],
                    'tax_code' => $row['tax_code'],
                    'street_name' => $row['street_name'],
                    'street_number' => $row['street_number'],
                    'area_id' => $row['area_id']
                ],
                'type' => [
                    'id' => $row['type_code'],
                    'greek_name' => $row['greek_name'],
                    'scientific_name' => $row['scientific_name'],
                    'measurements' => $measurements
                ]
            ];
        }
        
        echo json_encode(['success' => true, 'trees' => $trees]);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch user trees: ' . $e->getMessage()]);
    }
}

function handleGetUserFavorites($conn, $data) {
    try {
        if (!isset($data['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            return;
        }

        $sql = "SELECT t.*, tt.greek_name, tt.scientific_name, l.street_name, l.street_number, 
                u.username as inserted_by, f.created_at as favorited_at
                FROM favorite f
                JOIN tree t ON f.tree_id = t.id
                LEFT JOIN tree_type tt ON t.type_code = tt.id
                LEFT JOIN location l ON t.location_id = l.id
                LEFT JOIN user u ON t.inserted_by = u.username
                WHERE f.user_id = ?
                ORDER BY f.created_at DESC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception($conn->error);
        }

        $stmt->bind_param("i", $data['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $trees = [];
        while ($row = $result->fetch_assoc()) {
            $trees[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'lat' => $row['lat'],
                'lon' => $row['lon'],
                'inserted_at' => $row['inserted_at'],
                'inserted_by' => $row['inserted_by'],
                'url' => $row['url'],
                'favorited_at' => $row['favorited_at'],
                'type' => [
                    'greek_name' => $row['greek_name'],
                    'scientific_name' => $row['scientific_name']
                ],
                'location' => [
                    'street_name' => $row['street_name'],
                    'street_number' => $row['street_number']
                ]
            ];
        }
        
        echo json_encode(['success' => true, 'trees' => $trees]);
        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to fetch user favorites']);
    }
}

?> 