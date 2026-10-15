

<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../config/database.php";

require_once __DIR__ . "/../config/auth.php";

// =====================================

// RESPONSE AND VALIDATION HELPERS

// =====================================

function eventResponse(int $status, array $data): void {

    http_response_code($status);

    echo json_encode($data, JSON_PRETTY_PRINT);

    exit;

}

function eventValidId($value): ?int {

    $id = filter_var($value, FILTER_VALIDATE_INT);

    return ($id !== false && $id !== null && $id > 0)

        ? $id

        : null;

}

function eventJson(): array {

    $input = json_decode(

        file_get_contents("php://input"),

        true

    );

    if (!is_array($input)) {

        eventResponse(400, [

            "success" => false,

            "message" => "Invalid JSON body"

        ]);

    }

    return $input;

}

function eventValidateInput(array $input): array {

    $organiserId = eventValidId(

        $input["organiser_id"] ?? null

    );

    $venueId = eventValidId(

        $input["venue_id"] ?? null

    );

    $title = $input["title"] ?? null;

    $description = $input["description"] ?? "";

    $category = $input["category"] ?? "";

    $status = $input["status"] ?? "draft";

    if (

        !$organiserId ||

        !$venueId ||

        !is_string($title) ||

        trim($title) === "" ||

        strlen(trim($title)) > 255 ||

        !is_string($description) ||

        !is_string($category) ||

        !is_string($status) ||

        !in_array(

            $status,

            ["draft", "published", "cancelled"],

            true

        )

    ) {

        eventResponse(400, [

            "success" => false,

            "message" => "Invalid or missing event fields"

        ]);

    }

    return [

        "organiser_id" => $organiserId,

        "venue_id" => $venueId,

        "title" => trim($title),

        "description" => trim($description),

        "category" => trim($category),

        "status" => $status

    ];

}

function eventCheckOrganiser(PDO $conn, int $id): void {

    $stmt = $conn->prepare(

        "SELECT role FROM users WHERE user_id = ?"

    );

    $stmt->execute([$id]);

    if ($stmt->fetchColumn() !== "organiser") {

        eventResponse(400, [

            "success" => false,

            "message" => "Valid organiser is required"

        ]);

    }

}

function eventCheckVenue(PDO $conn, int $id): void {

    $stmt = $conn->prepare(

        "SELECT venue_id FROM venues WHERE venue_id = ?"

    );

    $stmt->execute([$id]);

    if (!$stmt->fetch()) {

        eventResponse(400, [

            "success" => false,

            "message" => "Venue does not exist"

        ]);

    }

}

// =====================================

// JWT AUTHENTICATION AND RBAC

// =====================================

$method = $_SERVER["REQUEST_METHOD"];

if (!in_array(

    $method,

    ["GET", "POST", "PUT", "DELETE"],

    true

)) {

    header("Allow: GET, POST, PUT, DELETE");

    eventResponse(405, [

        "success" => false,

        "message" => "Method not allowed"

    ]);

}

if ($method === "GET") {

    $currentUser = requireAuth([

        "admin", "organiser", "customer"

    ]);

} else {

    $currentUser = requireAuth([

        "admin", "organiser"

    ]);

}

$isAdmin = $currentUser["role"] === "admin";

$loggedInUserId = (int) $currentUser["user_id"];

// =====================================

// DATABASE CONNECTION

// =====================================

try {

    $db = new Database();

    $conn = $db->connect();

    // Confirm that the authenticated user

    // still exists with the same role.

    $authCheck = $conn->prepare(

        "SELECT role FROM users WHERE user_id = ?"

    );

    $authCheck->execute([$loggedInUserId]);

    $actualRole = $authCheck->fetchColumn();

    if (

        $actualRole === false ||

        $actualRole !== $currentUser["role"]

    ) {

        eventResponse(401, [

            "success" => false,

            "message" => "Account or role changed. Please login again"

        ]);

    }

    // =====================================

    // GET - RETRIEVE EVENTS

    // =====================================

    if ($method === "GET") {

        $sql = "SELECT

                    e.event_id,

                    e.organiser_id,

                    u.full_name AS organiser_name,

                    e.venue_id,

                    v.venue_name,

                    v.location,

                    e.title,

                    e.description,

                    e.category,

                    e.status,

                    e.created_at

                FROM events e

                INNER JOIN users u

                    ON e.organiser_id = u.user_id

                INNER JOIN venues v

                    ON e.venue_id = v.venue_id";

        if (isset($_GET["id"])) {

            $id = eventValidId($_GET["id"]);

            if (!$id) {

                eventResponse(400, [

                    "success" => false,

                    "message" => "Invalid event ID"

                ]);

            }

            $stmt = $conn->prepare(

                $sql . " WHERE e.event_id = ?"

            );

            $stmt->execute([$id]);

            $event = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$event) {

                eventResponse(404, [

                    "success" => false,

                    "message" => "Event not found"

                ]);

            }

            eventResponse(200, [

                "success" => true,

                "data" => $event

            ]);

        }

        // Pagination: GET /api/events.php?page=1&limit=2
        $page = filter_var($_GET["page"] ?? 1, FILTER_VALIDATE_INT);
        $limit = filter_var($_GET["limit"] ?? 10, FILTER_VALIDATE_INT);

        if ($page === false || $page < 1 ||
            $limit === false || $limit < 1 || $limit > 100) {
            eventResponse(400, [
                "success" => false,
                "message" => "Page must be >= 1 and limit must be 1 to 100"
            ]);
        }

        $countStmt = $conn->query("SELECT COUNT(*) FROM events");
        $total = (int) $countStmt->fetchColumn();
        $totalPages = (int) ceil($total / $limit);
        $offset = ($page - 1) * $limit;

        $stmt = $conn->prepare(
            $sql . " ORDER BY e.event_id ASC LIMIT :limit OFFSET :offset"
        );
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
        $stmt->execute();

        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        eventResponse(200, [
            "success" => true,
            "total" => $total,
            "page" => $page,
            "limit" => $limit,
            "total_pages" => $totalPages,
            "data" => $events
        ]);

    }

    // =====================================

    // POST - CREATE EVENT

    // ADMIN OR ORGANISER

    // =====================================

    if ($method === "POST") {

        $input = eventJson();

        $data = eventValidateInput($input);

        // Organisers may only create events

        // under their own organiser ID.

        if (

            !$isAdmin &&

            $data["organiser_id"] !== $loggedInUserId

        ) {

            eventResponse(403, [

                "success" => false,

                "message" => "You can only create events for your own account"

            ]);

        }

        eventCheckOrganiser(

            $conn,

            $data["organiser_id"]

        );

        eventCheckVenue(

            $conn,

            $data["venue_id"]

        );

        $stmt = $conn->prepare(

            "INSERT INTO events

             (organiser_id, venue_id, title,

              description, category, status)

             VALUES (?, ?, ?, ?, ?, ?)"

        );

        $stmt->execute([

            $data["organiser_id"],

            $data["venue_id"],

            $data["title"],

            $data["description"],

            $data["category"],

            $data["status"]

        ]);

        eventResponse(201, [

            "success" => true,

            "message" => "Event created successfully",

            "event_id" => (int) $conn->lastInsertId()

        ]);

    }

    // =====================================

    // PUT - UPDATE EVENT

    // ADMIN OR EVENT OWNER

    // =====================================

    if ($method === "PUT") {

        $id = eventValidId($_GET["id"] ?? null);

        if (!$id) {

            eventResponse(400, [

                "success" => false,

                "message" => "Valid event ID is required"

            ]);

        }

        $stmt = $conn->prepare(

            "SELECT organiser_id

             FROM events

             WHERE event_id = ?"

        );

        $stmt->execute([$id]);

        $existingOrganiserId = $stmt->fetchColumn();

        if ($existingOrganiserId === false) {

            eventResponse(404, [

                "success" => false,

                "message" => "Event not found"

            ]);

        }

        // Organisers cannot update

        // events belonging to other organisers.

        if (

            !$isAdmin &&

            (int) $existingOrganiserId !== $loggedInUserId

        ) {

            eventResponse(403, [

                "success" => false,

                "message" => "You can only update your own events"

            ]);

        }

        $input = eventJson();

        $data = eventValidateInput($input);

        // Organisers cannot transfer event

        // ownership to another account.

        if (

            !$isAdmin &&

            $data["organiser_id"] !== $loggedInUserId

        ) {

            eventResponse(403, [

                "success" => false,

                "message" => "You cannot change event ownership"

            ]);

        }

        eventCheckOrganiser(

            $conn,

            $data["organiser_id"]

        );

        eventCheckVenue(

            $conn,

            $data["venue_id"]

        );

        $stmt = $conn->prepare(

            "UPDATE events

             SET organiser_id = ?,

                 venue_id = ?,

                 title = ?,

                 description = ?,

                 category = ?,

                 status = ?

             WHERE event_id = ?"

        );

        $stmt->execute([

            $data["organiser_id"],

            $data["venue_id"],

            $data["title"],

            $data["description"],

            $data["category"],

            $data["status"],

            $id

        ]);

        eventResponse(200, [

            "success" => true,

            "message" => "Event updated successfully",

            "event_id" => $id

        ]);

    }

    // =====================================

    // DELETE - DELETE EVENT

    // ADMIN OR EVENT OWNER

    // =====================================

    if ($method === "DELETE") {

        $id = eventValidId($_GET["id"] ?? null);

        if (!$id) {

            eventResponse(400, [

                "success" => false,

                "message" => "Valid event ID is required"

            ]);

        }

        $stmt = $conn->prepare(

            "SELECT organiser_id

             FROM events

             WHERE event_id = ?"

        );

        $stmt->execute([$id]);

        $existingOrganiserId = $stmt->fetchColumn();

        if ($existingOrganiserId === false) {

            eventResponse(404, [

                "success" => false,

                "message" => "Event not found"

            ]);

        }

        if (

            !$isAdmin &&

            (int) $existingOrganiserId !== $loggedInUserId

        ) {

            eventResponse(403, [

                "success" => false,

                "message" => "You can only delete your own events"

            ]);

        }

        // Prevent deletion of events

        // that still have schedules.

        $check = $conn->prepare(

            "SELECT COUNT(*)

             FROM schedules

             WHERE event_id = ?"

        );

        $check->execute([$id]);

        if ((int) $check->fetchColumn() > 0) {

            eventResponse(409, [

                "success" => false,

                "message" => "Cannot delete event with existing schedules"

            ]);

        }

        $stmt = $conn->prepare(

            "DELETE FROM events WHERE event_id = ?"

        );

        $stmt->execute([$id]);

        eventResponse(200, [

            "success" => true,

            "message" => "Event deleted successfully",

            "event_id" => $id

        ]);

    }

} catch (PDOException $e) {

    error_log($e->getMessage());

    $status = $e->getCode() === "23000"

        ? 409

        : 500;

    eventResponse($status, [

        "success" => false,

        "message" => $status === 409

            ? "Event conflicts with related records"

            : "Internal server error"

    ]);

} catch (Throwable $e) {

    error_log($e->getMessage());

    eventResponse(500, [

        "success" => false,

        "message" => "Internal server error"

    ]);

}
