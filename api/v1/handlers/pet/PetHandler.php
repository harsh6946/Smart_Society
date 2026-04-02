<?php
/**
 * Securis Smart Society Platform — Pet Registration Handler
 * Manages pet registration, updates, and listing for society residents.
 */

require_once __DIR__ . '/../../../../include/security.php';
require_once __DIR__ . '/../../../../include/helpers.php';

class PetHandler {
    private $conn;
    private $auth;
    private $input;
    private $user;
    private $societyId;

    public function __construct($conn, $auth, $input) {
        $this->conn = $conn;
        $this->auth = $auth;
        $this->input = $input;
    }

    public function handle($method, $action, $id) {
        $this->user = $this->auth->authenticate();
        $this->societyId = $this->auth->requireSociety();

        switch ($method) {
            case 'GET':
                if ($action === 'my') {
                    // GET /pets/my
                    $this->listMyPets();
                } elseif ($action === 'society') {
                    // GET /pets/society
                    $this->listSocietyPets();
                } elseif ($id) {
                    // GET /pets/{id}
                    $this->getPet($id);
                } else {
                    // GET /pets
                    $this->listPets();
                }
                break;

            case 'POST':
                // POST /pets
                $this->createPet();
                break;

            case 'PUT':
                if (!$id) {
                    ApiResponse::error('Pet ID is required', 400);
                }
                // PUT /pets/{id}
                $this->updatePet($id);
                break;

            case 'DELETE':
                if (!$id) {
                    ApiResponse::error('Pet ID is required', 400);
                }
                // DELETE /pets/{id}
                $this->deletePet($id);
                break;

            default:
                ApiResponse::error('Method not allowed', 405);
        }
    }

    // ---------------------------------------------------------------
    //  GET /pets
    // ---------------------------------------------------------------
    private function listPets() {
        $page = getPage($this->input);
        $perPage = getPerPage($this->input);
        $offset = getOffset($page, $perPage);

        $where = "r.society_id = ?";
        $params = [$this->societyId];
        $types = 'i';

        // Filter by species
        if (!empty($this->input['species'])) {
            $species = sanitizeInput($this->input['species']);
            $allowedSpecies = ['dog', 'cat', 'bird', 'fish', 'rabbit', 'other'];
            if (in_array($species, $allowedSpecies)) {
                $where .= " AND p.species = ?";
                $params[] = $species;
                $types .= 's';
            }
        }

        // Count total
        $countStmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             WHERE $where"
        );
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = $countStmt->get_result()->fetch_assoc()['total'];
        $countStmt->close();

        // Fetch pets
        $sql = "SELECT p.id, p.resident_id, p.name, p.species, p.breed, p.age_years,
                       p.color, p.photo, p.vaccination_json, p.registration_number,
                       p.is_neutered, p.notes, p.created_at,
                       u.name AS owner_name, u.avatar AS owner_avatar,
                       f.flat_number, t.name AS tower_name
                FROM tbl_pet p
                INNER JOIN tbl_resident r ON r.id = p.resident_id
                LEFT JOIN tbl_user u ON u.id = r.user_id
                LEFT JOIN tbl_flat f ON f.id = r.flat_id
                LEFT JOIN tbl_tower t ON t.id = f.tower_id
                WHERE $where
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $perPage;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $pets = [];
        while ($row = $result->fetch_assoc()) {
            $pets[] = $this->formatPet($row);
        }
        $stmt->close();

        ApiResponse::paginated($pets, $total, $page, $perPage, 'Pets retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  GET /pets/society — all pets in the society
    // ---------------------------------------------------------------
    private function listSocietyPets() {
        $stmt = $this->conn->prepare(
            "SELECT p.*, u.name AS owner_name, f.flat_number, t.name AS tower_name
             FROM tbl_pet p
             JOIN tbl_resident r ON r.id = p.resident_id
             JOIN tbl_user u ON r.user_id = u.id
             JOIN tbl_flat f ON r.flat_id = f.id
             JOIN tbl_tower t ON f.tower_id = t.id
             WHERE r.society_id = ? AND r.status = 'approved'
             ORDER BY p.name"
        );
        $stmt->bind_param('i', $this->societyId);
        $stmt->execute();
        $pets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($pets as &$pet) {
            $pet['id'] = (int)$pet['id'];
            $pet['owner'] = $pet['owner_name'];
            $pet['flat'] = $pet['tower_name'] . ' - ' . $pet['flat_number'];
            unset($pet['owner_name'], $pet['flat_number'], $pet['tower_name']);
        }

        ApiResponse::success(['pets' => $pets]);
    }

    // ---------------------------------------------------------------
    //  GET /pets/my
    // ---------------------------------------------------------------
    private function listMyPets() {
        $residentId = $this->auth->getResidentId();

        if (!$residentId) {
            ApiResponse::error('You must be an approved resident to view your pets', 403);
        }

        $stmt = $this->conn->prepare(
            "SELECT p.id, p.resident_id, p.name, p.species, p.breed, p.age_years,
                    p.color, p.photo, p.vaccination_json, p.registration_number,
                    p.is_neutered, p.notes, p.created_at,
                    u.name AS owner_name, u.avatar AS owner_avatar,
                    f.flat_number, t.name AS tower_name
             FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             LEFT JOIN tbl_flat f ON f.id = r.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             WHERE p.resident_id = ?
             ORDER BY p.created_at DESC"
        );
        $stmt->bind_param('i', $residentId);
        $stmt->execute();
        $result = $stmt->get_result();

        $pets = [];
        while ($row = $result->fetch_assoc()) {
            $pets[] = $this->formatPet($row);
        }
        $stmt->close();

        ApiResponse::success($pets, 'Your pets retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  GET /pets/{id}
    // ---------------------------------------------------------------
    private function getPet($id) {
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.resident_id, p.name, p.species, p.breed, p.age_years,
                    p.color, p.photo, p.vaccination_json, p.registration_number,
                    p.is_neutered, p.notes, p.created_at,
                    u.name AS owner_name, u.avatar AS owner_avatar,
                    f.flat_number, t.name AS tower_name
             FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             LEFT JOIN tbl_flat f ON f.id = r.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             WHERE p.id = ? AND r.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Pet not found');
        }

        $pet = $this->formatPet($result->fetch_assoc());
        $stmt->close();

        ApiResponse::success($pet, 'Pet retrieved successfully');
    }

    // ---------------------------------------------------------------
    //  POST /pets
    // ---------------------------------------------------------------
    private function createPet() {
        $residentId = $this->auth->getResidentId();

        if (!$residentId) {
            ApiResponse::forbidden('You must be an approved resident to register a pet');
        }

        $name = sanitizeInput($this->input['name'] ?? '');
        $species = sanitizeInput($this->input['species'] ?? '');
        $breed = sanitizeInput($this->input['breed'] ?? '');
        $ageYears = isset($this->input['age_years']) ? (int)$this->input['age_years'] : null;
        $color = sanitizeInput($this->input['color'] ?? '');
        $registrationNumber = sanitizeInput($this->input['registration_number'] ?? '');
        $isNeutered = isset($this->input['is_neutered']) ? (int)(bool)$this->input['is_neutered'] : 0;
        $notes = sanitizeInput($this->input['notes'] ?? '');

        // Validation
        if (empty($name)) {
            ApiResponse::error('Pet name is required', 400);
        }

        $allowedSpecies = ['dog', 'cat', 'bird', 'fish', 'rabbit', 'other'];
        if (empty($species) || !in_array($species, $allowedSpecies)) {
            ApiResponse::error('Valid species is required. Allowed: ' . implode(', ', $allowedSpecies), 400);
        }

        // Handle vaccination_json
        $vaccinationJson = null;
        if (isset($this->input['vaccination_json'])) {
            $vaccData = $this->input['vaccination_json'];
            if (is_string($vaccData)) {
                $decoded = json_decode($vaccData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ApiResponse::error('Invalid vaccination_json format', 400);
                }
                $vaccinationJson = $vaccData;
            } elseif (is_array($vaccData)) {
                $vaccinationJson = json_encode($vaccData);
            }
        }

        // Handle photo upload
        $photo = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'pets', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $photo = $upload['path'];
        }

        $stmt = $this->conn->prepare(
            "INSERT INTO tbl_pet (resident_id, name, species, breed, age_years, color, photo,
                                  vaccination_json, registration_number, is_neutered, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'isssissssis',
            $residentId, $name, $species, $breed, $ageYears, $color, $photo,
            $vaccinationJson, $registrationNumber, $isNeutered, $notes
        );

        if (!$stmt->execute()) {
            ApiResponse::error('Failed to register pet', 500);
        }

        $petId = $stmt->insert_id;
        $stmt->close();

        // Fetch created pet
        $fetchStmt = $this->conn->prepare(
            "SELECT p.id, p.resident_id, p.name, p.species, p.breed, p.age_years,
                    p.color, p.photo, p.vaccination_json, p.registration_number,
                    p.is_neutered, p.notes, p.created_at,
                    u.name AS owner_name, u.avatar AS owner_avatar,
                    f.flat_number, t.name AS tower_name
             FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             LEFT JOIN tbl_flat f ON f.id = r.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             WHERE p.id = ?"
        );
        $fetchStmt->bind_param('i', $petId);
        $fetchStmt->execute();
        $pet = $this->formatPet($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::created($pet, 'Pet registered successfully');
    }

    // ---------------------------------------------------------------
    //  PUT /pets/{id}
    // ---------------------------------------------------------------
    private function updatePet($id) {
        $residentId = $this->auth->getResidentId();

        // Verify pet exists and belongs to current user
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.resident_id FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             WHERE p.id = ? AND r.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Pet not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['resident_id'] !== $residentId) {
            ApiResponse::forbidden('You can only update your own pets');
        }

        // Build dynamic update
        $fields = [];
        $params = [];
        $types = '';

        if (isset($this->input['name'])) {
            $fields[] = 'name = ?';
            $params[] = sanitizeInput($this->input['name']);
            $types .= 's';
        }

        if (isset($this->input['species'])) {
            $species = sanitizeInput($this->input['species']);
            $allowedSpecies = ['dog', 'cat', 'bird', 'fish', 'rabbit', 'other'];
            if (!in_array($species, $allowedSpecies)) {
                ApiResponse::error('Invalid species. Allowed: ' . implode(', ', $allowedSpecies), 400);
            }
            $fields[] = 'species = ?';
            $params[] = $species;
            $types .= 's';
        }

        if (isset($this->input['breed'])) {
            $fields[] = 'breed = ?';
            $params[] = sanitizeInput($this->input['breed']);
            $types .= 's';
        }

        if (array_key_exists('age_years', $this->input)) {
            $fields[] = 'age_years = ?';
            $params[] = $this->input['age_years'] !== null ? (int)$this->input['age_years'] : null;
            $types .= 'i';
        }

        if (isset($this->input['color'])) {
            $fields[] = 'color = ?';
            $params[] = sanitizeInput($this->input['color']);
            $types .= 's';
        }

        if (isset($this->input['registration_number'])) {
            $fields[] = 'registration_number = ?';
            $params[] = sanitizeInput($this->input['registration_number']);
            $types .= 's';
        }

        if (isset($this->input['is_neutered'])) {
            $fields[] = 'is_neutered = ?';
            $params[] = (int)(bool)$this->input['is_neutered'];
            $types .= 'i';
        }

        if (isset($this->input['notes'])) {
            $fields[] = 'notes = ?';
            $params[] = sanitizeInput($this->input['notes']);
            $types .= 's';
        }

        if (isset($this->input['vaccination_json'])) {
            $vaccData = $this->input['vaccination_json'];
            if (is_string($vaccData)) {
                $decoded = json_decode($vaccData, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    ApiResponse::error('Invalid vaccination_json format', 400);
                }
                $vaccinationJson = $vaccData;
            } elseif (is_array($vaccData)) {
                $vaccinationJson = json_encode($vaccData);
            } else {
                $vaccinationJson = null;
            }
            $fields[] = 'vaccination_json = ?';
            $params[] = $vaccinationJson;
            $types .= 's';
        }

        // Handle photo upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['photo'], 'pets', ['jpg', 'jpeg', 'png', 'webp']);
            if (isset($upload['error'])) {
                ApiResponse::error($upload['error'], 400);
            }
            $fields[] = 'photo = ?';
            $params[] = $upload['path'];
            $types .= 's';
        }

        if (empty($fields)) {
            ApiResponse::error('No fields to update', 400);
        }

        $sql = "UPDATE tbl_pet SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $updateStmt = $this->conn->prepare($sql);
        $updateStmt->bind_param($types, ...$params);

        if (!$updateStmt->execute()) {
            ApiResponse::error('Failed to update pet', 500);
        }
        $updateStmt->close();

        // Return updated pet
        $fetchStmt = $this->conn->prepare(
            "SELECT p.id, p.resident_id, p.name, p.species, p.breed, p.age_years,
                    p.color, p.photo, p.vaccination_json, p.registration_number,
                    p.is_neutered, p.notes, p.created_at,
                    u.name AS owner_name, u.avatar AS owner_avatar,
                    f.flat_number, t.name AS tower_name
             FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             LEFT JOIN tbl_user u ON u.id = r.user_id
             LEFT JOIN tbl_flat f ON f.id = r.flat_id
             LEFT JOIN tbl_tower t ON t.id = f.tower_id
             WHERE p.id = ?"
        );
        $fetchStmt->bind_param('i', $id);
        $fetchStmt->execute();
        $pet = $this->formatPet($fetchStmt->get_result()->fetch_assoc());
        $fetchStmt->close();

        ApiResponse::success($pet, 'Pet updated successfully');
    }

    // ---------------------------------------------------------------
    //  DELETE /pets/{id}
    // ---------------------------------------------------------------
    private function deletePet($id) {
        $residentId = $this->auth->getResidentId();

        // Verify pet exists and belongs to current user
        $stmt = $this->conn->prepare(
            "SELECT p.id, p.resident_id FROM tbl_pet p
             INNER JOIN tbl_resident r ON r.id = p.resident_id
             WHERE p.id = ? AND r.society_id = ?"
        );
        $stmt->bind_param('ii', $id, $this->societyId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            ApiResponse::notFound('Pet not found');
        }

        $existing = $result->fetch_assoc();
        $stmt->close();

        if ((int)$existing['resident_id'] !== $residentId) {
            ApiResponse::forbidden('You can only remove your own pets');
        }

        $deleteStmt = $this->conn->prepare("DELETE FROM tbl_pet WHERE id = ?");
        $deleteStmt->bind_param('i', $id);

        if (!$deleteStmt->execute()) {
            ApiResponse::error('Failed to remove pet', 500);
        }
        $deleteStmt->close();

        ApiResponse::success(null, 'Pet registration removed successfully');
    }

    // ---------------------------------------------------------------
    //  Formatters
    // ---------------------------------------------------------------
    private function formatPet($row) {
        return [
            'id' => (int)$row['id'],
            'resident_id' => (int)$row['resident_id'],
            'name' => $row['name'],
            'species' => $row['species'],
            'breed' => $row['breed'],
            'age_years' => $row['age_years'] !== null ? (int)$row['age_years'] : null,
            'color' => $row['color'],
            'photo' => $row['photo'],
            'vaccination_json' => $row['vaccination_json'] ? json_decode($row['vaccination_json'], true) : null,
            'registration_number' => $row['registration_number'],
            'is_neutered' => (bool)$row['is_neutered'],
            'notes' => $row['notes'],
            'owner' => [
                'name' => $row['owner_name'] ?? null,
                'avatar' => $row['owner_avatar'] ?? null,
                'flat_number' => $row['flat_number'] ?? null,
                'tower_name' => $row['tower_name'] ?? null,
            ],
            'created_at' => $row['created_at'],
        ];
    }
}
