<?php

namespace App\Console\Commands;

use App\Services\ScanIngestService;
use App\Support\AppLogger;
use App\Support\SseChangeSignal;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe {--once} {--debug}';
    protected $description = 'Subscribe to scan topic and ingest scans safely.';

    public function handle(ScanIngestService $scanIngestService, SseChangeSignal $sseChangeSignal): int
    {
        /*
         * WICHTIG:
         * MQTT-Topics sind exakt.
         * "api/v1/scan" und "/api/v1/scan" sind unterschiedliche Topics.
         * Deshalb wird das Topic hier NICHT normalisiert.
         */
        $topic = (string) env('MQTT_TOPIC_SCAN', '/api/v1/scan');

        $qos = max(0, min(2, (int) env('MQTT_QOS', 0)));
        $latWarn = (int) env('MQTT_LATENCY_WARN_MS', 3000);
        $maxPayloadBytes = (int) env('MQTT_MAX_PAYLOAD_BYTES', 4096);

        $baseClientId = (string) (env('MQTT_CLIENT_ID') ?: 'lokato-laravel-subscriber');
        $clientId = $baseClientId . '-' . getmypid();

        config(['mqtt-client.connections.default.client_id' => $clientId]);

        $host = (string) config('mqtt-client.connections.default.host', env('MQTT_HOST', '127.0.0.1'));
        $port = (int) config('mqtt-client.connections.default.port', env('MQTT_PORT', 1883));
        $username = env('MQTT_AUTH_USERNAME');

        $debugLogs = (bool) $this->option('debug')
            || filter_var(env('MQTT_SUBSCRIBE_DEBUG', false), FILTER_VALIDATE_BOOL);

        $this->warn('MQTT CONFIG: ' . json_encode([
                'host' => $host,
                'port' => $port,
                'client_id' => $clientId,
                'topic' => $topic,
                'qos' => $qos,
                'username_present' => !empty($username),
                'once' => (bool) $this->option('once'),
                'debug_logs' => $debugLogs,
                'max_payload_bytes' => $maxPayloadBytes,
            ], JSON_UNESCAPED_SLASHES));

        $this->logEvent('mqtt_subscribe_command_started', [
            'topic' => $topic,
            'qos' => $qos,
            'client_id' => $clientId,
            'host' => $host,
            'port' => $port,
            'username_present' => !empty($username),
            'once' => (bool) $this->option('once'),
            'debug_logs' => $debugLogs,
            'max_payload_bytes' => $maxPayloadBytes,
        ]);

        try {
            /** @var \PhpMqtt\Client\Contracts\MqttClient $mqtt */
            $mqtt = MQTT::connection();

            $this->info("MQTT connection initialized.");
            $this->info("Subscribing to {$topic} (QoS {$qos}) ...");

            $this->logEvent('mqtt_connection_initialized', [
                'topic' => $topic,
                'host' => $host,
                'port' => $port,
                'client_id' => $clientId,
            ]);
        } catch (Throwable $e) {
            $this->error('MQTT connection failed: ' . $e->getMessage());

            $this->logException('mqtt_connection_failed', $e, [
                'topic' => $topic,
                'host' => $host,
                'port' => $port,
                'client_id' => $clientId,
            ]);

            return self::FAILURE;
        }

        $mqtt->subscribe($topic, function (string $incomingTopic, string $message) use (
            $scanIngestService,
            $sseChangeSignal,
            $mqtt,
            $latWarn,
            $debugLogs,
            $maxPayloadBytes
        ) {
            $mqttReceivedAt = now();
            $len = strlen($message);

            $this->line("MQTT RECEIVED {$incomingTopic} (len={$len})");

            if ($debugLogs) {
                $this->line($message);
            }

            $this->logEvent('mqtt_message_received', [
                'topic' => $incomingTopic,
                'received_at' => $mqttReceivedAt->toIso8601String(),
                'payload_length' => $len,
                'payload_preview' => $debugLogs ? mb_substr($message, 0, 500) : null,
            ]);

            if ($len === 0) {
                $this->warn('Ignoring empty MQTT message.');

                $this->logEvent('mqtt_message_ignored', [
                    'reason' => 'empty_payload',
                    'topic' => $incomingTopic,
                ], 'warning');

                return;
            }

            if ($len > $maxPayloadBytes) {
                $this->warn("Ignoring oversized MQTT message ({$len} bytes, max {$maxPayloadBytes}).");

                $this->logEvent('mqtt_message_ignored', [
                    'reason' => 'oversized_payload',
                    'topic' => $incomingTopic,
                    'payload_length' => $len,
                    'max_payload_bytes' => $maxPayloadBytes,
                ], 'warning');

                return;
            }

            try {
                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                $this->warn('MQTT payload is not valid JSON: ' . $e->getMessage());

                $this->logException('mqtt_payload_json_decode_failed', $e, [
                    'topic' => $incomingTopic,
                    'raw_payload' => $debugLogs ? $message : mb_substr($message, 0, 120),
                ]);

                return;
            }

            if (!is_array($payload)) {
                $this->warn('MQTT payload is not a JSON object.');

                $this->logEvent('mqtt_message_ignored', [
                    'reason' => 'payload_not_object',
                    'topic' => $incomingTopic,
                    'payload_type' => gettype($payload),
                ], 'warning');

                return;
            }

            $deviceKey = trim((string) ($payload['device_key'] ?? ''));
            $trackerUid = trim((string) ($payload['tracker_uid'] ?? ''));
            $eventTimeRaw = array_key_exists('event_time', $payload)
                ? (string) $payload['event_time']
                : null;

            if (
                !$this->isValidId($deviceKey, 1, 64)
                || !$this->isValidId($trackerUid, 1, 64)
            ) {
                $this->warn('MQTT payload validation failed.');

                $this->logEvent('mqtt_payload_validation_failed', [
                    'topic' => $incomingTopic,
                    'reason' => 'invalid_required_fields',
                    'device_key_present' => $deviceKey !== '',
                    'tracker_uid_present' => $trackerUid !== '',
                    'event_time_present' => $eventTimeRaw !== null,
                    'device_key' => $debugLogs ? $deviceKey : null,
                    'tracker_uid' => $debugLogs ? $trackerUid : null,
                ], 'warning');

                return;
            }

            $eventTimeIso = null;
            $scannerSentAt = null;

            if ($eventTimeRaw !== null && trim($eventTimeRaw) !== '') {
                try {
                    $scannerSentAt = Carbon::parse($eventTimeRaw);
                    $eventTimeIso = $scannerSentAt->toIso8601String();
                } catch (Throwable $e) {
                    $this->warn('Invalid event_time; processing without scanner timestamp.');

                    $this->logException('mqtt_event_time_invalid', $e, [
                        'topic' => $incomingTopic,
                        'event_time' => $eventTimeRaw,
                    ]);
                }
            } else {
                $this->logEvent('mqtt_event_time_empty', [
                    'topic' => $incomingTopic,
                    'note' => 'Processing scan without event_time.',
                ], 'notice');
            }

            try {
                $deliveryLatency = $scannerSentAt
                    ? $mqttReceivedAt->diffInMilliseconds($scannerSentAt, false) * -1
                    : null;

                if ($deliveryLatency !== null && $deliveryLatency > $latWarn) {
                    $this->logEvent('mqtt_latency_warning', [
                        'mqtt_delivery_latency_ms' => $deliveryLatency,
                        'topic' => $incomingTopic,
                        'scanner_sent_at' => $eventTimeIso,
                        'mqtt_received_at' => $mqttReceivedAt->toIso8601String(),
                    ], 'warning');
                }

                $processingStart = microtime(true);

                $movement = $scanIngestService->ingestScan(
                    deviceKey: $deviceKey,
                    trackerUid: $trackerUid,
                    eventTimeIso: $eventTimeIso,
                    source: 'mqtt_scanner'
                );

                if ($movement) {
                    $sseChangeSignal->bump();
                    $processingDurationMs = (int) ((microtime(true) - $processingStart) * 1000);

                    $this->info("MQTT scan ingested. movement_id={$movement->id}");

                    $this->logEvent('mqtt_message_processed', [
                        'topic' => $incomingTopic,
                        'processing_duration_ms' => $processingDurationMs,
                        'total_app_latency_ms' => (int) now()->diffInMilliseconds($mqttReceivedAt),
                        'movement_id' => $movement->id,
                        'device_key' => $deviceKey,
                        'tracker_uid' => $trackerUid,
                        'event_time' => $eventTimeIso,
                    ]);

                    if ($this->option('once')) {
                        $this->info('Option --once used; interrupting MQTT loop.');

                        $this->logEvent('mqtt_once_option_interrupting_loop', [
                            'topic' => $incomingTopic,
                        ]);

                        $mqtt->interrupt();
                    }
                } else {
                    $this->warn('MQTT scan ignored: unknown device or child.');

                    $this->logEvent('mqtt_message_ignored', [
                        'reason' => 'unknown_device_or_child',
                        'topic' => $incomingTopic,
                        'device_key' => $deviceKey,
                        'tracker_uid' => $trackerUid,
                    ], 'notice');
                }
            } catch (Throwable $e) {
                $this->error('Failed to ingest MQTT scan: ' . $e->getMessage());

                $this->logException('mqtt_message_failed', $e, [
                    'topic' => $incomingTopic,
                    'device_key' => $deviceKey,
                    'tracker_uid' => $trackerUid,
                ]);
            }
        }, $qos);

        $this->info("Subscribed. Waiting for messages on topic: {$topic}");

        $this->logEvent('mqtt_subscribed', [
            'topic' => $topic,
            'qos' => $qos,
        ]);

        try {
            $mqtt->loop(true);

            $this->warn('MQTT loop finished.');

            $this->logEvent('mqtt_loop_finished', [
                'topic' => $topic,
            ], 'notice');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('MQTT subscribe or loop failed: ' . $e->getMessage());

            $this->logException('mqtt_subscribe_or_loop_failed', $e, [
                'topic' => $topic,
                'qos' => $qos,
            ]);

            return self::FAILURE;
        }
    }

    private function isValidId(string $value, int $min, int $max): bool
    {
        $len = strlen($value);

        if ($len < $min || $len > $max) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9_-]+$/', $value);
    }

    private function logEvent(string $event, array $context = [], string $level = 'info'): void
    {
        try {
            if (class_exists(AppLogger::class)) {
                AppLogger::event('mqtt', $event, $context, $level, true);
                return;
            }
        } catch (Throwable $e) {
            Log::channel('scan')->warning('AppLogger failed; falling back to Laravel Log.', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('scan')->log($level, $event, $context);
    }

    private function logException(string $event, Throwable $e, array $context = []): void
    {
        $context['error'] = $e->getMessage();
        $context['exception_class'] = get_class($e);

        try {
            if (class_exists(AppLogger::class)) {
                AppLogger::exception('mqtt', $event, $e, $context);
                return;
            }
        } catch (Throwable $loggerException) {
            Log::channel('scan')->warning('AppLogger exception logging failed; falling back to Laravel Log.', [
                'event' => $event,
                'logger_error' => $loggerException->getMessage(),
            ]);
        }

        Log::channel('scan')->error($event, $context);
    }
}
