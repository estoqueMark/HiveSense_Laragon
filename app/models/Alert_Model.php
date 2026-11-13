<?php
require_once __DIR__ . "/Base_Model.php";

class Alert_Model extends Base_Model {

    // Thresholds — matches your existing dashboard guide cards
    const TEMP_COLD       = 32;
    const TEMP_HOT        = 38;
    const HUM_DRY         = 50;
    const HUM_VERY_HUMID  = 85;
    // Estimated CO2 (MQ-135) ranges linked to Varroa infestation (Camayo et al., 2024):
    //   600-1000 ppm = low infestation, 1000-2000 ppm = average (warning alert),
    //   above 2000 ppm = high (critical alert)
    const CO2_LOW_PPM      = 600;
    const CO2_WARNING_PPM  = 1000;
    const CO2_CRITICAL_PPM = 2000;

    // Food is judged against the weight the beekeeper recorded as "full" (button on the dashboard)
    const FOOD_FULL_PCT   = 75;   // >= 75% of the recorded full weight = Full
    const FOOD_HALF_PCT   = 40;   // >= 40% = Half full
    const FOOD_EMPTY_PCT  = 10;   // <= 10% = Empty (in between: Low)
    const FOOD_MIN_REF_G  = 20;   // a "full" reference must weigh at least this much
    const FOOD_SETTLE_SEC = 30;   // use the first reading taken this long after the button press
    const FOOD_PENDING_TIMEOUT_MIN = 10;   // give up waiting for a reading after this long

    public function __construct() {
        parent::__construct();
    }

    /**
     * Call after every new reading. Any value may be null (sensor didn't report it).
     * Only metrics that were actually reported are checked or auto-resolved.
     */
    public function evaluateReading(?int $sensorId, ?float $temperature, ?float $humidity, ?float $co2, ?float $foodLevel, ?int $readingId): array {
        $triggered = [];
        $reported  = [];

        if ($temperature !== null) {
            $reported[] = 'temperature';
            if ($temperature < self::TEMP_COLD) {
                $triggered[] = ['type' => 'temperature', 'severity' => 'warning', 'value' => $temperature,
                    'message' => "Temperature dropped to {$temperature}°C — colony at risk of cold stress."];
            } elseif ($temperature > self::TEMP_HOT) {
                $triggered[] = ['type' => 'temperature', 'severity' => 'critical', 'value' => $temperature,
                    'message' => "Temperature spiked to {$temperature}°C — check ventilation."];
            }
        }

        if ($humidity !== null) {
            $reported[] = 'humidity';
            if ($humidity < self::HUM_DRY) {
                $triggered[] = ['type' => 'humidity', 'severity' => 'warning', 'value' => $humidity,
                    'message' => "Humidity dropped to {$humidity}% — risk of comb damage."];
            } elseif ($humidity > self::HUM_VERY_HUMID) {
                $triggered[] = ['type' => 'humidity', 'severity' => 'critical', 'value' => $humidity,
                    'message' => "Humidity spiked to {$humidity}% — act now to prevent mold."];
            }
        }

        if ($co2 !== null) {
            $reported[] = 'co2';
            $ppm = round($co2);
            if ($co2 > self::CO2_CRITICAL_PPM) {
                $triggered[] = ['type' => 'co2', 'severity' => 'critical', 'value' => $co2,
                    'message' => "Estimated CO₂ reached {$ppm} ppm — range linked to a high Varroa infestation. Inspect the hive for Varroa mites now."];
            } elseif ($co2 >= self::CO2_WARNING_PPM) {
                $triggered[] = ['type' => 'co2', 'severity' => 'warning', 'value' => $co2,
                    'message' => "Estimated CO₂ reached {$ppm} ppm — range linked to an average Varroa infestation. Inspect the hive for Varroa mites."];
            }
        }

        if ($foodLevel !== null) {
            $reported[] = 'food';
            if ($sensorId) {
                // A pending "Food placed" press becomes the new full reference on the first settled reading
                $this->applyPendingFoodReference($sensorId, (float)$foodLevel, $readingId);
                $ref = $this->getFoodReference($sensorId);
                // No reference recorded yet = nothing to compare against = no food alerts
                if ($ref) {
                    $full  = (float)$ref['full_weight_g'];
                    $state = self::foodState((float)$foodLevel, $full);
                    $g     = round($foodLevel);
                    $f     = round($full);
                    $pct   = $state['pct'];
                    if ($state['key'] === 'empty') {
                        $triggered[] = ['type' => 'food', 'severity' => 'critical', 'value' => $foodLevel,
                            'message' => "Supplementary food is empty ({$g} g, {$pct}% of full) — feed the colony now."];
                    } elseif ($state['key'] === 'low') {
                        $triggered[] = ['type' => 'food', 'severity' => 'warning', 'value' => $foodLevel,
                            'message' => "Supplementary food is low ({$g} g, {$pct}% of full) — plan feeding soon."];
                    }
                    // Half full / Full: shown on the dashboard only, no alert
                }
            }
        }

        $breachedTypes = array_column($triggered, 'type');

        // Only auto-resolve metrics that were reported this time and are back in range
        foreach ($reported as $type) {
            if (!in_array($type, $breachedTypes)) {
                $this->resolveActiveAlert($sensorId, $type);
            }
        }

        $newOrEscalated = [];
        foreach ($triggered as $alert) {
            $existing = $this->getActiveAlert($sensorId, $alert['type']);
            if ($existing) {
                $changed = $existing['severity'] !== $alert['severity']
                    || ($alert['type'] === 'food'
                        && $this->alertLabel($existing['message']) !== $this->alertLabel($alert['message']));
                if ($changed) {
                    $this->updateAlert((int)$existing['alert_id'], $alert['severity'], $alert['value'], $alert['message'], $readingId);
                    $alert['alert_id'] = $existing['alert_id'];
                    $newOrEscalated[] = $alert;
                }
                continue;
            }
            $id = $this->createAlert($sensorId, $alert['type'], $alert['severity'], $alert['value'], $alert['message'], $readingId);
            $alert['alert_id'] = $id;
            $newOrEscalated[] = $alert;
        }

        return $newOrEscalated;
    }

    // ══════════════════════════════════════════════════════════
    //  FOOD REFERENCE  ("Food placed" button)
    // ══════════════════════════════════════════════════════════

    private static bool $foodTableReady = false;

    private function ensureFoodTable(): void {
        if (self::$foodTableReady) return;
        $this->connection->query(
            "CREATE TABLE IF NOT EXISTS hs_food_reference (
                sensor_id     INT NOT NULL PRIMARY KEY,
                full_weight_g DOUBLE NULL,
                set_at        DATETIME NULL,
                set_by        INT NULL,
                pending_since DATETIME NULL,
                pending_by    INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        self::$foodTableReady = true;
    }

    /** Classifies a weight against the recorded full weight. */
    public static function foodState(float $grams, float $fullGrams): array {
        $pct = $fullGrams > 0 ? ($grams / $fullGrams) * 100 : 0;
        if     ($pct >= self::FOOD_FULL_PCT)  return ['key' => 'full',     'label' => 'Full',      'pct' => round($pct)];
        elseif ($pct >= self::FOOD_HALF_PCT)  return ['key' => 'moderate', 'label' => 'Half full', 'pct' => round($pct)];
        elseif ($pct >  self::FOOD_EMPTY_PCT) return ['key' => 'low',      'label' => 'Low',       'pct' => round($pct)];
        return ['key' => 'empty', 'label' => 'Empty', 'pct' => round($pct)];
    }

    /** Raw row (may only hold a pending request). */
    public function getFoodRow(int $sensorId): ?array {
        $this->ensureFoodTable();
        $stmt = $this->connection->prepare(
            'SELECT full_weight_g, set_at, set_by, pending_since FROM hs_food_reference WHERE sensor_id = ? LIMIT 1'
        );
        $stmt->bind_param("i", $sensorId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    /** The recorded "full" weight, or null if the beekeeper has not recorded one yet. */
    public function getFoodReference(int $sensorId): ?array {
        $row = $this->getFoodRow($sensorId);
        return ($row && $row['full_weight_g'] !== null) ? $row : null;
    }

    /** Button pressed: the first settled reading after this moment becomes the full weight. */
    public function requestFoodReference(int $sensorId, int $userId): void {
        $this->ensureFoodTable();
        $stmt = $this->connection->prepare(
            'INSERT INTO hs_food_reference (sensor_id, pending_since, pending_by) VALUES (?, NOW(), ?)
             ON DUPLICATE KEY UPDATE pending_since = NOW(), pending_by = VALUES(pending_by)'
        );
        $stmt->bind_param("ii", $sensorId, $userId);
        $stmt->execute();
    }

    /**
     * Called with every new reading. Returns true if it just became the new full reference.
     * With a $readingId the reading itself must have been taken after the settle time;
     * without one (older call style) the press must simply be older than the settle time.
     */
    public function applyPendingFoodReference(int $sensorId, float $food, ?int $readingId = null): bool {
        $row = $this->getFoodRow($sensorId);
        if (!$row || $row['pending_since'] === null) return false;

        $settleSec = self::FOOD_SETTLE_SEC;
        $stmt = $this->connection->prepare(
            'SELECT TIMESTAMPDIFF(MINUTE, pending_since, NOW()) AS age_min,
                    IF(? IS NULL,
                       (pending_since <= DATE_SUB(NOW(), INTERVAL ? SECOND)),
                       (SELECT COUNT(*) > 0 FROM hs_readings
                         WHERE readings_id = ? AND timestamp >= DATE_ADD(pending_since, INTERVAL ? SECOND))) AS settled
             FROM hs_food_reference WHERE sensor_id = ? AND pending_since IS NOT NULL'
        );
        $stmt->bind_param("iiiii", $readingId, $settleSec, $readingId, $settleSec, $sensorId);
        $stmt->execute();
        $chk = $stmt->get_result()->fetch_assoc();
        if (!$chk) return false;

        if ((int)$chk['age_min'] >= self::FOOD_PENDING_TIMEOUT_MIN) {      // nobody put food on: give up
            $stmt = $this->connection->prepare(
                'UPDATE hs_food_reference SET pending_since = NULL, pending_by = NULL WHERE sensor_id = ?'
            );
            $stmt->bind_param("i", $sensorId);
            $stmt->execute();
            return false;
        }
        if ((int)$chk['settled'] === 0) return false;       // reading is from before the settle time
        if ($food < self::FOOD_MIN_REF_G) return false;     // nothing on the scale yet - keep waiting

        $stmt = $this->connection->prepare(
            'UPDATE hs_food_reference
             SET full_weight_g = ?, set_at = NOW(), set_by = pending_by, pending_since = NULL, pending_by = NULL
             WHERE sensor_id = ?'
        );
        $stmt->bind_param("di", $food, $sensorId);
        $stmt->execute();
        $this->resolveActiveAlert($sensorId, 'food');
        return true;
    }

    /** "Food is half full (143 g of 300 g)." -> "Food is half full" */
    private function alertLabel(string $message): string {
        $p = strpos($message, ' (');
        return $p === false ? $message : substr($message, 0, $p);
    }

    private function getActiveAlert(?int $sensorId, string $type): ?array {
        $stmt = $this->connection->prepare(
            "SELECT * FROM hs_alerts WHERE alert_type = ? AND status = 'active' AND sensor_id <=> ? LIMIT 1"
        );
        $stmt->bind_param("si", $type, $sensorId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function createAlert(?int $sensorId, string $type, string $severity, float $value, string $message, ?int $readingId): int {
        $stmt = $this->connection->prepare(
            "INSERT INTO hs_alerts (sensor_id, alert_type, severity, metric_value, message, reading_id, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())"
        );
        $stmt->bind_param("issdsi", $sensorId, $type, $severity, $value, $message, $readingId);
        $stmt->execute();
        return (int)$this->connection->insert_id;
    }

    private function updateAlert(int $alertId, string $severity, float $value, string $message, ?int $readingId): bool {
        $stmt = $this->connection->prepare(
            "UPDATE hs_alerts SET severity = ?, metric_value = ?, message = ?, reading_id = ?, created_at = NOW(),
             acknowledged_by = NULL, acknowledged_at = NULL
             WHERE alert_id = ?"
        );
        $stmt->bind_param("sdsii", $severity, $value, $message, $readingId, $alertId);
        return $stmt->execute();
    }

    private function resolveActiveAlert(?int $sensorId, string $type): bool {
        $stmt = $this->connection->prepare(
            "UPDATE hs_alerts SET status = 'resolved', resolved_at = NOW()
             WHERE alert_type = ? AND status = 'active' AND sensor_id <=> ?"
        );
        $stmt->bind_param("si", $type, $sensorId);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    public function getActiveAlerts(?int $sensorId = null): array {
        if ($sensorId) {
            $stmt = $this->connection->prepare(
                "SELECT a.*, s.hive_name FROM hs_alerts a
                 LEFT JOIN hs_sensors s ON s.sensor_id = a.sensor_id
                 WHERE a.status = 'active' AND a.acknowledged_at IS NULL AND a.sensor_id = ?
                 ORDER BY a.created_at DESC"
            );
            $stmt->bind_param("i", $sensorId);
        } else {
            $stmt = $this->connection->prepare(
                "SELECT a.*, s.hive_name FROM hs_alerts a
                 LEFT JOIN hs_sensors s ON s.sensor_id = a.sensor_id
                 WHERE a.status = 'active' AND a.acknowledged_at IS NULL
                 ORDER BY a.created_at DESC"
            );
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Full history — both active and resolved, newest first, optional hive filter + limit */
    public function getAlertHistory(?int $sensorId = null, int $limit = 100): array {
        if ($sensorId) {
            $stmt = $this->connection->prepare(
                "SELECT a.*, s.hive_name FROM hs_alerts a
                 LEFT JOIN hs_sensors s ON s.sensor_id = a.sensor_id
                 WHERE a.sensor_id = ?
                 ORDER BY a.created_at DESC
                 LIMIT ?"
            );
            $stmt->bind_param("ii", $sensorId, $limit);
        } else {
            $stmt = $this->connection->prepare(
                "SELECT a.*, s.hive_name FROM hs_alerts a
                 LEFT JOIN hs_sensors s ON s.sensor_id = a.sensor_id
                 ORDER BY a.created_at DESC
                 LIMIT ?"
            );
            $stmt->bind_param("i", $limit);
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function acknowledgeAlert(int $alertId, int $userId): bool {
        $stmt = $this->connection->prepare(
            "UPDATE hs_alerts SET acknowledged_by = ?, acknowledged_at = NOW() WHERE alert_id = ?"
        );
        $stmt->bind_param("ii", $userId, $alertId);
        return $stmt->execute();
    }
}
?>