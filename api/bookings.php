
<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../config/auth.php";

function bookingResponse(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

function bookingId($value): ?int {
    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id !== null && $id > 0)
        ? $id : null;
}

function bookingJson(): array {
    $data = json_decode(
        file_get_contents("php://input"),
        true
    );

    if (!is_array($data)) {
        bookingResponse(400, [
            "success" => false,
            "message" => "Invalid JSON body"
        ]);
    }

    return $data;
}

function getLockedSchedule(PDO $conn, int $id) {
    $stmt = $conn->prepare(
        "SELECT schedule_id, available_tickets,
                ticket_price
         FROM schedules
         WHERE schedule_id = ?
         FOR UPDATE"
    );
    $stmt->execute([$id]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function changeStock(
    PDO $conn,
    int $scheduleId,
    int $difference
): bool {
    $stmt = $conn->prepare(
        "UPDATE schedules
         SET available_tickets = available_tickets + ?
         WHERE schedule_id = ?
           AND available_tickets + ? >= 0
           AND available_tickets + ? <= total_tickets"
    );

    $stmt->execute([
        $difference,
        $scheduleId,
        $difference,
        $difference
    ]);

    return $stmt->rowCount() === 1;
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

    bookingResponse(405, [
        "success" => false,
        "message" => "Method not allowed"
    ]);
}

$currentUser = requireAuth(
    ["admin", "organiser", "customer"]
);

$userId = (int)$currentUser["user_id"];
$role = $currentUser["role"];

try {

    $db = new Database();
    $conn = $db->connect();

    // Check that the account and role are still valid.
    $check = $conn->prepare(
        "SELECT role FROM users WHERE user_id = ?"
    );
    $check->execute([$userId]);

    $actualRole = $check->fetchColumn();

    if (
        $actualRole === false ||
        $actualRole !== $role
    ) {
        bookingResponse(401, [
            "success" => false,
            "message" => "Account or role changed. Please login again"
        ]);
    }

    // Organisers may view bookings, but cannot
    // create, update or delete bookings.
    if (
        $role === "organiser" &&
        $method !== "GET"
    ) {
        bookingResponse(403, [
            "success" => false,
            "message" => "Access denied for this role"
        ]);
    }

    // =====================================
    // GET - VIEW BOOKINGS
    // =====================================

    if ($method === "GET") {

        $sql = "SELECT b.booking_id,
                       b.user_id,
                       u.full_name AS customer_name,
                       b.schedule_id,
                       e.title AS event_title,
                       s.start_time,
                       b.quantity,
                       b.total_price,
                       b.booking_status,
                       b.booked_at
                FROM bookings b
                JOIN users u
                    ON b.user_id = u.user_id
                JOIN schedules s
                    ON b.schedule_id = s.schedule_id
                JOIN events e
                    ON s.event_id = e.event_id";

        $conditions = [];
        $params = [];

        if (isset($_GET["id"])) {
            $id = bookingId($_GET["id"]);

            if (!$id) {
                bookingResponse(400, [
                    "success" => false,
                    "message" => "Invalid booking ID"
                ]);
            }

            $conditions[] = "b.booking_id = ?";
            $params[] = $id;
        }

        if ($role === "customer") {
            $conditions[] = "b.user_id = ?";
            $params[] = $userId;
        }

        if ($role === "organiser") {
            $conditions[] = "e.organiser_id = ?";
            $params[] = $userId;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " .
                implode(" AND ", $conditions);
        }

        $sql .= " ORDER BY b.booking_id ASC";

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);

        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (isset($_GET["id"])) {

            if (!$bookings) {
                bookingResponse(404, [
                    "success" => false,
                    "message" => "Booking not found"
                ]);
            }

            bookingResponse(200, [
                "success" => true,
                "data" => $bookings[0]
            ]);
        }

        bookingResponse(200, [
            "success" => true,
            "total" => count($bookings),
            "data" => $bookings
        ]);
    }

    // =====================================
    // POST - CREATE BOOKING
    // =====================================

    if ($method === "POST") {

        $input = bookingJson();

        $requestedUserId = bookingId(
            $input["user_id"] ?? null
        );

        $scheduleId = bookingId(
            $input["schedule_id"] ?? null
        );

        $quantity = bookingId(
            $input["quantity"] ?? null
        );

        if (
            !$requestedUserId ||
            !$scheduleId ||
            !$quantity
        ) {
            bookingResponse(400, [
                "success" => false,
                "message" => "Valid user, schedule and quantity are required"
            ]);
        }

        // Customers cannot book using another user's ID.
        if (
            $role === "customer" &&
            $requestedUserId !== $userId
        ) {
            bookingResponse(403, [
                "success" => false,
                "message" => "You can only create bookings for yourself"
            ]);
        }

        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "SELECT role FROM users WHERE user_id = ?"
        );
        $stmt->execute([$requestedUserId]);

        if ($stmt->fetchColumn() !== "customer") {
            $conn->rollBack();

            bookingResponse(400, [
                "success" => false,
                "message" => "Valid customer is required"
            ]);
        }

        $schedule = getLockedSchedule(
            $conn,
            $scheduleId
        );

        if (!$schedule) {
            $conn->rollBack();

            bookingResponse(404, [
                "success" => false,
                "message" => "Schedule not found"
            ]);
        }

        if (
            (int)$schedule["available_tickets"] <
            $quantity
        ) {
            $conn->rollBack();

            bookingResponse(409, [
                "success" => false,
                "message" => "Not enough tickets available"
            ]);
        }

        $totalPrice = round(
            $quantity * (float)$schedule["ticket_price"],
            2
        );

        if (!changeStock(
            $conn,
            $scheduleId,
            -$quantity
        )) {
            $conn->rollBack();

            bookingResponse(409, [
                "success" => false,
                "message" => "Unable to reserve tickets"
            ]);
        }

        $stmt = $conn->prepare(
            "INSERT INTO bookings
             (user_id, schedule_id, quantity,
              total_price, booking_status)
             VALUES (?, ?, ?, ?, 'confirmed')"
        );

        $stmt->execute([
            $requestedUserId,
            $scheduleId,
            $quantity,
            $totalPrice
        ]);

        $newId = (int)$conn->lastInsertId();

        $conn->commit();

        bookingResponse(201, [
            "success" => true,
            "message" => "Booking created successfully",
            "booking_id" => $newId,
            "total_price" => $totalPrice
        ]);
    }

    // =====================================
    // PUT - UPDATE BOOKING
    // =====================================

    if ($method === "PUT") {

        $id = bookingId($_GET["id"] ?? null);

        if (!$id) {
            bookingResponse(400, [
                "success" => false,
                "message" => "Valid booking ID is required"
            ]);
        }

        $input = bookingJson();

        $quantity = bookingId(
            $input["quantity"] ?? null
        );

        $status = $input["booking_status"] ?? null;

        if (
            !$quantity ||
            !is_string($status) ||
            !in_array(
                $status,
                ["confirmed", "cancelled"],
                true
            )
        ) {
            bookingResponse(400, [
                "success" => false,
                "message" => "Valid quantity and booking_status are required"
            ]);
        }

        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "SELECT *
             FROM bookings
             WHERE booking_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$id]);

        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $conn->rollBack();

            bookingResponse(404, [
                "success" => false,
                "message" => "Booking not found"
            ]);
        }

        // Customer may update only their own booking.
        if (
            $role === "customer" &&
            (int)$booking["user_id"] !== $userId
        ) {
            $conn->rollBack();

            bookingResponse(403, [
                "success" => false,
                "message" => "You can only update your own bookings"
            ]);
        }

        $scheduleId = (int)$booking["schedule_id"];

        $schedule = getLockedSchedule(
            $conn,
            $scheduleId
        );

        if (!$schedule) {
            $conn->rollBack();

            bookingResponse(404, [
                "success" => false,
                "message" => "Schedule not found"
            ]);
        }

        $oldReserved = (
            $booking["booking_status"] === "confirmed"
        ) ? (int)$booking["quantity"] : 0;

        $newReserved = (
            $status === "confirmed"
        ) ? $quantity : 0;

        $difference = $oldReserved - $newReserved;

        if (
            $difference !== 0 &&
            !changeStock(
                $conn,
                $scheduleId,
                $difference
            )
        ) {
            $conn->rollBack();

            bookingResponse(409, [
                "success" => false,
                "message" => "Insufficient ticket availability"
            ]);
        }

        $totalPrice = round(
            $quantity * (float)$schedule["ticket_price"],
            2
        );

        $stmt = $conn->prepare(
            "UPDATE bookings
             SET quantity = ?,
                 total_price = ?,
                 booking_status = ?
             WHERE booking_id = ?"
        );

        $stmt->execute([
            $quantity,
            $totalPrice,
            $status,
            $id
        ]);

        $conn->commit();

        bookingResponse(200, [
            "success" => true,
            "message" => "Booking updated successfully",
            "booking_id" => $id,
            "booking_status" => $status,
            "total_price" => $totalPrice
        ]);
    }

    // =====================================
    // DELETE - DELETE BOOKING
    // =====================================

    if ($method === "DELETE") {

        $id = bookingId($_GET["id"] ?? null);

        if (!$id) {
            bookingResponse(400, [
                "success" => false,
                "message" => "Valid booking ID is required"
            ]);
        }

        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "SELECT *
             FROM bookings
             WHERE booking_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$id]);

        $booking = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$booking) {
            $conn->rollBack();

            bookingResponse(404, [
                "success" => false,
                "message" => "Booking not found"
            ]);
        }

        // Customer may delete only their own booking.
        if (
            $role === "customer" &&
            (int)$booking["user_id"] !== $userId
        ) {
            $conn->rollBack();

            bookingResponse(403, [
                "success" => false,
                "message" => "You can only delete your own bookings"
            ]);
        }

        if ($booking["booking_status"] === "confirmed") {

            $schedule = getLockedSchedule(
                $conn,
                (int)$booking["schedule_id"]
            );

            if (
                !$schedule ||
                !changeStock(
                    $conn,
                    (int)$booking["schedule_id"],
                    (int)$booking["quantity"]
                )
            ) {
                $conn->rollBack();

                bookingResponse(409, [
                    "success" => false,
                    "message" => "Unable to restore tickets"
                ]);
            }
        }

        $stmt = $conn->prepare(
            "DELETE FROM bookings WHERE booking_id = ?"
        );
        $stmt->execute([$id]);

        $conn->commit();

        bookingResponse(200, [
            "success" => true,
            "message" => "Booking deleted successfully",
            "booking_id" => $id
        ]);
    }

} catch (Throwable $e) {

    if (
        isset($conn) &&
        $conn->inTransaction()
    ) {
        $conn->rollBack();
    }

    error_log($e->getMessage());

    bookingResponse(500, [
        "success" => false,
        "message" => "Internal server error"
    ]);
}
