
<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// ---------------------------------------
// JWT AUTHENTICATION + ROLE PERMISSIONS
// ---------------------------------------

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // All authenticated roles can view venues
    $currentUser = requireAuth([
        'admin',
        'organiser',
        'customer'
    ]);

} elseif (in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    // Only admin can manage venues
    $currentUser = requireAuth(['admin']);

} else {
    header('Allow: GET, POST, PUT, DELETE');
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// ---------------------------------------
// DATABASE CONNECTION
// ---------------------------------------

try {
    $db = new Database();
    $conn = $db->connect();
} catch (Throwable $e) {
    error_log('Database connection error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]);
    exit;
}

// ---------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------

function venueResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getVenueInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        venueResponse(400, [
            'success' => false,
            'message' => 'Invalid JSON body'
        ]);
    }

    return $input;
}

function getVenueId(): int
{
    $id = filter_var(
        $_GET['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($id === false || $id === null) {
        venueResponse(400, [
            'success' => false,
            'message' => 'A valid venue ID is required'
        ]);
    }

    return $id;
}

function venueExists(PDO $conn, int $id): bool
{
    $stmt = $conn->prepare(
        'SELECT venue_id FROM venues WHERE venue_id = ?'
    );
    $stmt->execute([$id]);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---------------------------------------
// GET - VIEW VENUES
// ---------------------------------------

try {

    if ($method === 'GET') {

        if (isset($_GET['id'])) {
            $id = getVenueId();

            $stmt = $conn->prepare(
                'SELECT * FROM venues WHERE venue_id = ?'
            );
            $stmt->execute([$id]);

            $venue = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$venue) {
                venueResponse(404, [
                    'success' => false,
                    'message' => 'Venue not found'
                ]);
            }

            venueResponse(200, [
                'success' => true,
                'data' => $venue
            ]);
        }

        $stmt = $conn->query(
            'SELECT * FROM venues ORDER BY venue_id ASC'
        );

        venueResponse(200, [
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }

    // ---------------------------------------
    // POST - CREATE VENUE (ADMIN ONLY)
    // ---------------------------------------

    if ($method === 'POST') {

        $input = getVenueInput();

        $name = trim((string) ($input['venue_name'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        $capacity = filter_var(
            $input['capacity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($name === '' || $location === '' || $capacity === false) {
            venueResponse(400, [
                'success' => false,
                'message' => 'Venue name, location and valid capacity are required'
            ]);
        }

        $stmt = $conn->prepare(
            'INSERT INTO venues
             (venue_name, location, capacity, description)
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $name,
            $location,
            $capacity,
            $description
        ]);

        venueResponse(201, [
            'success' => true,
            'message' => 'Venue created successfully',
            'venue_id' => (int) $conn->lastInsertId()
        ]);
    }

    // ---------------------------------------
    // PUT - UPDATE VENUE (ADMIN ONLY)
    // ---------------------------------------

    if ($method === 'PUT') {

        $id = getVenueId();
        $input = getVenueInput();

        if (!venueExists($conn, $id)) {
            venueResponse(404, [
                'success' => false,
                'message' => 'Venue not found'
            ]);
        }

        $name = trim((string) ($input['venue_name'] ?? ''));
        $location = trim((string) ($input['location'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        $capacity = filter_var(
            $input['capacity'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($name === '' || $location === '' || $capacity === false) {
            venueResponse(400, [
                'success' => false,
                'message' => 'Venue name, location and valid capacity are required'
            ]);
        }

        $stmt = $conn->prepare(
            'UPDATE venues
             SET venue_name = ?,
                 location = ?,
                 capacity = ?,
                 description = ?
             WHERE venue_id = ?'
        );

        $stmt->execute([
            $name,
            $location,
            $capacity,
            $description,
            $id
        ]);

        venueResponse(200, [
            'success' => true,
            'message' => 'Venue updated successfully',
            'venue_id' => $id
        ]);
    }

    // ---------------------------------------
    // DELETE - REMOVE VENUE (ADMIN ONLY)
    // ---------------------------------------

    if ($method === 'DELETE') {

        $id = getVenueId();

        if (!venueExists($conn, $id)) {
            venueResponse(404, [
                'success' => false,
                'message' => 'Venue not found'
            ]);
        }

        // Prevent deleting venues linked to events
        $check = $conn->prepare(
            'SELECT COUNT(*) FROM events WHERE venue_id = ?'
        );
        $check->execute([$id]);

        if ((int) $check->fetchColumn() > 0) {
            venueResponse(409, [
                'success' => false,
                'message' => 'Cannot delete venue because it is linked to existing events'
            ]);
        }

        $stmt = $conn->prepare(
            'DELETE FROM venues WHERE venue_id = ?'
        );
        $stmt->execute([$id]);

        venueResponse(200, [
            'success' => true,
            'message' => 'Venue deleted successfully',
            'venue_id' => $id
        ]);
    }

} catch (PDOException $e) {

    error_log('Venues API database error: ' . $e->getMessage());

    venueResponse(500, [
        'success' => false,
        'message' => 'Internal database error'
    ]);

} catch (Throwable $e) {

    error_log('Venues API error: ' . $e->getMessage());

    venueResponse(500, [
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
