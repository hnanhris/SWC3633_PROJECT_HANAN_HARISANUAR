
<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

// =====================================
// RESPONSE HELPER
// =====================================
function userResponse(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function userValidId($value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id !== null && $id > 0)
        ? $id : null;
}

function userJson(): array {
    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        userResponse(400, [
            "success" => false,
            "message" => "Invalid JSON body"
        ]);
    }

    return $input;
}

// =====================================
// VALIDATE USER INPUT
// =====================================
function userValidate(
    array $input,
    bool $requirePassword = true,
    bool $requireRole = true
): array {

    $name = $input["full_name"] ?? null;
    $email = $input["email"] ?? null;
    $password = $input["password"] ?? null;
    $role = $input["role"] ?? null;

    if (
        !is_string($name) ||
        trim($name) === "" ||
        strlen(trim($name)) > 150 ||
        !is_string($email) ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 255
    ) {
        userResponse(400, [
            "success" => false,
            "message" => "Invalid name or email"
        ]);
    }

    if (
        $requireRole &&
        (
            !is_string($role) ||
            !in_array(
                $role,
                ["admin", "organiser", "customer"],
                true
            )
        )
    ) {
        userResponse(400, [
            "success" => false,
            "message" => "Invalid user role"
        ]);
    }

    if (
        ($requirePassword || array_key_exists("password", $input)) &&
        (
            !is_string($password) ||
            strlen($password) < 8 ||
            strlen($password) > 72
        )
    ) {
        userResponse(400, [
            "success" => false,
            "message" => "Password must contain 8 to 72 characters"
        ]);
    }

    return [
        "full_name" => trim($name),
        "email" => strtolower(trim($email)),
        "role" => $role,
        "password" => $password
    ];
}

// =====================================
// JWT AUTHENTICATION
// =====================================
$method = $_SERVER["REQUEST_METHOD"];

if (!in_array($method, ["GET", "POST", "PUT", "DELETE"], true)) {
    header("Allow: GET, POST, PUT, DELETE");

    userResponse(405, [
        "success" => false,
        "message" => "Method not allowed"
    ]);
}

// Every request requires a valid JWT.
$currentUser = requireAuth([
    "admin",
    "organiser",
    "customer"
]);

$isAdmin = $currentUser["role"] === "admin";
$loggedInUserId = $currentUser["user_id"];

// Only Admin can create or delete users.
if (
    in_array($method, ["POST", "DELETE"], true) &&
    !$isAdmin
) {
    userResponse(403, [
        "success" => false,
        "message" => "Access denied for this role"
    ]);
}

try {
    $db = new Database();
    $conn = $db->connect();

    // Recheck user against the database.
    // Prevent deleted accounts from using old JWTs.
    $authCheck = $conn->prepare(
        "SELECT role FROM users WHERE user_id = ?"
    );
    $authCheck->execute([$loggedInUserId]);

    $actualRole = $authCheck->fetchColumn();

    if (
        $actualRole === false ||
        $actualRole !== $currentUser["role"]
    ) {
        userResponse(401, [
            "success" => false,
            "message" => "Account or role has changed. Please login again"
        ]);
    }

    // =====================================
    // GET - RETRIEVE USERS
    // =====================================
    if ($method === "GET") {

        $sql = "SELECT user_id, full_name, email,
                       role, created_at
                FROM users";

        if (isset($_GET["id"])) {

            $id = userValidId($_GET["id"]);

            if (!$id) {
                userResponse(400, [
                    "success" => false,
                    "message" => "Invalid user ID"
                ]);
            }

            // Non-admin users can only view themselves.
            if (!$isAdmin && $id !== $loggedInUserId) {
                userResponse(403, [
                    "success" => false,
                    "message" => "You can only view your own account"
                ]);
            }

            $stmt = $conn->prepare(
                $sql . " WHERE user_id = ?"
            );
            $stmt->execute([$id]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                userResponse(404, [
                    "success" => false,
                    "message" => "User not found"
                ]);
            }

            userResponse(200, [
                "success" => true,
                "data" => $user
            ]);
        }

        // Only Admin can list all users.
        if (!$isAdmin) {
            userResponse(403, [
                "success" => false,
                "message" => "Only administrators can list all users"
            ]);
        }

        $stmt = $conn->query(
            $sql . " ORDER BY user_id ASC"
        );

        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        userResponse(200, [
            "success" => true,
            "total" => count($users),
            "data" => $users
        ]);
    }

    // =====================================
    // POST - CREATE USER (ADMIN ONLY)
    // =====================================
    if ($method === "POST") {

        $input = userJson();
        $data = userValidate($input, true, true);

        $check = $conn->prepare(
            "SELECT user_id FROM users WHERE email = ?"
        );
        $check->execute([$data["email"]]);

        if ($check->fetch()) {
            userResponse(409, [
                "success" => false,
                "message" => "Email already registered"
            ]);
        }

        $hash = password_hash(
            $data["password"],
            PASSWORD_DEFAULT
        );

        $stmt = $conn->prepare(
            "INSERT INTO users
             (full_name, email, password_hash, role)
             VALUES (?, ?, ?, ?)"
        );

        $stmt->execute([
            $data["full_name"],
            $data["email"],
            $hash,
            $data["role"]
        ]);

        userResponse(201, [
            "success" => true,
            "message" => "User created successfully",
            "user_id" => (int) $conn->lastInsertId()
        ]);
    }

    // =====================================
    // PUT - UPDATE USER
    // =====================================
    if ($method === "PUT") {

        $id = userValidId($_GET["id"] ?? null);

        if (!$id) {
            userResponse(400, [
                "success" => false,
                "message" => "Valid user ID is required"
            ]);
        }

        // Non-admin users can update only themselves.
        if (!$isAdmin && $id !== $loggedInUserId) {
            userResponse(403, [
                "success" => false,
                "message" => "You can only update your own account"
            ]);
        }

        $input = userJson();

        // Non-admin users cannot submit a role change.
        if (!$isAdmin && array_key_exists("role", $input)) {
            userResponse(403, [
                "success" => false,
                "message" => "Only administrators can change user roles"
            ]);
        }

        $data = userValidate($input, false, $isAdmin);

        $check = $conn->prepare(
            "SELECT role FROM users WHERE user_id = ?"
        );
        $check->execute([$id]);

        $existingRole = $check->fetchColumn();

        if ($existingRole === false) {
            userResponse(404, [
                "success" => false,
                "message" => "User not found"
            ]);
        }

        // Preserve role when the user is not Admin.
        $newRole = $isAdmin
            ? $data["role"]
            : $existingRole;

        $check = $conn->prepare(
            "SELECT user_id
             FROM users
             WHERE email = ? AND user_id <> ?"
        );
        $check->execute([$data["email"], $id]);

        if ($check->fetch()) {
            userResponse(409, [
                "success" => false,
                "message" => "Email already registered"
            ]);
        }

        if ($data["password"] !== null) {

            $hash = password_hash(
                $data["password"],
                PASSWORD_DEFAULT
            );

            $stmt = $conn->prepare(
                "UPDATE users
                 SET full_name = ?,
                     email = ?,
                     role = ?,
                     password_hash = ?
                 WHERE user_id = ?"
            );

            $stmt->execute([
                $data["full_name"],
                $data["email"],
                $newRole,
                $hash,
                $id
            ]);

        } else {

            $stmt = $conn->prepare(
                "UPDATE users
                 SET full_name = ?,
                     email = ?,
                     role = ?
                 WHERE user_id = ?"
            );

            $stmt->execute([
                $data["full_name"],
                $data["email"],
                $newRole,
                $id
            ]);
        }

        userResponse(200, [
            "success" => true,
            "message" => "User updated successfully",
            "user_id" => $id
        ]);
    }

    // =====================================
    // DELETE - DELETE USER (ADMIN ONLY)
    // =====================================
    if ($method === "DELETE") {

        $id = userValidId($_GET["id"] ?? null);

        if (!$id) {
            userResponse(400, [
                "success" => false,
                "message" => "Valid user ID is required"
            ]);
        }

        // Prevent an administrator from deleting themselves.
        if ($id === $loggedInUserId) {
            userResponse(403, [
                "success" => false,
                "message" => "You cannot delete your own admin account"
            ]);
        }

        $check = $conn->prepare(
            "SELECT user_id FROM users WHERE user_id = ?"
        );
        $check->execute([$id]);

        if (!$check->fetch()) {
            userResponse(404, [
                "success" => false,
                "message" => "User not found"
            ]);
        }

        $check = $conn->prepare(
            "SELECT COUNT(*)
             FROM events WHERE organiser_id = ?"
        );
        $check->execute([$id]);

        if ((int) $check->fetchColumn() > 0) {
            userResponse(409, [
                "success" => false,
                "message" => "Cannot delete user linked to events"
            ]);
        }

        $check = $conn->prepare(
            "SELECT COUNT(*)
             FROM bookings WHERE user_id = ?"
        );
        $check->execute([$id]);

        if ((int) $check->fetchColumn() > 0) {
            userResponse(409, [
                "success" => false,
                "message" => "Cannot delete user linked to bookings"
            ]);
        }

        $stmt = $conn->prepare(
            "DELETE FROM users WHERE user_id = ?"
        );
        $stmt->execute([$id]);

        userResponse(200, [
            "success" => true,
            "message" => "User deleted successfully",
            "user_id" => $id
        ]);
    }

} catch (PDOException $e) {

    error_log($e->getMessage());

    $status = $e->getCode() === "23000" ? 409 : 500;

    userResponse($status, [
        "success" => false,
        "message" => $status === 409
            ? "User conflicts with existing records"
            : "Internal server error"
    ]);

} catch (Throwable $e) {

    error_log($e->getMessage());

    userResponse(500, [
        "success" => false,
        "message" => "Internal server error"
    ]);
}
