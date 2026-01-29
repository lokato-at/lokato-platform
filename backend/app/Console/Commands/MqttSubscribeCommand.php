<?php

namespace App\Console\Commands;

use App\Services\ScanIngestService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe {--once} {--debug}';
    protected $description = 'Subscribe to scan topic and ingest scans safely.';

    public function handle(ScanIngestService $scanIngestService): int
    {
        $topic = (string) env('MQTT_TOPIC_SCAN', '/api/v1/scan');
        $qos = (int) env('MQTT_QOS', 0);

        // Safety limit: drop oversized payloads
        $maxPayloadBytes = (int) env('MQTT_MAX_PAYLOAD_BYTES', 4096);

        // Optional: eindeutige Client-ID pro Prozess (wichtig wenn mehrere Listener parallel laufen)
        // Zur Laufzeit überschreiben, bevor connection() gebaut wird:
        $baseClientId = (string) (env('MQTT_CLIENT_ID') ?: 'lokato-laravel-subscriber');
        config(['mqtt-client.connections.default.client_id' => $baseClientId . '-' . getmypid()]);

        $this->warn('MQTT CONFIG: ' . json_encode([
                'host' => config('mqtt-client.connections.default.host'),
                'port' => config('mqtt-client.connections.default.port'),
                'client_id' => config('mqtt-client.connections.default.client_id'),
                'topic' => $topic,
                'qos' => $qos,
                'max_payload_bytes' => $maxPayloadBytes,
            ], JSON_UNESCAPED_SLASHES));

        /** @var \PhpMqtt\Client\Contracts\MqttClient $mqtt */
        $mqtt = MQTT::connection(); // nutzt default_connection aus config/mqtt-client.php

        $self = $this;

        $self->info("Subscribing to {$topic} (QoS {$qos}) ...");

        $processedOne = false;

        $mqtt->subscribe($topic, function (string $incomingTopic, string $message) use (
            $scanIngestService,
            $self,
            $maxPayloadBytes,
            &$processedOne,
            $mqtt
        ) {
            $len = strlen($message);

            if ($self->option('debug')) {
                $self->line("MQTT RECEIVED {$incomingTopic} (len={$len}): {$message}");
            }

            // 1) size checks
            if ($len === 0) {
                Log::channel('scan')->warning('Ignoring empty MQTT message', ['topic' => $incomingTopic]);
                return;
            }
            if ($len > $maxPayloadBytes) {
                Log::channel('scan')->warning('Ignoring oversized MQTT message', [
                    'topic' => $incomingTopic,
                    'len' => $len,
                    'max' => $maxPayloadBytes,
                ]);
                return;
            }

            // 2) strict JSON
            try {
                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    Log::channel('scan')->warning('MQTT payload is not a JSON object', [
                        'topic' => $incomingTopic,
                        'type' => gettype($payload),
                    ]);
                    return;
                }
            } catch (\JsonException $e) {
                Log::channel('scan')->warning('MQTT scan message is not valid JSON', [
                    'topic' => $incomingTopic,
                    'len' => $len,
                    'error' => $e->getMessage(),
                    // nicht das ganze message loggen (außer debug)
                    'sample' => $self->option('debug') ? $message : substr($message, 0, 120),
                ]);
                return;
            }

            // 3) extract + validate
            $deviceKey  = isset($payload['device_key']) ? (string) $payload['device_key'] : '';
            $trackerUid = isset($payload['tracker_uid']) ? (string) $payload['tracker_uid'] : '';
            $eventTimeRaw = isset($payload['event_time']) ? (string) $payload['event_time'] : null;

            if (!$this->isValidId($deviceKey, 1, 64) || !$this->isValidId($trackerUid, 1, 64)) {
                Log::channel('scan')->warning('MQTT scan payload failed validation', [
                    'topic' => $incomingTopic,
                    'device_key_present' => $deviceKey !== '',
                    'tracker_uid_present' => $trackerUid !== '',
                ]);
                return;
            }

            // event_time: nur übernehmen wenn parsebar
            $eventTimeIso = null;
            if ($eventTimeRaw !== null && $eventTimeRaw !== '') {
                try {
                    $eventTimeIso = Carbon::parse($eventTimeRaw)->toIso8601String();
                } catch (Throwable $e) {
                    Log::channel('scan')->notice('Invalid event_time; using now()', [
                        'topic' => $incomingTopic,
                        'event_time' => $eventTimeRaw,
                    ]);
                }
            }

            // 4) ingest -> DB
            try {
                $movement = $scanIngestService->ingestScan(
                    deviceKey: $deviceKey,
                    trackerUid: $trackerUid,
                    eventTimeIso: $eventTimeIso,
                    source: 'mqtt_scanner'
                );

                if ($movement) {
                    Log::channel('scan')->info('MQTT scan ingested', [
                        'movement_id' => $movement->id,
                        'device_key' => $deviceKey,
                        'tracker_uid' => $trackerUid,
                        'event_time' => $eventTimeIso,
                    ]);

                    $processedOne = true;

                    if ($self->option('once')) {
                        $mqtt->interrupt(); // beendet loop in der nächsten Iteration
                    }
                } else {
                    // ingestScan loggt unknown device/tracker schon selbst
                    Log::channel('scan')->notice('MQTT scan ignored (unknown device or child)', [
                        'device_key' => $deviceKey,
                        'tracker_uid' => $trackerUid,
                    ]);
                }

            } catch (Throwable $e) {
                Log::channel('scan')->error('Failed to ingest MQTT scan', [
                    'topic' => $incomingTopic,
                    'error' => $e->getMessage(),
                ]);
            }
        }, $qos);

        // Blockierend
        $mqtt->loop(true);

        return self::SUCCESS;
    }

    private function isValidId(string $value, int $min, int $max): bool
    {
        $len = strlen($value);
        if ($len < $min || $len > $max) return false;

        // erlaubt: A-Z a-z 0-9 _ -
        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $value);
    }
}
