<?php
require_once __DIR__ . "/../core/Base_Controller.php";

/**
 * POST /ingest
 *
 * Single reading (same as before):
 *   { "api_key": "...", "sensor_id": 3, "temperature": 34.5, "humidity": 60, "co2": 600, "food_level": 1200 }
 *
 * Batch (e.g. after a power outage):
 *   { "api_key": "...", "sensor_id": 3,
 *     "readings": [
 *        { "age_s": 7200, "temperature": 34.1, "humidity": 61 },   // taken 2 hours before sending
 *        { "age_s": 7140, "temperature": 34.2, "humidity": 61 },
 *        { "ts": 1790000000, "temperature": 34.3, "humidity": 60 } // or an exact unix time
 *     ] }
 *
 * Time of each reading: "ts" (unix seconds) if sent, else "age_s" (seconds before now),
 * else now. "age_s" works even if the ESP32 has no real clock.
 */
class Ingest extends Base_Controller {

    const MAX_BATCH   = 200;          // readings per request (free-host friendly)
    const MAX_AGE_SEC = 7 * 86400;    // older than this is rejected

    public function __construct() {
        parent::__construct();
    }

    private function fail(int $code, string $message): void {
        http_response_code($code);
        echo json_encode(['success' => false, 'message' => $message]);
        exit();
    }

    /** Validate one reading. Returns ['values'=>[...], 'warnings'=>[...]] or ['error'=>[code, msg]] */
    private function cleanReading(array $in): array {
        $keys = ['temperature', 'humidity', 'co2', 'food_level'];
        $v = array_fill_keys($keys, null);
        $w = [];

        foreach ($keys as $k) {
            if (isset($in[$k])) {
                if (!is_numeric($in[$k])) return ['error' => [400, "{$k} must be numeric."]];
                $v[$k] = (float) $in[$k];
            }
        }

        if ($v['temperature'] === null && $v['humidity'] === null && $v['co2'] === null && $v['food_level'] === null) {
            return ['error' => [400, 'Send at least one of: temperature, humidity, co2, food_level.']];
        }

        // A suspicious value is dropped (not stored) so it can't trigger fake alerts,
        // but the other sensors' values in the same reading are still saved.
        if ($v['temperature'] !== null && ($v['temperature'] < -10 || $v['temperature'] > 60)) {
            $w[] = "temperature ignored (suspected sensor fault: {$v['temperature']})";
            $v['temperature'] = null;
        }
        if ($v['humidity'] !== null && ($v['humidity'] <= 0 || $v['humidity'] > 100)) {
            $w[] = "humidity ignored (suspected sensor fault: {$v['humidity']})";
            $v['humidity'] = null;
        }
        if ($v['co2'] !== null && ($v['co2'] < 300 || $v['co2'] > 10000)) {
            $w[] = "co2 ignored (out of range 300-10000 ppm: {$v['co2']})";
            $v['co2'] = null;
        }
        if ($v['food_level'] !== null && ($v['food_level'] < 0 || $v['food_level'] > FOOD_MAX_G)) {
            $w[] = "food_level ignored (out of range 0-" . FOOD_MAX_G . ": {$v['food_level']})";
            $v['food_level'] = null;
        }

        if ($v['temperature'] === null && $v['humidity'] === null && $v['co2'] === null && $v['food_level'] === null) {
            return ['error' => [422, 'No valid sensor values in this reading. ' . implode('; ', $w)]];
        }

        return ['values' => $v, 'warnings' => $w];
    }

    public function index() {
        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->fail(405, 'POST only.');
        }

        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body || !is_array($body)) {
            $this->fail(400, 'Invalid JSON.');
        }

        $apiKey = trim($body['api_key'] ?? '');
        if (!$apiKey) {
            $this->fail(401, 'API key required.');
        }

        require_once __DIR__ . "/../models/Base_Model.php";
        $db         = new Base_Model();
        $connection = $db->connection;

        // Verify API key
        $stmt = $connection->prepare('SELECT key_id FROM hs_api_keys WHERE api_key = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param("s", $apiKey);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $this->fail(401, 'Invalid or inactive API key.');
        }

        // sensor_id applies to the whole request (single or batch)
        if (!isset($body['sensor_id']) || !is_numeric($body['sensor_id']) || (int)$body['sensor_id'] <= 0) {
            $this->fail(400, 'A valid sensor_id is required.');
        }
        $sensorId = (int) $body['sensor_id'];

        $stmt = $connection->prepare('SELECT sensor_id FROM hs_sensors WHERE sensor_id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param("i", $sensorId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            $this->fail(400, "Unknown sensor_id: {$sensorId}");
        }

        // Single reading or batch?
        $isBatch = isset($body['readings']) && is_array($body['readings']);
        $items   = $isBatch ? $body['readings'] : [$body];

        if (!$items) {
            $this->fail(400, 'readings is empty.');
        }
        if (count($items) > self::MAX_BATCH) {
            $this->fail(413, 'Too many readings. Max ' . self::MAX_BATCH . ' per request.');
        }

        // Validate everything first
        $now      = time();
        $rows     = [];
        $warnings = [];
        $skipped  = 0;

        foreach ($items as $i => $item) {
            $label = $isBatch ? "#{$i}: " : '';

            if (!is_array($item)) {
                $warnings[] = $label . 'skipped (not an object)';
                $skipped++;
                continue;
            }

            $clean = $this->cleanReading($item);
            if (isset($clean['error'])) {
                if (!$isBatch) $this->fail($clean['error'][0], $clean['error'][1]);
                $warnings[] = $label . 'skipped (' . $clean['error'][1] . ')';
                $skipped++;
                continue;
            }
            foreach ($clean['warnings'] as $w) $warnings[] = $label . $w;

            // When was it taken?
            $ts  = null;
            $age = 0;
            if (isset($item['ts']) && is_numeric($item['ts'])) {
                $ts  = (int) $item['ts'];
                $age = max(0, $now - $ts);                 // future times count as "now"
            } elseif (isset($item['age_s']) && is_numeric($item['age_s'])) {
                $age = max(0, (int) $item['age_s']);
            }
            if ($age > self::MAX_AGE_SEC) {
                $warnings[] = $label . 'skipped (older than 7 days or bad clock)';
                $skipped++;
                continue;
            }

            $rows[] = [
                'v'     => $clean['values'],
                'stamp' => date('Y-m-d H:i:s', $now - $age),
                'exact' => $ts !== null,                   // exact time -> safe to de-duplicate
            ];
        }

        if (!$rows) {
            $this->fail(422, 'No valid readings. ' . implode('; ', $warnings));
        }

        // Oldest first, so the newest row is the last one inserted
        usort($rows, fn($a, $b) => strcmp($a['stamp'], $b['stamp']));

        $insert = $connection->prepare(
            'INSERT INTO hs_readings (sensor_id, temperature, humidity, co2, food_level, timestamp)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $dupCheck = $connection->prepare(
            'SELECT 1 FROM hs_readings WHERE sensor_id = ? AND timestamp = ? LIMIT 1'
        );

        $inserted   = 0;
        $duplicates = 0;
        $newest     = null;   // last row actually stored

        foreach ($rows as $r) {
            // A retried batch with exact "ts" values won't create duplicates
            if ($r['exact']) {
                $dupCheck->bind_param("is", $sensorId, $r['stamp']);
                $dupCheck->execute();
                if ($dupCheck->get_result()->fetch_assoc()) {
                    $duplicates++;
                    continue;
                }
            }

            $v = $r['v'];
            $insert->bind_param("idddds", $sensorId, $v['temperature'], $v['humidity'], $v['co2'], $v['food_level'], $r['stamp']);
            if (!$insert->execute()) {
                $this->fail(500, 'Database insert failed: ' . $insert->error);
            }
            $inserted++;
            $newest = ['id' => $connection->insert_id, 'v' => $v, 'stamp' => $r['stamp']];
        }

        // Alerts: only judge the NEWEST reading. Evaluating old backlog rows one by one
        // would create alerts stamped "now" for things that happened hours ago.
        if ($newest) {
            require_once __DIR__ . "/../models/Alert_Model.php";
            $alertModel = new Alert_Model();
            $v = $newest['v'];
            $alertModel->evaluateReading($sensorId, $v['temperature'], $v['humidity'], $v['co2'], $v['food_level'], (int)$newest['id']);
            if ($v['food_level'] !== null) {
                $alertModel->applyPendingFoodReference($sensorId, $v['food_level']);
            }
        }

        // Update last used timestamp
        $stmt = $connection->prepare('UPDATE hs_api_keys SET last_used_at = NOW() WHERE api_key = ?');
        $stmt->bind_param("s", $apiKey);
        $stmt->execute();

        if (!$isBatch) {
            $v = $rows[0]['v'];
            echo json_encode([
                'success'     => true,
                'reading_id'  => $newest['id'] ?? null,
                'temperature' => $v['temperature'],
                'humidity'    => $v['humidity'],
                'co2'         => $v['co2'],
                'food_level'  => $v['food_level'],
                'timestamp'   => $rows[0]['stamp'],
                'warnings'    => $warnings,
            ]);
            return;
        }

        echo json_encode([
            'success'    => true,
            'inserted'   => $inserted,
            'duplicates' => $duplicates,
            'skipped'    => $skipped,
            'warnings'   => $warnings,
        ]);
    }
}
?>