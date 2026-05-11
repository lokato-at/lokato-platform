<?php

namespace App\Console\Commands;

use App\Services\ScanIngestService;
use App\Support\AppLogger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe {--once}';
    protected $description = 'Subscribe to scan topic and ingest scans safely.';

    public function handle(ScanIngestService $scanIngestService): int
    {
        $topic = (string) env('MQTT_TOPIC_SCAN', '/api/v1/scan');
        $normalizedTopic = '/'.ltrim($topic, '/');
        $qos = max(0, min(2, (int) env('MQTT_QOS', 0)));
        $latWarn = (int) env('MQTT_LATENCY_WARN_MS', 3000);
        $baseClientId = (string) (env('MQTT_CLIENT_ID') ?: 'lokato-laravel-subscriber');
        $username = env('MQTT_AUTH_USERNAME');
        $host = (string) env('MQTT_HOST', '127.0.0.1');
        $port = (int) env('MQTT_PORT', 1883);
        $debugLogs = filter_var(env('MQTT_SUBSCRIBE_DEBUG', false), FILTER_VALIDATE_BOOL);

        if ($topic !== $normalizedTopic) {
            AppLogger::event('mqtt', 'mqtt_topic_normalized', [
                'configured_topic' => $topic,
                'normalized_topic' => $normalizedTopic,
            ], 'notice', true);
        }

        config(['mqtt-client.connections.default.client_id' => $baseClientId]);

        AppLogger::event('mqtt', 'mqtt_subscribe_command_started', [
            'topic' => $normalizedTopic,
            'qos' => $qos,
            'client_id' => $baseClientId,
            'host' => $host,
            'port' => $port,
            'username_present' => !empty($username),
            'once' => (bool) $this->option('once'),
            'debug_logs' => $debugLogs,
        ], 'info', true);

        try {
            $mqtt = MQTT::connection();
            AppLogger::event('mqtt', 'mqtt_connection_initialized', [
                'topic' => $normalizedTopic,
                'host' => $host,
                'port' => $port,
                'client_id' => $baseClientId,
            ], 'info', true);
        } catch (Throwable $e) {
            AppLogger::exception('mqtt', 'mqtt_connection_failed', $e, [
                'topic' => $normalizedTopic,
                'host' => $host,
                'port' => $port,
                'client_id' => $baseClientId,
            ]);

            return self::FAILURE;
        }

        $messageHandler = function (string $incomingTopic, string $message) use ($scanIngestService, $mqtt, $latWarn, $debugLogs) {
            $mqttReceivedAt = now();

            AppLogger::event('mqtt', 'mqtt_message_received', [
                'topic' => $incomingTopic,
                'received_at' => $mqttReceivedAt->toIso8601String(),
                'payload_length' => strlen($message),
                'payload_preview' => $debugLogs ? mb_substr($message, 0, 500) : null,
            ], 'info', true);

            try {
                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                AppLogger::exception('mqtt', 'mqtt_payload_json_decode_failed', $e, [
                    'topic' => $incomingTopic,
                    'raw_payload' => $debugLogs ? $message : null,
                ]);

                return;
            }

            if (!is_array($payload)) {
                AppLogger::event('mqtt', 'mqtt_message_ignored', [
                    'reason' => 'payload_not_object',
                    'topic' => $incomingTopic,
                    'payload_type' => gettype($payload),
                ], 'warning', true);
                return;
            }

            $deviceKey = trim((string) ($payload['device_key'] ?? ''));
            $trackerUid = trim((string) ($payload['tracker_uid'] ?? ''));
            $eventTimeRaw = array_key_exists('event_time', $payload) ? (string) $payload['event_time'] : null;

            if ($deviceKey === '' || $trackerUid === '') {
                AppLogger::event('mqtt', 'mqtt_payload_validation_failed', [
                    'topic' => $incomingTopic,
                    'reason' => 'missing_required_fields',
                    'device_key_present' => $deviceKey !== '',
                    'tracker_uid_present' => $trackerUid !== '',
                    'event_time_present' => $eventTimeRaw !== null,
                ], 'warning', true);

                return;
            }

            $eventTimeIso = null;
            $scannerSentAt = null;

            if ($eventTimeRaw !== null && trim($eventTimeRaw) !== '') {
                try {
                    $scannerSentAt = Carbon::parse($eventTimeRaw);
                    $eventTimeIso = $scannerSentAt->toIso8601String();
                } catch (Throwable $e) {
                    AppLogger::exception('mqtt', 'mqtt_event_time_invalid', $e, [
                        'topic' => $incomingTopic,
                        'event_time' => $eventTimeRaw,
                    ]);
                }
            } else {
                AppLogger::event('mqtt', 'mqtt_event_time_empty', [
                    'topic' => $incomingTopic,
                    'note' => 'Processing scan without event_time (fallback to server-side timing).',
                ], 'notice', true);
            }

            try {
                $deliveryLatency = $scannerSentAt ? $mqttReceivedAt->diffInMilliseconds($scannerSentAt, false) * -1 : null;
                if ($deliveryLatency !== null && $deliveryLatency > $latWarn) {
                    AppLogger::event('mqtt', 'mqtt_latency_warning', [
                        'mqtt_delivery_latency_ms' => $deliveryLatency,
                        'topic' => $incomingTopic,
                        'scanner_sent_at' => $eventTimeIso,
                        'mqtt_received_at' => $mqttReceivedAt->toIso8601String(),
                    ], 'warning', true);
                }

                $processingStart = microtime(true);
                $movement = $scanIngestService->ingestScan(
                    deviceKey: $deviceKey,
                    trackerUid: $trackerUid,
                    eventTimeIso: $eventTimeIso,
                    source: 'mqtt_scanner'
                );

                AppLogger::event('mqtt', 'mqtt_message_processed', [
                    'topic' => $incomingTopic,
                    'processing_duration_ms' => (int) ((microtime(true) - $processingStart) * 1000),
                    'total_app_latency_ms' => (int) now()->diffInMilliseconds($mqttReceivedAt),
                    'movement_id' => $movement?->id,
                    'device_key' => $deviceKey,
                    'tracker_uid' => $trackerUid,
                    'event_time' => $eventTimeIso,
                ], 'info', true);

                if ($this->option('once')) {
                    AppLogger::event('mqtt', 'mqtt_once_option_interrupting_loop', ['topic' => $incomingTopic], 'info', true);
                    $mqtt->interrupt();
                }
            } catch (Throwable $e) {
                AppLogger::exception('mqtt', 'mqtt_message_failed', $e, [
                    'topic' => $incomingTopic,
                    'device_key' => $deviceKey,
                    'tracker_uid' => $trackerUid,
                ]);
            }
        };

        try {
            $mqtt->subscribe($normalizedTopic, $messageHandler, $qos);
            AppLogger::event('mqtt', 'mqtt_subscribed', [
                'topic' => $normalizedTopic,
                'qos' => $qos,
            ], 'info', true);

            $mqtt->loop(true);
            AppLogger::event('mqtt', 'mqtt_loop_finished', ['topic' => $normalizedTopic], 'notice', true);

            return self::SUCCESS;
        } catch (Throwable $e) {
            AppLogger::exception('mqtt', 'mqtt_subscribe_or_loop_failed', $e, [
                'topic' => $normalizedTopic,
                'qos' => $qos,
            ]);

            return self::FAILURE;
        }
    }
}
