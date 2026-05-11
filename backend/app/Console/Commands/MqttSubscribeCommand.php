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
        $maxPayloadBytes = (int) env('MQTT_MAX_PAYLOAD_BYTES', 4096);

        // App-/Backend-Lokalzeit. In config/app.php sollte stehen:
        // 'timezone' => env('APP_TIMEZONE', 'Europe/Vienna'),
        $appTimezone = (string) config('app.timezone', env('APP_TIMEZONE', 'Europe/Vienna'));

        // Zeitzone, in der die Hardware event_time sendet, falls event_time KEINEN Offset enthält.
        // Wenn die Hardware UTC sendet, nichts ändern. Wenn sie lokale Zeit sendet, in .env setzen.
        $hardwareTimezone = (string) env('MQTT_HARDWARE_TIMEZONE', 'UTC');

        // Eindeutige Client-ID pro Prozess, wichtig wenn mehrere Listener parallel laufen.
        $baseClientId = (string) (env('MQTT_CLIENT_ID') ?: 'lokato-laravel-subscriber');
        config(['mqtt-client.connections.default.client_id' => $baseClientId . '-' . getmypid()]);

        $this->warn('MQTT CONFIG: ' . json_encode([
                'host' => config('mqtt-client.connections.default.host'),
                'port' => config('mqtt-client.connections.default.port'),
                'client_id' => config('mqtt-client.connections.default.client_id'),
                'topic' => $topic,
                'qos' => $qos,
                'max_payload_bytes' => $maxPayloadBytes,
                'app_timezone' => $appTimezone,
                'hardware_timezone' => $hardwareTimezone,
            ], JSON_UNESCAPED_SLASHES));

        /** @var \PhpMqtt\Client\Contracts\MqttClient $mqtt */
        $mqtt = MQTT::connection();

        $self = $this;
        $self->info("Subscribing to {$topic} (QoS {$qos}) ...");

        $mqtt->subscribe($topic, function (string $incomingTopic, string $message) use (
            $scanIngestService,
            $self,
            $maxPayloadBytes,
            $appTimezone,
            $hardwareTimezone,
            $mqtt
        ) {
            $len = strlen($message);

            if ($self->option('debug')) {
                $self->line("MQTT RECEIVED {$incomingTopic} (len={$len}): {$message}");
            }

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
                    'sample' => $self->option('debug') ? $message : substr($message, 0, 120),
                ]);
                return;
            }

            $deviceKey = isset($payload['device_key']) ? (string) $payload['device_key'] : '';
            $trackerUid = isset($payload['tracker_uid']) ? (string) $payload['tracker_uid'] : '';
            $eventTimeRaw = isset($payload['event_time']) ? trim((string) $payload['event_time']) : null;

            if (!$this->isValidId($deviceKey, 1, 64) || !$this->isValidId($trackerUid, 1, 64)) {
                Log::channel('scan')->warning('MQTT scan payload failed validation', [
                    'topic' => $incomingTopic,
                    'device_key_present' => $deviceKey !== '',
                    'tracker_uid_present' => $trackerUid !== '',
                ]);
                return;
            }

            // Wichtig:
            // - event_time von der Hardware wird zuerst in der Hardware-Zeitzone interpretiert.
            // - Danach wird sie in die App-/Backend-Lokalzeit konvertiert, z. B. Europe/Vienna.
            // - Wenn keine gültige Hardware-Zeit kommt, setzt das Backend jetzt() ebenfalls in App-Lokalzeit.
            $eventTimeIso = $this->resolveLocalEventTimeIso(
                eventTimeRaw: $eventTimeRaw,
                hardwareTimezone: $hardwareTimezone,
                appTimezone: $appTimezone,
                topic: $incomingTopic
            );

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
                        'event_time_local' => $eventTimeIso,
                        'app_timezone' => $appTimezone,
                    ]);

                    if ($self->option('once')) {
                        $mqtt->interrupt();
                    }
                } else {
                    Log::channel('scan')->notice('MQTT scan ignored (unknown device or child)', [
                        'device_key' => $deviceKey,
                        'tracker_uid' => $trackerUid,
                        'event_time_local' => $eventTimeIso,
                    ]);
                }
            } catch (Throwable $e) {
                Log::channel('scan')->error('Failed to ingest MQTT scan', [
                    'topic' => $incomingTopic,
                    'error' => $e->getMessage(),
                ]);
            }
        }, $qos);

        $mqtt->loop(true);

        return self::SUCCESS;
    }

    private function resolveLocalEventTimeIso(
        ?string $eventTimeRaw,
        string $hardwareTimezone,
        string $appTimezone,
        string $topic
    ): string {
        if ($eventTimeRaw === null || $eventTimeRaw === '') {
            return Carbon::now($appTimezone)->toIso8601String();
        }

        try {
            // Falls event_time bereits einen Offset/Zulu enthält, respektiert Carbon diesen Offset.
            // Falls kein Offset enthalten ist, wird $hardwareTimezone verwendet.
            $eventTime = $this->hasTimezoneOffset($eventTimeRaw)
                ? Carbon::parse($eventTimeRaw)
                : Carbon::parse($eventTimeRaw, $hardwareTimezone);

            return $eventTime->setTimezone($appTimezone)->toIso8601String();
        } catch (Throwable $e) {
            Log::channel('scan')->notice('Invalid event_time; using backend local now()', [
                'topic' => $topic,
                'event_time' => $eventTimeRaw,
                'hardware_timezone' => $hardwareTimezone,
                'app_timezone' => $appTimezone,
            ]);

            return Carbon::now($appTimezone)->toIso8601String();
        }
    }

    private function hasTimezoneOffset(string $value): bool
    {
        // Erkennt z. B. 2026-05-11T12:30:00Z, 2026-05-11T12:30:00+02:00, 2026-05-11 12:30:00-0500
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));
    }

    private function isValidId(string $value, int $min, int $max): bool
    {
        $len = strlen($value);
        if ($len < $min || $len > $max) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $value);
    }
}
