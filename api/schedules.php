
<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

// =====================================
// RESPONSE HELPER
// =====================================
function scheduleResponse(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function scheduleValidId($value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id !== null && $id > 0)
        ? $id : null;
}

function scheduleJson(): array {
    $input = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($input)) {
        scheduleResponse(400, [
            "success" => false,
            "message" => "Invalid JSON body"
        ]);
    }

    return $input;
}

// =====================================
// SCHEDULE VALIDATION
// =====================================
function scheduleInput($input): array {

    if (!is_array($input)) {
        scheduleResponse(400, [
            "success" => false,
            "message" => "Invalid JSON body"
        ]);
    }

    $eventId = scheduleValidId(
        $input["event_id"] ?? null
    );

    $start = $input["start_time"] ?? null;
    $end = $input["end_time"] ?? null;

    $total = filter_var(
        $input["total_tickets"] ?? null,
        FILTER_VALIDATE_INT
    );

    $available = filter_var(
        $input["available_tickets"] ?? null,
        FILTER_VALIDATE_INT
    );

    $price = $input["ticket_price"] ?? null;

    if (
        !$eventId ||
        !is_string($start) ||
        !is_string($end) ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $start
        ) ||
        !preg_match(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $end
        ) ||
        $total === false ||
        $total === null ||
        $total <= 0 ||
        $available === false ||
        $available === null ||
        $available < 0 ||
        $available > $total ||
        !is_numeric($price) ||
        (float)$price < 0
    ) {
        scheduleResponse(400, [
            "success" => false,
            "message" => "Invalid or missing schedule fields"
        ]);
    }

    $startDate = DateTimeImmutable::createFromFormat(
        "!Y-m-d H:i:s",
        $start
    );

    $endDate = DateTimeImmutable::createFromFormat(
        "!Y-m-d H:i:s",
        $end
    );

    if (
        !$startDate ||
        !$endDate ||
        $startDate->format("Y-m-d H:i:s") !== $start ||
        $endDate->format("Y-m-d H:i:s") !== $end ||
        $endDate <= $startDate
    ) {
        scheduleResponse(400, [
            "success" => false,
            "message" => "End time must be after start time"
        ]);
    }

    return [
        "event_id" => $eventId,
        "start_time" => $start,
        "end_time" => $end,
        "total_tickets" => $total,
        "available_tickets" => $available,
        "ticket_price" => (float)$price
    ];
}

// =====================================
// JWT AUTHENTICATION
// =====================================
$method = $_SERVER["REQUEST_METHOD"];

if (!in_array(
    $method,
    ["GET", "POST", "PUT", "DELETE"],
    true
)) {
    header("Allow: GET, POST, PUT, DELETE");

    scheduleResponse(405, [
        "success" => false,
        "message" => "Method not allowed"
    ]);
}

$currentUser = ($method === "GET")
    ? requireAuth(["admin", "organiser", "customer"])
    : requireAuth(["admin", "organiser"]);

$isAdmin = $currentUser["role"] === "admin";
$loggedInUserId = (int)$currentUser["user_id"];

try {

    $db = new Database();
    $conn = $db->connect();

    // Verify account and role are still valid.
    $authCheck = $conn->prepare(
        "SELECT role FROM users WHERE user_id = ?"
    );
    $authCheck->execute([$loggedInUserId]);

    $actualRole = $authCheck->fetchColumn();

    if (
        $actualRole === false ||
        $actualRole !== $currentUser["role"]
    ) {
        scheduleResponse(401, [
            "success" => false,
            "message" => "Account or role changed. Please login again"
        ]);
    }

    // =====================================
    // GET - ALL OR ONE SCHEDULE
    // =====================================
    if ($method === "GET") {

        $sql = "SELECT s.*, e.title AS event_title
                FROM schedules s
                INNER JOIN events e
                    ON s.event_id = e.event_id";

        if (isset($_GET["id"])) {

            $id = scheduleValidId($_GET["id"]);

            if (!$id) {
                scheduleResponse(400, [
                    "success" => false,
                    "message" => "Invalid schedule ID"
                ]);
            }

            $stmt = $conn->prepare(
                $sql . " WHERE s.schedule_id = ?"
            );

            $stmt->execute([$id]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$schedule) {
                scheduleResponse(404, [
                    "success" => false,
                    "message" => "Schedule not found"
                ]);
            }

            scheduleResponse(200, [
                "success" => true,
                "data" => $schedule
            ]);
        }

        $stmt = $conn->query(
            $sql . " ORDER BY s.schedule_id ASC"
        );

        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        scheduleResponse(200, [
            "success" => true,
            "total" => count($schedules),
            "data" => $schedules
        ]);
    }

    // =====================================
    // POST - CREATE SCHEDULE
    // =====================================
    if ($method === "POST") {

        $input = scheduleJson();
        $data = scheduleInput($input);

        // Find the owner of the selected event.
        $check = $conn->prepare(
            "SELECT organiser_id
             FROM events
             WHERE event_id = ?"
        );
        $check->execute([$data["event_id"]]);

        $eventOwner = $check->fetchColumn();

        if ($eventOwner === false) {
            scheduleResponse(400, [
                "success" => false,
                "message" => "Event does not exist"
            ]);
        }

        if (
            !$isAdmin &&
            (int)$eventOwner !== $loggedInUserId
        ) {
            scheduleResponse(403, [
                "success" => false,
                "message" => "You can only manage schedules for your own events"
            ]);
        }

        $stmt = $conn->prepare(
            "INSERT INTO schedules
             (event_id, start_time, end_time,
              total_tickets, available_tickets, ticket_price)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $data["event_id"],
            $data["start_time"],
            $data["end_time"],
            $data["total_tickets"],
            $data["available_tickets"],
            $data["ticket_price"]
        ]);

        scheduleResponse(201, [
            "success" => true,
            "message" => "Schedule created successfully",
            "schedule_id" => (int)$conn->lastInsertId()
        ]);
    }

    // =====================================
    // PUT - UPDATE SCHEDULE
    // =====================================
    if ($method === "PUT") {

        $id = scheduleValidId($_GET["id"] ?? null);

        if (!$id) {
            scheduleResponse(400, [
                "success" => false,
                "message" => "Valid schedule ID is required"
            ]);
        }

        // Check existing schedule ownership.
        $check = $conn->prepare(
            "SELECT e.organiser_id
             FROM schedules s
             INNER JOIN events e
                 ON s.event_id = e.event_id
             WHERE s.schedule_id = ?"
        );
        $check->execute([$id]);

        $existingOwner = $check->fetchColumn();

        if ($existingOwner === false) {
            scheduleResponse(404, [
                "success" => false,
                "message" => "Schedule not found"
            ]);
        }

        if (
            !$isAdmin &&
            (int)$existingOwner !== $loggedInUserId
        ) {
            scheduleResponse(403, [
                "success" => false,
                "message" => "You can only update schedules for your own events"
            ]);
        }

        $input = scheduleJson();
        $data = scheduleInput($input);

        // Check ownership of the destination event.
        $check = $conn->prepare(
            "SELECT organiser_id
             FROM events
             WHERE event_id = ?"
        );
        $check->execute([$data["event_id"]]);

        $newEventOwner = $check->fetchColumn();

        if ($newEventOwner === false) {
            scheduleResponse(400, [
                "success" => false,
                "message" => "Event does not exist"
            ]);
        }

        if (
            !$isAdmin &&
            (int)$newEventOwner !== $loggedInUserId
        ) {
            scheduleResponse(403, [
                "success" => false,
                "message" => "You cannot move a schedule to another organiser's event"
            ]);
        }

        // Preserve original ticket-stock validation.
        $check = $conn->prepare(
            "SELECT COALESCE(SUM(quantity), 0)
             FROM bookings
             WHERE schedule_id = ?
               AND booking_status = 'confirmed'"
        );
        $check->execute([$id]);

        $booked = (int)$check->fetchColumn();

        if (
            $booked > 0 &&
            (
                $data["total_tickets"] < $booked ||
                $data["available_tickets"] !==
                    $data["total_tickets"] - $booked
            )
        ) {
            scheduleResponse(409, [
                "success" => false,
                "message" => "Ticket counts conflict with confirmed bookings"
            ]);
        }

        $stmt = $conn->prepare(
            "UPDATE schedules
             SET event_id = ?,
                 start_time = ?,
                 end_time = ?,
                 total_tickets = ?,
                 available_tickets = ?,
                 ticket_price = ?
             WHERE schedule_id = ?"
        );

        $stmt->execute([
            $data["event_id"],
            $data["start_time"],
            $data["end_time"],
            $data["total_tickets"],
            $data["available_tickets"],
            $data["ticket_price"],
            $id
        ]);

        scheduleResponse(200, [
            "success" => true,
            "message" => "Schedule updated successfully",
            "schedule_id" => $id
        ]);
    }

    // =====================================
    // DELETE - DELETE SCHEDULE
    // =====================================
    if ($method === "DELETE") {

        $id = scheduleValidId($_GET["id"] ?? null);

        if (!$id) {
            scheduleResponse(400, [
                "success" => false,
                "message" => "Valid schedule ID is required"
            ]);
        }

        $check = $conn->prepare(
            "SELECT e.organiser_id
             FROM schedules s
             INNER JOIN events e
                 ON s.event_id = e.event_id
             WHERE s.schedule_id = ?"
        );
        $check->execute([$id]);

        $existingOwner = $check->fetchColumn();

        if ($existingOwner === false) {
            scheduleResponse(404, [
                "success" => false,
                "message" => "Schedule not found"
            ]);
        }

        if (
            !$isAdmin &&
            (int)$existingOwner !== $loggedInUserId
        ) {
            scheduleResponse(403, [
                "success" => false,
                "message" => "You can only delete schedules for your own events"
            ]);
        }

        // Prevent deleting schedules with bookings.
        $check = $conn->prepare(
            "SELECT COUNT(*)
             FROM bookings
             WHERE schedule_id = ?"
        );
        $check->execute([$id]);

        if ((int)$check->fetchColumn() > 0) {
            scheduleResponse(409, [
                "success" => false,
                "message" => "Cannot delete schedule with existing bookings"
            ]);
        }

        $stmt = $conn->prepare(
            "DELETE FROM schedules
             WHERE schedule_id = ?"
        );
        $stmt->execute([$id]);

        scheduleResponse(200, [
            "success" => true,
            "message" => "Schedule deleted successfully",
            "schedule_id" => $id
        ]);
    }

} catch (PDOException $e) {

    error_log($e->getMessage());

    $status = $e->getCode() === "23000"
        ? 409
        : 500;

    scheduleResponse($status, [
        "success" => false,
        "message" => $status === 409
            ? "Schedule conflicts with related records"
            : "Internal server error"
    ]);

} catch (Throwable $e) {

    error_log($e->getMessage());

    scheduleResponse(500, [
        "success" => false,
        "message" => "Internal server error"
    ]);
}
