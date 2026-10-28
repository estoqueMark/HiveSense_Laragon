<?php
require_once __DIR__ . "/../core/Base_Controller.php";
require_once __DIR__ . "/../models/Base_Model.php";
require_once __DIR__ . "/../models/Alert_Model.php";

/**
 * ThingSpeak_Bridge  (replaces Ingest.php)
 *
 * Polls a ThingSpeak channel (populated by the ESP32) and mirrors new
 * entries into `hs_readings`, then runs the alert check. The site pulls
 * from ThingSpeak instead of the ESP32 POSTing directly to the host,
 * which was unreliable.
 *
 * Call it with:  /thingspeak_bridge?secret=YOUR_SECRET
 * (use a cron job or an external pinger every minute; the exact URL
 * depends on your router)
 *
 * Field mapping (ThingSpeak -> hs_readings):
 *   field1 -> temperature
 *   field2 -> humidity
 *   field3 -> (raw CO2 ADC value, not stored)
 *   field4 -> co2 (ppm)
 *   field5 -> food_level   (assumption — change FIELD_FOOD if yours differs)
 *
 * Requires in core/config.php:
 *   define('THINGSPEAK_CHANNEL_ID',    'your_channel_id');
 *   define('THINGSPEAK_READ_API_KEY',  'your_read_api_key');
 *   define('THINGSPEAK_SENSOR_ID',     1);   // hs_sensors.sensor_id
 *   define('THINGSPEAK_BRIDGE_SECRET', 'some-long-random-string');
 *   define('FOOD_MAX_G',               2500); // max valid food_level value
 *
 * Requires in hs_readings:
 *   ALTER TABLE hs_readings
 *     ADD COLUMN thingspeak_entry_id INT NULL,
 *     ADD UNIQUE KEY uq_thingspeak_entry (thingspeak_entry_id);
 */
class ThingSpeak_Bridge extends Base_Controller {

    private const FIELD_TEMP  = 'field1';
    private const FIELD_HUM   = 'field2';
    private const FIELD_CO2   = 'field4';   // ppm
    private const FIELD_FOOD  = 'field5';

    private Base_Model  $db;
    private mysqli      $connection;
    private ?Alert_Model $alertModel = null;
    private string $channelId;
    private string $readApiKey;
    private int    $sensorId;

    public function __construct() {
        parent::__construct();

        $this->connectDb();

        $this->channelId  = defined('THINGSPEAK_CHANNEL_ID')   ? THINGSPEAK_CHANNEL_ID   : '';
        $this->readApiKey = defined('THINGSPEAK_READ_API_KEY') ? THINGSPEAK_READ_API_KEY : '';
        $this->sensorId   = defined('THINGSPEAK_SENSOR_ID')    ? (int) THINGSPEAK_SENSOR_ID : 1;
    }

    // ══════════════════════════════════════════════════════════
    //  WEB ENTRY POINT
    // ══════════════════════════════════════════════════════════

    /**
     * GET /thingspeak_bridge?secret=...
     * Verifies the secret, then pulls the latest entries from ThingSpeak and
     * stores any that are missing. Safe to call as often as you like —
     * duplicates are skipped by thingspeak_entry_id.
     */
    public function index() {
        header('Content-Type: application/json');
        // Never let the host/CDN/proxy serve a cached copy: a cached 200 would
        // make a cron pinger look successful without PHP ever running.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        if (!$this->verifySecret()) {
            $this->logRun('forbidden (wrong or missing secret)');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Forbidden.']);
            exit();
        }

        try {
            // Last 100 entries (~25 min at 15 s), so a missed poll never loses data.
            $result = $this->getRecent(100);
            $this->logRun('ok: fetched=' . ($result['fetched'] ?? 0) . ' inserted=' . ($result['inserted'] ?? 0)
                . ' newest_age_min=' . ($result['newest_entry_age_min'] ?? '?')
                . (empty($result['success']) ? ' | ' . ($result['message'] ?? 'failed') : ''));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->logRun('error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    private const BACKFILL_MAX_DAYS    = 14;     // never look back further than this
    private const BACKFILL_WINDOW_SEC  = 86400;  // ask ThingSpeak for up to 24 h per request
    private const BACKFILL_MAX_INSERTS = 800;    // per request, stays inside PHP time limits

    /**
     * GET /api/bridge_sync[?from=EPOCH]  (logged-in dashboard users only — guarded by index.php)
     * Copies new ThingSpeak entries into the database. With no "from" it starts at the newest
     * reading already stored, so it also fills any gap (dashboard closed, ESP32 offline and
     * uploading its backlog later). The response says "more":true with "next_from" when the
     * browser should immediately call again to continue catching up.
     */
    public function syncFromDashboard(): void {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        try {
            $from = (isset($_GET['from']) && ctype_digit((string) $_GET['from'])) ? (int) $_GET['from'] : null;

            // Throttle only the start of a cycle; follow-up catch-up calls carry "from".
            if ($from === null && $this->recentlySynced(15)) {
                echo json_encode(['success' => true, 'skipped' => true]);
                exit();
            }

            $result = $this->backfillStep($from);
            $this->logRun('dashboard ok: fetched=' . ($result['fetched'] ?? 0) . ' inserted=' . ($result['inserted'] ?? 0)
                . ' from=' . ($result['from_utc'] ?? '?')
                . ' more=' . (!empty($result['more']) ? 1 : 0)
                . (!empty($result['fallback']) ? ' (fallback: windowed request failed)' : '')
                . ' newest_age_min=' . ($result['newest_entry_age_min'] ?? '?')
                . (empty($result['success']) ? ' | ' . ($result['message'] ?? 'failed') : ''));
            echo json_encode($result);
        } catch (\Throwable $e) {
            $this->logRun('dashboard error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }

    /**
     * One catch-up step. Starts at $fromEpoch (or just before the newest stored ThingSpeak
     * reading), reads up to 24 h of entries from ThingSpeak, saves the missing ones (at most
     * BACKFILL_MAX_INSERTS), and reports where to continue.
     */
    public function backfillStep(?int $fromEpoch = null): array {
        @set_time_limit(60);
        $now = time();

        if ($fromEpoch === null) {
            $anchor = null;
            $r   = $this->connection->query(
                'SELECT UNIX_TIMESTAMP(MAX(timestamp)) AS t FROM hs_readings WHERE thingspeak_entry_id IS NOT NULL'
            );
            $row = $r ? $r->fetch_assoc() : null;
            if ($row && $row['t'] !== null) $anchor = (int) $row['t'];
            $fromEpoch = ($anchor ?? ($now - 86400)) - 120;       // small overlap; duplicates are skipped
        }
        $fromEpoch = max($fromEpoch, $now - self::BACKFILL_MAX_DAYS * 86400);

        if ($fromEpoch >= $now - 30) {
            return ['success' => true, 'fetched' => 0, 'inserted' => 0, 'more' => false, 'next_from' => $now,
                    'from_utc' => gmdate('Y-m-d H:i:s', $fromEpoch)];
        }

        // For gaps up to ~33 h ask ThingSpeak for "the newest N entries" (no date/time-zone
        // parameters, so nothing can be misread). Entries come at least 15 s apart on the free
        // tier, so N = age / 15 s (+ margin) is always enough. Only longer gaps use start/end.
        $ageSec = $now - $fromEpoch;
        $needed = (int) ceil($ageSec / 15) + 60;
        if ($needed <= 8000) {
            $endEpoch = $now;
            $url = $this->buildFeedsUrl($needed);
        } else {
            $endEpoch = min($fromEpoch + self::BACKFILL_WINDOW_SEC, $now);
            $url = $this->buildFeedsUrl(8000)
                 . '&start=' . rawurlencode(gmdate('Y-m-d H:i:s', $fromEpoch))
                 . '&end='   . rawurlencode(gmdate('Y-m-d H:i:s', $endEpoch));
        }
        $data = $this->httpGetJson($url);

        if ($data === null) {
            // Windowed request failed: fall back to the plain "last 100 entries" pull.
            $res = $this->getRecent(100);
            $res['fallback'] = true;
            $res['more']     = false;
            $res['from_utc'] = gmdate('Y-m-d H:i:s', $fromEpoch);
            return $res;
        }

        $feeds    = $data['feeds'] ?? [];
        $inserted = 0;
        $latest   = null;
        $errors   = [];
        $nextFrom = $endEpoch;

        foreach ($feeds as $feed) {                          // oldest -> newest
            $res = $this->saveFeedEntry($feed);
            if (!empty($res['inserted'])) {
                $inserted++;
                $latest = $res;
            } elseif (empty($res['success'])) {
                $errors[] = $res['message'] ?? 'Unknown error.';
            }
            if ($inserted >= self::BACKFILL_MAX_INSERTS) {   // stop here, continue on the next call
                $t = strtotime($feed['created_at'] ?? '');
                if ($t) $nextFrom = $t;
                break;
            }
        }

        // Only raise alerts for a reading that is actually recent, never for old history.
        if ($latest && ($now - (int) $latest['ts_epoch']) <= 900) {
            $this->runAlerts($latest);
        }

        $out = [
            'success'   => true,
            'fetched'   => count($feeds),
            'inserted'  => $inserted,
            'from_utc'  => gmdate('Y-m-d H:i:s', $fromEpoch),
            'next_from' => $nextFrom,
            'more'      => $nextFrom < $now - 30,
        ];
        if ($feeds) {
            $lastFeed = end($feeds);
            if (!empty($lastFeed['created_at'])) {
                $out['newest_entry_at_utc']  = $lastFeed['created_at'];
                $out['newest_entry_age_min'] = round(($now - strtotime($lastFeed['created_at'])) / 60, 1);
            }
        }
        if ($errors) $out['errors'] = array_values(array_unique($errors));
        return $out;
    }

    /** True if a successful pull (cron or dashboard) was logged within the last $seconds. */
    private function recentlySynced(int $seconds): bool {
        try {
            $r = $this->connection->query(
                "SELECT TIMESTAMPDIFF(SECOND, MAX(called_at), NOW()) AS s FROM hs_bridge_log
                 WHERE outcome LIKE 'ok:%' OR outcome LIKE 'dashboard ok:%'"
            );
            $row = $r ? $r->fetch_assoc() : null;
            return $row && $row['s'] !== null && (int)$row['s'] < $seconds;
        } catch (\Throwable $e) {
            return false;   // table not created yet, etc. — just do the pull
        }
    }

    /**
     * Diagnostic: records every request that reaches this script (who, when, outcome).
     * If a cron pinger shows "200 OK" but no row appears here, the request never reached
     * PHP (cached page or host bot-check). Never breaks the bridge if logging fails.
     */
    private function logRun(string $outcome): void {
        try {
            $this->connection->query(
                "CREATE TABLE IF NOT EXISTS hs_bridge_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    called_at DATETIME NOT NULL,
                    ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    outcome VARCHAR(255) NULL,
                    KEY idx_called_at (called_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
            $out = substr($outcome, 0, 255);
            $stmt = $this->connection->prepare(
                'INSERT INTO hs_bridge_log (called_at, ip, user_agent, outcome) VALUES (NOW(), ?, ?, ?)'
            );
            $stmt->bind_param("sss", $ip, $ua, $out);
            $stmt->execute();
            $this->connection->query("DELETE FROM hs_bridge_log WHERE called_at < (NOW() - INTERVAL 3 DAY)");
        } catch (\Throwable $e) {
            // ignore: logging is best-effort
        }
    }

    /** Uses Base_Model so charset and the +08:00 timezone are applied. */
    private function connectDb(): void {
        $db = new Base_Model();
        $this->db = $db;              // old Base_Model (if any) is destructed and closes its handle
        $this->connection = $db->connection;
    }

    /** Prepares a statement, reconnecting and retrying once if the handle is dead. */
    private function prepareWithRetry(string $sql): mysqli_stmt {
        try {
            $stmt = $this->connection->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException($this->connection->error ?: 'prepare() returned false');
            }
            return $stmt;
        } catch (\Throwable $e) {
            $this->connectDb();
            $stmt = $this->connection->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException('Prepare failed after reconnect: ' . $this->connection->error);
            }
            return $stmt;
        }
    }

    /** Shared-secret check. Fails closed: no secret configured = denied. */
    public function verifySecret(): bool {
        if (!defined('THINGSPEAK_BRIDGE_SECRET') || THINGSPEAK_BRIDGE_SECRET === '') {
            return false;
        }
        $provided = $_GET['secret'] ?? '';
        return hash_equals(THINGSPEAK_BRIDGE_SECRET, (string) $provided);
    }

    /** Pull the single most recent ThingSpeak entry, store it if new, check alerts. */
    public function fetchAndSave(): array {
        $data = $this->httpGetJson($this->buildFeedsUrl(1));

        if (!$data || empty($data['feeds'])) {
            return ['success' => false, 'message' => 'No data returned from ThingSpeak.'];
        }

        $result = $this->saveFeedEntry($data['feeds'][0]);

        if (!empty($result['inserted'])) {
            $this->runAlerts($result);
        }
        unset($result['values']);
        return $result;
    }

    /** Pull several recent entries and backfill any not stored yet. Alerts are checked once, on the newest inserted entry. */
    public function getRecent(int $limit = 10): array {
        $limit = max(1, min($limit, 100));
        $data  = $this->httpGetJson($this->buildFeedsUrl($limit));

        if (!$data || empty($data['feeds'])) {
            return ['success' => false, 'message' => 'No data returned from ThingSpeak.'];
        }

        $inserted = 0;
        $latest   = null;
        $errors   = [];
        foreach ($data['feeds'] as $feed) {      // ThingSpeak returns oldest -> newest
            $result = $this->saveFeedEntry($feed);
            if (!empty($result['inserted'])) {
                $inserted++;
                $latest = $result;
            } elseif (empty($result['success'])) {
                $errors[] = $result['message'] ?? 'Unknown error.';
            }
        }

        if ($latest) $this->runAlerts($latest);

        // Diagnostics: where the data went and how fresh ThingSpeak's newest entry is
        $newest    = end($data['feeds']);
        $newestAt  = $newest['created_at'] ?? null;
        $ageMin    = $newestAt ? round((time() - strtotime($newestAt)) / 60, 1) : null;

        $out = [
            'success'               => true,
            'fetched'               => count($data['feeds']),
            'inserted'              => $inserted,
            'saved_to_sensor_id'    => $this->sensorId,
            'newest_entry_at_utc'   => $newestAt,
            'newest_entry_age_min'  => $ageMin,
        ];
        if ($errors) {
            $out['errors'] = array_values(array_unique($errors));
        }
        return $out;
    }

    // ══════════════════════════════════════════════════════════
    //  INTERNAL
    // ══════════════════════════════════════════════════════════

    private function buildFeedsUrl(int $results): string {
        return "https://api.thingspeak.com/channels/{$this->channelId}/feeds.json"
             . "?api_key={$this->readApiKey}&results={$results}";
    }

    private function runAlerts(array $result): void {
        $v = $result['values'];
        if ($this->alertModel === null) {
            $this->alertModel = new Alert_Model();
        }
        $this->alertModel->evaluateReading(
            $this->sensorId, $v['temperature'], $v['humidity'], $v['co2'], $v['food_level'], $result['reading_id']
        );
    }

    /**
     * Same rules as the old Ingest.php: a suspicious value is dropped (so it
     * can't trigger fake alerts) but the other values in the entry still save.
     */
    private function saveFeedEntry(array $feed): array {
        $entryId   = $feed['entry_id']   ?? null;
        $createdAt = $feed['created_at'] ?? null;

        if (!$entryId) {
            return ['success' => false, 'inserted' => false, 'message' => 'Feed entry has no entry_id.'];
        }
        $entryIdInt = (int) $entryId;

        // ThingSpeak sends UTC; the DB session runs on +08:00 (see Base_Model), so
        // convert to Asia/Manila before inserting, regardless of PHP's default timezone.
        try {
            $timestamp = $createdAt
                ? (new DateTime($createdAt))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s')
                : date('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $timestamp = date('Y-m-d H:i:s');
        }

        // ThingSpeak entry IDs restart at 1 if the channel is ever cleared, so an ID
        // alone is not unique. An entry is a duplicate only if ID *and* time match.
        if ($this->entryAlreadyStored($entryIdInt, $timestamp)) {
            return ['success' => true, 'inserted' => false, 'message' => 'Already stored.'];
        }

        $warnings = [];

        $temperature = $this->numericOrNull($feed[self::FIELD_TEMP] ?? null, 'temperature', $warnings);
        if ($temperature !== null && ($temperature < -10 || $temperature > 60)) {
            $warnings[] = "temperature ignored (suspected sensor fault: {$temperature})";
            $temperature = null;
        }

        $humidity = $this->numericOrNull($feed[self::FIELD_HUM] ?? null, 'humidity', $warnings);
        if ($humidity !== null && ($humidity <= 0 || $humidity > 100)) {
            $warnings[] = "humidity ignored (suspected sensor fault: {$humidity})";
            $humidity = null;
        }

        $co2 = $this->numericOrNull($feed[self::FIELD_CO2] ?? null, 'co2', $warnings);
        if ($co2 !== null && ($co2 < 300 || $co2 > 10000)) {
            $warnings[] = "co2 ignored (out of range 300-10000 ppm: {$co2})";
            $co2 = null;
        }

        $foodMax = defined('FOOD_MAX_G') ? FOOD_MAX_G : 2500;
        $food = $this->numericOrNull($feed[self::FIELD_FOOD] ?? null, 'food_level', $warnings);
        if ($food !== null && ($food < 0 || $food > $foodMax)) {
            $warnings[] = "food_level ignored (out of range 0-{$foodMax}: {$food})";
            $food = null;
        }

        if ($temperature === null && $humidity === null && $co2 === null && $food === null) {
            return [
                'success' => false, 'inserted' => false, 'entry_id' => $entryIdInt,
                'message' => 'No valid sensor values in this entry. ' . implode('; ', $warnings),
            ];
        }

        $stmt = $this->prepareWithRetry(
            'INSERT INTO hs_readings (sensor_id, temperature, humidity, co2, food_level, thingspeak_entry_id, timestamp)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param("iddddis", $this->sensorId, $temperature, $humidity, $co2, $food, $entryIdInt, $timestamp);

        if (!$stmt->execute()) {
            return ['success' => false, 'inserted' => false, 'message' => 'Database insert failed: ' . $stmt->error];
        }

        return [
            'success'    => true,
            'inserted'   => true,
            'reading_id' => (int) $this->connection->insert_id,
            'entry_id'   => $entryIdInt,
            'ts_epoch'   => $createdAt ? (int) strtotime($createdAt) : time(),
            'warnings'   => $warnings,
            'values'     => ['temperature' => $temperature, 'humidity' => $humidity, 'co2' => $co2, 'food_level' => $food],
        ];
    }

    /** Returns float, or null if missing / non-numeric (non-numeric adds a warning). */
    private function numericOrNull($raw, string $name, array &$warnings): ?float {
        if ($raw === null || $raw === '') return null;
        if (!is_numeric($raw)) {
            $warnings[] = "{$name} ignored (not numeric)";
            return null;
        }
        return (float) $raw;
    }

    private function entryAlreadyStored(int $entryId, string $timestamp): bool {
        $stmt = $this->prepareWithRetry(
            'SELECT 1 FROM hs_readings WHERE thingspeak_entry_id = ? AND timestamp = ? LIMIT 1'
        );
        $stmt->bind_param("is", $entryId, $timestamp);
        $stmt->execute();
        return (bool) $stmt->get_result()->fetch_assoc();
    }

    private function httpGetJson(string $url): ?array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'HiveSense-Bridge/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;
        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }
}
?>